<?php

declare(strict_types=1);

namespace ElggMigrate\Rules\V2ToV3;

use ElggMigrate\AbstractRule;
use ElggMigrate\FileChange;
use ElggMigrate\Finding;
use ElggMigrate\RuleAnalysis;
use ElggMigrate\RuleResult;
use PhpParser\Node;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitorAbstract;

/**
 * Replaces foreach-by-reference on hook return values.
 *
 * In Elgg 3.x, hook return values like MenuItems implement Traversable but
 * cannot be iterated by reference. Code like:
 *
 *     foreach ($return as &$item) { ... }
 *
 * must be rewritten to:
 *
 *     $items = $return instanceof \Traversable ? iterator_to_array($return) : (array) $return;
 *     foreach ($items as $key => $item) { ... }
 *
 * This rule detects foreach loops that iterate by reference over variables
 * commonly used as hook return values ($return, $returnvalue, $items, $menu).
 */
final class ForeachByReferenceOnIterator extends AbstractRule
{
    /**
     * Variable names commonly used as hook return values that may be iterators.
     */
    private const HOOK_RETURN_VARS = [
        'return',
        'returnvalue',
        'result',
        'items',
        'menu',
        'value',
    ];

    public function getId(): string
    {
        return 'foreach-by-reference-on-iterator';
    }

    public function getDescription(): string
    {
        return 'Replace foreach-by-reference on hook return values (iterators cannot be iterated by reference in PHP 7.4+)';
    }

    public function canAutomate(): bool
    {
        return true;
    }

    public function analyze(string $pluginPath): RuleAnalysis
    {
        $findings = [];

        foreach ($this->findPhpFiles($pluginPath) as $file) {
            $relativePath = $this->relativePath($pluginPath, $file);
            $code = file_get_contents($file);
            if ($code === false) continue;

            $ast = $this->parse($code);
            if ($ast === null) continue;

            $foreachNodes = $this->finder()->find(
                $ast,
                fn(Node $node) => $node instanceof Node\Stmt\Foreach_ && $node->byRef
            );

            foreach ($foreachNodes as $foreach) {
                /** @var Node\Stmt\Foreach_ $foreach */
                if ($this->isHookReturnVar($foreach->expr)) {
                    $varName = $this->getVarName($foreach->expr);
                    $findings[] = new Finding(
                        file: $relativePath,
                        line: $foreach->getLine(),
                        description: "foreach by reference on \${$varName} (may be an iterator in Elgg 3.x)",
                        code: "foreach (\${$varName} as &...)",
                    );
                }
            }
        }

        $applicable = count($findings) > 0;

        return new RuleAnalysis(
            ruleId: $this->getId(),
            applicable: $applicable,
            findings: $findings,
            summary: $applicable
                ? sprintf('Found %d foreach-by-reference on potential iterator(s)', count($findings))
                : 'No foreach-by-reference on iterators found',
        );
    }

    public function apply(string $pluginPath): RuleResult
    {
        $changes = [];
        $warnings = [];

        foreach ($this->findPhpFiles($pluginPath) as $file) {
            $relativePath = $this->relativePath($pluginPath, $file);
            $code = file_get_contents($file);
            if ($code === false) continue;

            $result = $this->transformFile($code);

            if ($result['transformed']) {
                file_put_contents($file, $result['code']);
                $changes[] = new FileChange(
                    file: $relativePath,
                    type: 'modified',
                    description: 'Replaced foreach-by-reference with iterator-safe pattern',
                );
            }

            foreach ($result['warnings'] as $w) {
                $warnings[] = "{$relativePath}: {$w}";
            }
        }

        return new RuleResult(
            ruleId: $this->getId(),
            success: true,
            changes: $changes,
            warnings: $warnings,
        );
    }

    private function isHookReturnVar(Node\Expr $expr): bool
    {
        if ($expr instanceof Node\Expr\Variable && is_string($expr->name)) {
            return in_array($expr->name, self::HOOK_RETURN_VARS, true);
        }
        return false;
    }

    private function getVarName(Node\Expr $expr): string
    {
        if ($expr instanceof Node\Expr\Variable && is_string($expr->name)) {
            return $expr->name;
        }
        return '?';
    }

