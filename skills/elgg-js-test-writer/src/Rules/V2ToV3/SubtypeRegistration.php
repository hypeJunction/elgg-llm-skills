<?php

declare(strict_types=1);

namespace ElggMigrate\Rules\V2ToV3;

use ElggMigrate\FileChange;
use ElggMigrate\Finding;
use ElggMigrate\MigrationRule;
use ElggMigrate\RuleAnalysis;
use ElggMigrate\RuleResult;
use PhpParser\Node;
use PhpParser\NodeFinder;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitor\CloningVisitor;
use PhpParser\NodeVisitorAbstract;
use PhpParser\ParserFactory;
use PhpParser\PrettyPrinter;

/**
 * Replaces add_subtype()/update_subtype() with elgg_set_entity_class().
 *
 * In Elgg 2.x:
 *   add_subtype('object', 'blog', BlogPost::class);
 *   update_subtype('object', 'blog', BlogPost::class);
 *
 * In Elgg 3.x, subtypes are strings (not DB IDs). Use:
 *   elgg_set_entity_class('object', 'blog', BlogPost::class);
 *
 * Also removes get_subtype_id() and get_subtype_from_id() calls.
 */
final class SubtypeRegistration implements MigrationRule
{
    public function getId(): string
    {
        return 'subtype-registration';
    }

    public function getDescription(): string
    {
        return 'Replace add_subtype()/update_subtype() with elgg_set_entity_class()';
    }

    public function canAutomate(): bool
    {
        return true;
    }

    public function analyze(string $pluginPath): RuleAnalysis
    {
        $findings = [];
        $targetFunctions = ['add_subtype', 'update_subtype', 'get_subtype_id', 'get_subtype_from_id', 'remove_subtype'];

        foreach ($this->findPhpFiles($pluginPath) as $file) {
            $relativePath = $this->relativePath($pluginPath, $file);
            $code = file_get_contents($file);
            if ($code === false) {
                continue;
            }

            foreach ($this->findCalls($code, $targetFunctions) as $call) {
                $findings[] = new Finding(
                    file: $relativePath,
                    line: $call['line'],
                    description: "{$call['function']}() needs migration",
                    code: $call['code'],
                );
            }
        }

        $applicable = count($findings) > 0;

        return new RuleAnalysis(
            ruleId: $this->getId(),
            applicable: $applicable,
            findings: $findings,
            summary: $applicable
                ? sprintf('Found %d subtype registration/lookup call(s) to migrate', count($findings))
                : 'No subtype registration calls found',
        );
    }

    public function apply(string $pluginPath): RuleResult
    {
        $targetFunctions = ['add_subtype', 'update_subtype', 'get_subtype_id', 'get_subtype_from_id', 'remove_subtype'];
        $changes = [];
        $warnings = [];

        foreach ($this->findPhpFiles($pluginPath) as $file) {
            $relativePath = $this->relativePath($pluginPath, $file);
            $code = file_get_contents($file);
            if ($code === false) {
                continue;
            }

            if (empty($this->findCalls($code, $targetFunctions))) {
                continue;
            }

            $result = $this->transformFile($code);

            if ($result['transformed']) {
                file_put_contents($file, $result['code']);
                $changes[] = new FileChange(
                    file: $relativePath,
                    type: 'modified',
                    description: 'Replaced subtype registration calls with elgg_set_entity_class()',
                );

                foreach ($result['warnings'] as $w) {
                    $warnings[] = "{$relativePath}: {$w}";
                }
            }
        }

        if (empty($changes)) {
            return new RuleResult(
                ruleId: $this->getId(),
                success: true,
                changes: [],
                warnings: ['No subtype registration calls found'],
            );
        }

        return new RuleResult(
            ruleId: $this->getId(),
            success: true,
            changes: $changes,
            warnings: $warnings,
        );
    }

