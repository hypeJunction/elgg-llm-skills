<?php

declare(strict_types=1);

namespace ElggMigrate\Rules\Shared;

use ElggMigrate\AbstractRule;
use ElggMigrate\FileChange;
use ElggMigrate\Finding;
use ElggMigrate\RuleAnalysis;
use ElggMigrate\RuleResult;
use PhpParser\Comment\Doc;
use PhpParser\Node;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitor\CloningVisitor;
use PhpParser\NodeVisitorAbstract;
use PhpParser\ParserFactory;
use PhpParser\PrettyPrinter;

/**
 * Attach PHPDoc blocks to functions, methods, and class properties that
 * don't already have one. This is a shared (version-agnostic) rule that
 * every manifest pulls in as a last step, so migrated plugins come out
 * with a consistent type-carrying docblock on every top-level declaration.
 *
 * What it does:
 * - Walks every .php file under the plugin tree.
 * - For each Function_, ClassMethod, and Property node without an
 *   existing doc comment, synthesises one from the PHP type hints
 *   (falling back to `mixed`) and attaches it.
 * - Uses nikic/php-parser's format-preserving printer so nothing else
 *   in the file is reformatted — only the newly inserted docblocks.
 *
 * What it deliberately does NOT do:
 * - Fill in summary/description text. It leaves the summary line blank
 *   so a human or LLM can fill it in later. The types are the valuable
 *   scaffolding; prose is judgment.
 * - Touch local variables. PHP docblocks on locals are nonstandard and
 *   the AST transformation isn't safe at that granularity.
 * - Replace existing docblocks. If a node already has one, it's left
 *   alone even if incomplete — we never clobber hand-written docs.
 * - Add docs to arrow functions, closures, or trait use statements.
 */
final class AddDocBlocks extends AbstractRule
{
    public function getId(): string
    {
        return 'add-docblocks';
    }

    public function getDescription(): string
    {
        return 'Add PHPDoc blocks to functions, methods, and class properties that lack them';
    }

    public function canAutomate(): bool
    {
        return true;
    }

    public function analyze(string $pluginPath): RuleAnalysis
    {
        $findings = [];
        $total = 0;

        foreach ($this->findPhpFiles($pluginPath) as $file) {
            $count = $this->countMissing($file);
            if ($count === 0) {
                continue;
            }
            $total += $count;
            $findings[] = new Finding(
                $this->relativePath($pluginPath, $file),
                0,
                "{$count} missing docblock(s)",
                '',
            );
        }

        return new RuleAnalysis(
            ruleId: $this->getId(),
            applicable: $total > 0,
            findings: $findings,
            summary: $total > 0
                ? "Found {$total} function(s)/method(s)/property(ies) without docblocks"
                : 'All functions, methods, and properties already have docblocks',
        );
    }

    public function apply(string $pluginPath): RuleResult
    {
        $changes = [];
        $warnings = [];

        foreach ($this->findPhpFiles($pluginPath) as $file) {
            $added = $this->addDocBlocksToFile($file);
            if ($added > 0) {
                $changes[] = new FileChange(
                    file: $this->relativePath($pluginPath, $file),
                    type: 'modified',
                    description: "Added {$added} docblock(s)",
                );
            }
        }

        return new RuleResult(
            ruleId: $this->getId(),
            success: true,
            changes: $changes,
            warnings: $warnings,
        );
    }

    /**
     * Count how many function/method/property nodes in a file are
     * missing a doc comment.
     */
    private function countMissing(string $file): int
    {
        $code = @file_get_contents($file);
        if ($code === false) {
            return 0;
        }
        $ast = $this->parse($code);
        if ($ast === null) {
            return 0;
        }

        $visitor = new class extends NodeVisitorAbstract {
            public int $count = 0;

            public function enterNode(Node $node): ?int
            {
                if ($this->isTarget($node) && $node->getDocComment() === null) {
                    $this->count++;
                }
                return null;
            }

            private function isTarget(Node $node): bool
            {
                return $node instanceof Node\Stmt\Function_
                    || $node instanceof Node\Stmt\ClassMethod
                    || $node instanceof Node\Stmt\Property;
            }
        };

        $traverser = new NodeTraverser();
        $traverser->addVisitor($visitor);
        $traverser->traverse($ast);

        return $visitor->count;
    }

