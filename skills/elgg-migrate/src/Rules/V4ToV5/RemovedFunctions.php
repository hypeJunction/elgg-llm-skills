<?php

declare(strict_types=1);

namespace ElggMigrate\Rules\V4ToV5;

use ElggMigrate\AbstractRule;
use ElggMigrate\FileChange;
use ElggMigrate\Finding;
use ElggMigrate\RuleAnalysis;
use ElggMigrate\RuleResult;
use PhpParser\Node;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitorAbstract;

/**
 * Handles procedural helper functions removed in Elgg 5.0 that have
 * exact 1:1 function renames.
 *
 * - current_page_url()   → elgg_get_current_url()
 * - get_default_access() → elgg_get_default_access()
 */
final class RemovedFunctions extends AbstractRule
{
    /**
     * Map of old function name → new function name.
     *
     * @var array<string, string>
     */
    public const MAP = [
        'current_page_url'  => 'elgg_get_current_url',
        'get_default_access' => 'elgg_get_default_access',
    ];

    public function getId(): string
    {
        return 'removed-functions-5x';
    }

    public function getDescription(): string
    {
        return 'Rename procedural helpers removed in 5.0 to their elgg_*() equivalents';
    }

    public function canAutomate(): bool
    {
        return true;
    }

    public function analyze(string $pluginPath): RuleAnalysis
    {
        $findings = [];
        $targetNames = array_keys(self::MAP);

        foreach ($this->findPhpFiles($pluginPath) as $file) {
            $relativePath = $this->relativePath($pluginPath, $file);
            $code = file_get_contents($file);
            if ($code === false) {
                continue;
            }

            $ast = $this->parse($code);
            if ($ast === null) {
                continue;
            }

            $calls = $this->findFunctionCalls($ast, $targetNames);
            $printer = $this->printer();

            foreach ($calls as $call) {
                $funcName = $call->name->toString();
                $replacement = self::MAP[$funcName];

                $findings[] = new Finding(
                    file: $relativePath,
                    line: $call->getLine(),
                    description: "{$funcName}() removed in 5.0 — rename to {$replacement}()",
                    code: $printer->prettyPrintExpr($call),
                );
            }
        }

        $applicable = count($findings) > 0;

        return new RuleAnalysis(
            ruleId: $this->getId(),
            applicable: $applicable,
            findings: $findings,
            summary: $applicable
                ? sprintf('Found %d removed function call(s) to rename', count($findings))
                : 'No removed function calls found',
        );
    }

    public function apply(string $pluginPath): RuleResult
    {
        $changes = [];
        $warnings = [];
        $targetNames = array_keys(self::MAP);

        foreach ($this->findPhpFiles($pluginPath) as $file) {
            $relativePath = $this->relativePath($pluginPath, $file);
            $code = file_get_contents($file);
            if ($code === false) {
                continue;
            }

            $ast = $this->parse($code);
            if ($ast === null) {
                continue;
            }

            $calls = $this->findFunctionCalls($ast, $targetNames);
            if (empty($calls)) {
                continue;
            }

            $result = $this->transformFile($code);

            if ($result['transformed']) {
                file_put_contents($file, $result['code']);
                $changes[] = new FileChange(
                    file: $relativePath,
                    type: 'modified',
                    description: 'Renamed removed function calls to 5.x equivalents',
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

    // -------------------------------------------------------------------------

    /**
     * @return array{transformed: bool, code: string, warnings: array<string>}
     */
    private function transformFile(string $originalCode): array
    {
        $warnings = [];

        $parsed = $this->parsePreserving($originalCode);
        if ($parsed === null) {
            return ['transformed' => false, 'code' => $originalCode, 'warnings' => $warnings];
        }

        $traverser = new NodeTraverser();
        $visitor = new class($warnings) extends NodeVisitorAbstract {
            private bool $changed = false;

            public function __construct(private array &$warnings) {}

            public function leaveNode(Node $node): int|Node|null
            {
                if (
                    $node instanceof Node\Expr\FuncCall
                    && $node->name instanceof Node\Name
                    && isset(RemovedFunctions::MAP[$node->name->toString()])
                ) {
                    $oldName = $node->name->toString();
                    $newName = RemovedFunctions::MAP[$oldName];
                    $this->warnings[] = "{$oldName}() → {$newName}()";
                    $this->changed = true;

                    $node->name = new Node\Name($newName);
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
}