    /**
     * @return array<array{function: string, line: int, code: string}>
     */
    private function findCalls(string $code, array $functionNames): array
    {
        $parser = (new ParserFactory())->createForHostVersion();

        try {
            $ast = $parser->parse($code);
        } catch (\Throwable) {
            return [];
        }

        if ($ast === null) {
            return [];
        }

        $finder = new NodeFinder();
        $calls = $finder->find($ast, function (Node $node) use ($functionNames) {
            return $node instanceof Node\Expr\FuncCall
                && $node->name instanceof Node\Name
                && in_array($node->name->toString(), $functionNames, true);
        });

        $results = [];
        $printer = new PrettyPrinter\Standard();

        foreach ($calls as $call) {
            /** @var Node\Expr\FuncCall $call */
            $results[] = [
                'function' => $call->name->toString(),
                'line' => $call->getLine(),
                'code' => $printer->prettyPrintExpr($call),
            ];
        }

        return $results;
    }

    /**
     * @return array{transformed: bool, code: string, warnings: array<string>}
     */
    private function transformFile(string $code): array
    {
        $parser = (new ParserFactory())->createForHostVersion();

        try {
            $oldAst = $parser->parse($code);
        } catch (\Throwable) {
            return ['transformed' => false, 'code' => $code, 'warnings' => []];
        }

        if ($oldAst === null) {
            return ['transformed' => false, 'code' => $code, 'warnings' => []];
        }
        $tokens = $parser->getTokens();

        $cloneTraverser = new NodeTraverser();
        $cloneTraverser->addVisitor(new CloningVisitor());
        $newAst = $cloneTraverser->traverse($oldAst);

        $warnings = [];

        $traverser = new NodeTraverser();
        $visitor = new class($warnings) extends NodeVisitorAbstract {
            private bool $changed = false;

            public function __construct(private array &$warnings) {}

            public function leaveNode(Node $node): int|Node|null
            {
                // Handle standalone expression statements (for REMOVE_NODE)
                if ($node instanceof Node\Stmt\Expression
                    && $node->expr instanceof Node\Expr\FuncCall
                    && $node->expr->name instanceof Node\Name
                ) {
                    $call = $node->expr;
                    $funcName = $call->name->toString();

                    if (in_array($funcName, ['add_subtype', 'update_subtype'], true) && count($call->args) === 2) {
                        $this->warnings[] = "{$funcName}() with 2 args (no class) was used to clear registration — removed. Verify this is no longer needed.";
                        $this->changed = true;
                        return NodeTraverser::REMOVE_NODE;
                    }

                    if (in_array($funcName, ['get_subtype_id', 'get_subtype_from_id', 'remove_subtype'], true)) {
                        $this->warnings[] = "{$funcName}() removed — subtypes are strings in 3.0, not integer IDs. Review usage and replace with the string subtype directly.";
                        $this->changed = true;
                        return NodeTraverser::REMOVE_NODE;
                    }
                }

                // Handle function calls anywhere (including inside if conditions, assignments, etc.)
                if ($node instanceof Node\Expr\FuncCall
                    && $node->name instanceof Node\Name
                ) {
                    $funcName = $node->name->toString();

                    // add_subtype/update_subtype with 3 args → elgg_set_entity_class
                    if (in_array($funcName, ['add_subtype', 'update_subtype'], true) && count($node->args) >= 3) {
                        $node->name = new Node\Name('elgg_set_entity_class');
                        $node->args = array_slice($node->args, 0, 3);
                        $this->changed = true;
                        return $node;
                    }
                }

                return null;
            }

            public function hasChanged(): bool
            {
                return $this->changed;
            }
        };

        $traverser->addVisitor($visitor);
        $newAst = $traverser->traverse($newAst);

        if (!$visitor->hasChanged()) {
            return ['transformed' => false, 'code' => $code, 'warnings' => $warnings];
        }

        $printer = new PrettyPrinter\Standard();
        $newCode = $printer->printFormatPreserving($newAst, $oldAst, $tokens);

        return ['transformed' => true, 'code' => $newCode, 'warnings' => $warnings];
    }

    /**
     * @return \Generator<string>
     */
    private function findPhpFiles(string $dir): \Generator
    {
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
        );

        foreach ($iterator as $file) {
            /** @var \SplFileInfo $file */
            if ($file->getExtension() === 'php') {
                yield $file->getPathname();
            }
        }
    }

    private function relativePath(string $base, string $path): string
    {
        $base = rtrim($base, '/') . '/';
        if (str_starts_with($path, $base)) {
            return substr($path, strlen($base));
        }
        return $path;
    }
}