    /**
     * Parse, decorate, and rewrite a file with newly added docblocks.
     * Returns the number of docblocks inserted.
     */
    private function addDocBlocksToFile(string $file): int
    {
        $code = @file_get_contents($file);
        if ($code === false) {
            return 0;
        }

        $parser = (new ParserFactory())->createForHostVersion();

        try {
            $oldStmts = $parser->parse($code);
        } catch (\Throwable) {
            return 0;
        }
        if ($oldStmts === null) {
            return 0;
        }
        $oldTokens = $parser->getTokens();

        // Clone the AST so the original (linked to $oldTokens) stays
        // untouched for the format-preserving printer's diff.
        $cloneTraverser = new NodeTraverser();
        $cloneTraverser->addVisitor(new CloningVisitor());
        $newStmts = $cloneTraverser->traverse($oldStmts);

        $visitor = new class($this) extends NodeVisitorAbstract {
            public int $added = 0;

            public function __construct(private AddDocBlocks $rule)
            {
            }

            public function enterNode(Node $node): ?int
            {
                if ($node->getDocComment() !== null) {
                    return null;
                }
                if ($node instanceof Node\Stmt\Function_ || $node instanceof Node\Stmt\ClassMethod) {
                    $doc = $this->rule->renderFunctionDoc($node);
                } elseif ($node instanceof Node\Stmt\Property) {
                    $doc = $this->rule->renderPropertyDoc($node);
                } else {
                    return null;
                }
                if ($doc === null) {
                    return null;
                }
                $node->setDocComment(new Doc($doc));
                $this->added++;
                return null;
            }
        };

        $addTraverser = new NodeTraverser();
        $addTraverser->addVisitor($visitor);
        $addTraverser->traverse($newStmts);

        if ($visitor->added === 0) {
            return 0;
        }

        $printer = new PrettyPrinter\Standard();
        $newCode = $printer->printFormatPreserving($newStmts, $oldStmts, $oldTokens);

        if ($newCode !== $code) {
            file_put_contents($file, $newCode);
        }

        return $visitor->added;
    }

    /**
     * Build a docblock for a function or method. Returns null if the
     * docblock would be empty (no params, no return, nothing worth
     * saying) — in that case we leave the node clean rather than
     * clutter it with an empty placeholder block.
     */
    public function renderFunctionDoc(Node\Stmt\Function_|Node\Stmt\ClassMethod $node): ?string
    {
        $lines = [];

        foreach ($node->params as $param) {
            if (!$param->var instanceof Node\Expr\Variable || !is_string($param->var->name)) {
                continue;
            }
            $type = $this->typeToString($param->type);
            if ($param->variadic) {
                $lines[] = " * @param {$type} ...\${$param->var->name}";
            } else {
                $lines[] = " * @param {$type} \${$param->var->name}";
            }
        }

        $returnType = $this->typeToString($node->returnType);
        $hasExplicitReturn = $this->hasExplicitReturn($node);
        if ($returnType !== 'mixed' || $hasExplicitReturn) {
            // Constructors don't need @return; it's always the instance.
            $isCtor = $node instanceof Node\Stmt\ClassMethod
                && strtolower($node->name->toString()) === '__construct';
            if (!$isCtor) {
                $lines[] = " * @return {$returnType}";
            }
        }

        if (empty($lines)) {
            return null;
        }

        return "/**\n" . implode("\n", $lines) . "\n */";
    }

    /**
     * Build a `@var` docblock for a class property declaration. Even
     * when the property has a PHP type hint, a matching `@var` line is
     * useful because older tooling (IDEs, static analyzers pinned to
     * older PHP) still reads it.
     */
    public function renderPropertyDoc(Node\Stmt\Property $node): ?string
    {
        $type = $this->typeToString($node->type);
        return "/** @var {$type} */";
    }

    /**
     * Flatten a PhpParser type node (Identifier, Name, Nullable, Union,
     * Intersection) back into a PHPDoc-friendly type string. Returns
     * 'mixed' for anything it can't resolve.
     */
    private function typeToString(?Node $type): string
    {
        if ($type === null) {
            return 'mixed';
        }
        if ($type instanceof Node\Identifier) {
            return $type->toString();
        }
        if ($type instanceof Node\Name) {
            return $type->toString();
        }
        if ($type instanceof Node\NullableType) {
            return '?' . $this->typeToString($type->type);
        }
        if ($type instanceof Node\UnionType) {
            return implode('|', array_map(fn($t) => $this->typeToString($t), $type->types));
        }
        if ($type instanceof Node\IntersectionType) {
            return implode('&', array_map(fn($t) => $this->typeToString($t), $type->types));
        }
        return 'mixed';
    }

    /**
     * True when the function body contains at least one return
     * statement (even `return;`). Used to decide whether to emit a
     * `@return mixed` for an untyped function.
     */
    private function hasExplicitReturn(Node\Stmt\Function_|Node\Stmt\ClassMethod $node): bool
    {
        if ($node->stmts === null) {
            return false;
        }
        $returns = $this->finder()->find($node->stmts, function (Node $n): bool {
            // Nested closures/anon classes have their own return scope
            // — ignore them when deciding about the outer function.
            return $n instanceof Node\Stmt\Return_;
        });
        return !empty($returns);
    }
}
