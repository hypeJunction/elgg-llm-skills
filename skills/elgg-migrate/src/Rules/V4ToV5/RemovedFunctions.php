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
 * Handles procedural helper functions removed in Elgg 5.0.
 *
 * Two categories:
 * - 'rename': function has an exact 1:1 replacement → auto-renamed
 * - 'warn':   function removed without a 1:1 swap, refactor required → warn only
 *
 * Warn-only entries cover functions that were only deprecated (not removed) in 4.x
 * but were dropped from deprecated-4.x.php in 5.0 — a latent 5.x landmine that
 * needs to be flagged at the 4→5 boundary. See bd elgg-migrate-5h0u4.
 */
final class RemovedFunctions extends AbstractRule
{
    /**
     * Map of old function name → entry.
     *
     * Entry shape:
     *   ['action' => 'rename', 'replacement' => 'new_fn_name']
     *   ['action' => 'warn',   'note' => 'human-readable refactor hint']
     *
     * @var array<string, array{action: string, replacement?: string, note?: string}>
     */
    public const MAP = [
        // 1:1 renames (auto-applied)
        'current_page_url' => ['action' => 'rename', 'replacement' => 'elgg_get_current_url'],
        'get_default_access' => ['action' => 'rename', 'replacement' => 'elgg_get_default_access'],

        // Warn-only: hard-removed in 5.0 (these were deprecated-only in 4.x; the
        // deprecation shim is dropped in 5.0, causing activation fatals).
        'add_translation' => [
            'action' => 'warn',
            'note' => "Removed in 5.0 (was deprecated-only in 4.3). Rewrite languages/<lang>.php to 'return [\"key\" => \"value\", ...];' instead of calling add_translation(\$code, [...]).",
        ],
        'forward' => [
            'action' => 'warn',
            'note' => 'Removed in 5.0 (was deprecated-only in 4.0). Use elgg_redirect_response() or throw \\Elgg\\Exceptions\\HttpException.',
        ],
        'elgg_register_entity_type' => [
            'action' => 'warn',
            'note' => "Removed in 5.0 (was deprecated-only in 4.1). Use the 'entities' key in elgg-plugin.php.",
        ],
        'elgg_register_admin_menu_item' => [
            'action' => 'warn',
            'note' => "Removed in 4.0 without deprecation (still bites at the 4→5 boundary if not previously fixed). Use a declarative 'menus.page.<plugin_id>' block in elgg-plugin.php.",
        ],
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
                $entry = self::MAP[$funcName];

                if ($entry['action'] === 'rename') {
                    $description = "{$funcName}() removed in 5.0 — rename to {$entry['replacement']}()";
                } else {
                    $description = "{$funcName}() removed in 5.0: {$entry['note']}";
                }

                $findings[] = new Finding(
                    file: $relativePath,
                    line: $call->getLine(),
                    description: $description,
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

            // Emit warn-only findings up front (they don't transform but must surface)
            foreach ($calls as $call) {
                $funcName = $call->name->toString();
                $entry = self::MAP[$funcName];

                if ($entry['action'] === 'warn') {
                    $warnings[] = "{$relativePath}:{$call->getLine()} — {$funcName}() removed in 5.0: {$entry['note']}";
                }
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
                    $entry = RemovedFunctions::MAP[$oldName];

                    // Only auto-rename entries; warn-only entries are surfaced in apply().
                    if ($entry['action'] !== 'rename') {
                        return null;
                    }

                    $newName = $entry['replacement'];
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