    /**
     * @return array{transformed: bool, code: string, warnings: array<string>}
     */
    private function transformFile(string $originalCode): array
    {
        $warnings = [];
        $rule = $this;

        $parsed = $this->parsePreserving($originalCode);
        if ($parsed === null) {
            return ['transformed' => false, 'code' => $originalCode, 'warnings' => $warnings];
        }

        $traverser = new NodeTraverser();
        $visitor = new class($rule, $warnings) extends NodeVisitorAbstract {
            private bool $changed = false;
            private int $counter = 0;

            public function __construct(
                private readonly ForeachByReferenceOnIterator $rule,
                private array &$warnings,
            ) {}

            public function leaveNode(Node $node): int|Node|array|null
            {
                if (!$node instanceof Node\Stmt\Foreach_) return null;
                if (!$node->byRef) return null;
                if (!$this->rule->isHookReturnVarPublic($node->expr)) return null;

                $this->changed = true;

                $originalVar = $node->expr;
                $originalVarName = $this->rule->getVarNamePublic($originalVar);

                // Generate a safe temp var name
                $safeVarName = $originalVarName === 'items' ? 'itemsArr' : 'items';
                $this->counter++;
                if ($this->counter > 1) {
                    $safeVarName .= $this->counter;
                }

                // Build: $items = $return instanceof \Traversable ? iterator_to_array($return) : (array) $return;
                $assignStmt = new Node\Stmt\Expression(
                    new Node\Expr\Assign(
                        new Node\Expr\Variable($safeVarName),
                        new Node\Expr\Ternary(
                            new Node\Expr\Instanceof_(
                                $originalVar,
                                new Node\Name\FullyQualified('Traversable')
                            ),
                            new Node\Expr\FuncCall(
                                new Node\Name('iterator_to_array'),
                                [new Node\Arg($originalVar)]
                            ),
                            new Node\Expr\Cast\Array_($originalVar)
                        )
                    )
                );

                // Rewrite foreach: remove byRef, change expr to $safeVar, add $key
                $newForeach = clone $node;
                $newForeach->byRef = false;
                $newForeach->expr = new Node\Expr\Variable($safeVarName);

                // If there's no key variable, add one
                if ($newForeach->keyVar === null) {
                    $newForeach->keyVar = new Node\Expr\Variable('key');
                }

                // Replace &$item = null with unset($items[$key]) pattern in stmts
                // This is best-effort: we handle the common case of $item = null
                $newStmts = $this->rewriteNullAssignments(
                    $newForeach->stmts,
                    $newForeach->valueVar,
                    $safeVarName,
                    $newForeach->keyVar,
                );
                $newForeach->stmts = $newStmts;

                return [$assignStmt, $newForeach];
            }

            /**
             * Replace `$item = null` with `unset($items[$key])` in foreach body.
             */
            private function rewriteNullAssignments(
                array $stmts,
                Node\Expr $valueVar,
                string $arrayVarName,
                Node\Expr $keyVar,
            ): array {
                $result = [];
                foreach ($stmts as $stmt) {
                    if (
                        $stmt instanceof Node\Stmt\Expression
                        && $stmt->expr instanceof Node\Expr\Assign
                        && $this->isSameVar($stmt->expr->var, $valueVar)
                        && $stmt->expr->expr instanceof Node\Expr\ConstFetch
                        && strtolower($stmt->expr->expr->name->toString()) === 'null'
                    ) {
                        // Replace $item = null with unset($items[$key])
                        $result[] = new Node\Stmt\Expression(
                            new Node\Expr\FuncCall(
                                new Node\Name('unset'),
                                [new Node\Arg(
                                    new Node\Expr\ArrayDimFetch(
                                        new Node\Expr\Variable($arrayVarName),
                                        $keyVar,
                                    )
                                )]
                            )
                        );
                    } else {
                        $result[] = $stmt;
                    }
                }
                return $result;
            }

            private function isSameVar(Node\Expr $a, Node\Expr $b): bool
            {
                if (
                    $a instanceof Node\Expr\Variable && $b instanceof Node\Expr\Variable
                    && is_string($a->name) && is_string($b->name)
                ) {
                    return $a->name === $b->name;
                }
                return false;
            }

            public function hasChanged(): bool
            {
                return $this->changed;
            }
        };

        $traverser->addVisitor($visitor);
        $parsed['new'] = $traverser->traverse($parsed['new']);

        if (!$visitor->hasChanged()) {
            return ['transformed' => false, 'code' => $originalCode, 'warnings' => $warnings];
        }

        return [
            'transformed' => true,
            'code' => $this->printPreserving($parsed['new'], $parsed['old'], $parsed['tokens']),
            'warnings' => $warnings,
        ];
    }

    /** Public wrapper for anonymous class access. */
    public function isHookReturnVarPublic(Node\Expr $expr): bool
    {
        return $this->isHookReturnVar($expr);
    }

    /** Public wrapper for anonymous class access. */
    public function getVarNamePublic(Node\Expr $expr): string
    {
        return $this->getVarName($expr);
    }
}
