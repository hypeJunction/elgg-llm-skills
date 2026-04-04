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
use PhpParser\NodeVisitorAbstract;
use PhpParser\ParserFactory;
use PhpParser\PrettyPrinter;

/**
 * Replaces deprecated elgg_get_entities_from_* and elgg_list_entities_from_*
 * with the unified elgg_get_entities() / elgg_list_entities().
 *
 * In Elgg 2.x:
 *   elgg_get_entities_from_metadata([...])
 *   elgg_get_entities_from_relationship([...])
 *   elgg_list_entities_from_metadata([...])
 *
 * In Elgg 3.x, these are deprecated (removed in 4.0). The unified functions
 * accept all options directly:
 *   elgg_get_entities([..., 'metadata_name' => ..., 'metadata_value' => ...])
 *
 * This rule renames the function calls. The options array is already compatible
 * since the unified functions accept the same keys.
 */
final class DeprecatedEntityQueries implements MigrationRule
{
    /**
     * Map of deprecated function → replacement function.
     */
    public const RENAMES = [
        'elgg_get_entities_from_metadata' => 'elgg_get_entities',
        'elgg_get_entities_from_relationship' => 'elgg_get_entities',
        'elgg_get_entities_from_private_settings' => 'elgg_get_entities',
        'elgg_get_entities_from_access_id' => 'elgg_get_entities',
        'elgg_get_entities_from_annotations' => 'elgg_get_entities',
        'elgg_list_entities_from_metadata' => 'elgg_list_entities',
        'elgg_list_entities_from_relationship' => 'elgg_list_entities',
        'elgg_list_entities_from_private_settings' => 'elgg_list_entities',
        'elgg_list_entities_from_access_id' => 'elgg_list_entities',
        'elgg_list_entities_from_annotations' => 'elgg_list_entities',
        'elgg_list_entities_from_annotation_calculation' => 'elgg_list_entities',
    ];

    public function getId(): string
    {
        return 'deprecated-entity-queries';
    }

    public function getDescription(): string
    {
        return 'Replace elgg_get_entities_from_*/elgg_list_entities_from_* with unified functions';
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
            if ($code === false) {
                continue;
            }

            foreach ($this->findDeprecatedCalls($code) as $call) {
                $replacement = self::RENAMES[$call['function']];
                $findings[] = new Finding(
                    file: $relativePath,
                    line: $call['line'],
                    description: "{$call['function']}() → {$replacement}()",
                    code: $call['code'],
                );
            }

            // Also find string references (used as callbacks, e.g. in ElggBatch)
            foreach ($this->findStringReferences($code) as $ref) {
                $findings[] = new Finding(
                    file: $relativePath,
                    line: $ref['line'],
                    description: "String reference '{$ref['function']}' used as callback — must be renamed",
                    code: $ref['code'],
                );
            }
        }

        $applicable = count($findings) > 0;

        return new RuleAnalysis(
            ruleId: $this->getId(),
            applicable: $applicable,
            findings: $findings,
            summary: $applicable
                ? sprintf('Found %d deprecated entity query call(s)/reference(s)', count($findings))
                : 'No deprecated entity query calls found',
        );
    }

    public function apply(string $pluginPath): RuleResult
    {
        $changes = [];
        $warnings = [];

        foreach ($this->findPhpFiles($pluginPath) as $file) {
            $relativePath = $this->relativePath($pluginPath, $file);
            $code = file_get_contents($file);
            if ($code === false) {
                continue;
            }

            $hasCalls = !empty($this->findDeprecatedCalls($code));
            $hasRefs = !empty($this->findStringReferences($code));

            if (!$hasCalls && !$hasRefs) {
                continue;
            }

            $result = $this->transformFile($code);

            if ($result['transformed']) {
                file_put_contents($file, $result['code']);
                $changes[] = new FileChange(
                    file: $relativePath,
                    type: 'modified',
                    description: 'Renamed deprecated entity query functions to unified equivalents',
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
                warnings: ['No deprecated entity query calls found'],
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
    private function findDeprecatedCalls(string $code): array
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

        $deprecatedNames = array_keys(self::RENAMES);
        $finder = new NodeFinder();
        $calls = $finder->find($ast, function (Node $node) use ($deprecatedNames) {
            return $node instanceof Node\Expr\FuncCall
                && $node->name instanceof Node\Name
                && in_array($node->name->toString(), $deprecatedNames, true);
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
     * Find string literals containing deprecated function names (used as callbacks).
     *
     * @return array<array{function: string, line: int, code: string}>
     */
    private function findStringReferences(string $code): array
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

        $deprecatedNames = array_keys(self::RENAMES);
        $finder = new NodeFinder();
        $strings = $finder->find($ast, function (Node $node) use ($deprecatedNames) {
            return $node instanceof Node\Scalar\String_
                && in_array($node->value, $deprecatedNames, true);
        });

        $results = [];

        foreach ($strings as $str) {
            /** @var Node\Scalar\String_ $str */
            $results[] = [
                'function' => $str->value,
                'line' => $str->getLine(),
                'code' => "'{$str->value}'",
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
            $ast = $parser->parse($code);
        } catch (\Throwable) {
            return ['transformed' => false, 'code' => $code, 'warnings' => []];
        }

        if ($ast === null) {
            return ['transformed' => false, 'code' => $code, 'warnings' => []];
        }

        $warnings = [];

        $traverser = new NodeTraverser();
        $visitor = new class($warnings) extends NodeVisitorAbstract {
            private bool $changed = false;
            private const RENAMES = DeprecatedEntityQueries::RENAMES;

            public function __construct(private array &$warnings) {}

            public function leaveNode(Node $node): ?Node
            {
                // Rename direct function calls
                if ($node instanceof Node\Expr\FuncCall
                    && $node->name instanceof Node\Name
                    && isset(self::RENAMES[$node->name->toString()])
                ) {
                    $oldName = $node->name->toString();
                    $newName = self::RENAMES[$oldName];
                    $node->name = new Node\Name($newName);
                    $this->changed = true;

                    if (str_contains($oldName, 'annotation_calculation')) {
                        $this->warnings[] = "Renamed {$oldName} → {$newName}. Note: annotation calculation options may need adjustment for 3.x.";
                    }

                    return $node;
                }

                // Rename string references (used as callbacks in ElggBatch, etc.)
                if ($node instanceof Node\Scalar\String_
                    && isset(self::RENAMES[$node->value])
                ) {
                    $oldName = $node->value;
                    $node->value = self::RENAMES[$oldName];
                    $this->changed = true;
                    $this->warnings[] = "Renamed string callback '{$oldName}' → '{$node->value}'. Verify the callback context still works.";
                    return $node;
                }

                return null;
            }

            public function hasChanged(): bool
            {
                return $this->changed;
            }
        };

        $traverser->addVisitor($visitor);
        $newAst = $traverser->traverse($ast);

        if (!$visitor->hasChanged()) {
            return ['transformed' => false, 'code' => $code, 'warnings' => $warnings];
        }

        $printer = new PrettyPrinter\Standard();
        $newCode = $printer->prettyPrintFile($newAst);

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
