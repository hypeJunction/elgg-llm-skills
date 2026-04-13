<?php

declare(strict_types=1);

namespace ElggMigrate\Rules\V3ToV4;

use ElggMigrate\AbstractRule;
use ElggMigrate\FileChange;
use ElggMigrate\Finding;
use ElggMigrate\RuleAnalysis;
use ElggMigrate\RuleResult;
use PhpParser\Node;

/**
 * Renames hooks and events deprecated in 3.x and removed/changed in 4.0.
 *
 * Scans for elgg_register_plugin_hook_handler() and elgg_register_event_handler()
 * calls with known deprecated hook/event+type combinations.
 *
 * Also scans elgg-plugin.php arrays for the same patterns in 'hooks' and 'events' keys.
 */
final class HookEventRenames extends AbstractRule
{
    /**
     * Map of "hook_or_event:type" → replacement info.
     *
     * Format: 'old_name:old_type' => ['new_name' => ..., 'new_type' => ..., 'note' => ...]
     * If new_name/new_type are null, the handler should be removed.
     */
    public const HOOK_MAP = [
        // Profile field hooks
        'profile:fields:group' => ['new_name' => 'fields', 'new_type' => 'group:group', 'note' => "'profile:fields','group' → 'fields','group:group'"],
        'profile:fields:user' => ['new_name' => 'fields', 'new_type' => 'user:user', 'note' => "'profile:fields','user' → 'fields','user:user'"],

        // Filter tabs
        'filter_tabs:*' => ['new_name' => null, 'new_type' => null, 'note' => "Use 'register','menu:filter:<filter_id>' hook instead"],

        // Output hooks
        'output:ajax' => ['new_name' => null, 'new_type' => null, 'note' => "Use 'ajax_response' hook instead"],

        // User settings
        'usersettings:plugin' => ['new_name' => 'plugin_setting', 'new_type' => null, 'note' => "'usersettings','plugin' → 'plugin_setting','<entity_type>'"],

        // Search format
        'search:format:entity' => ['new_name' => null, 'new_type' => null, 'note' => 'Removed in 4.0 — search formatting moved to views'],
    ];

    public const EVENT_MAP = [
        // River events renamed
        'created:river' => ['new_name' => 'create:after', 'new_type' => 'river', 'note' => "'created','river' → 'create:after','river'"],
        'creating:river' => ['new_name' => 'create:before', 'new_type' => 'river', 'note' => "'creating','river' → 'create:before','river'"],
    ];

    public function getId(): string
    {
        return 'hook-event-renames';
    }

    public function getDescription(): string
    {
        return 'Rename hooks and events deprecated in 3.x, changed in 4.0';
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

            // Find hook registrations
            $hookCalls = $this->findFunctionCalls($ast, ['elgg_register_plugin_hook_handler']);
            foreach ($hookCalls as $call) {
                $finding = $this->checkHookCall($call, $relativePath);
                if ($finding) $findings[] = $finding;
            }

            // Find event registrations
            $eventCalls = $this->findFunctionCalls($ast, ['elgg_register_event_handler']);
            foreach ($eventCalls as $call) {
                $finding = $this->checkEventCall($call, $relativePath);
                if ($finding) $findings[] = $finding;
            }

            // Check elgg-plugin.php array keys
            if (str_ends_with($file, 'elgg-plugin.php')) {
                $arrayFindings = $this->checkPluginPhpArray($code, $relativePath);
                $findings = array_merge($findings, $arrayFindings);
            }
        }

        $applicable = count($findings) > 0;

        return new RuleAnalysis(
            ruleId: $this->getId(),
            applicable: $applicable,
            findings: $findings,
            summary: $applicable
                ? sprintf('Found %d deprecated hook/event registration(s)', count($findings))
                : 'No deprecated hook/event registrations found',
        );
    }

    public function apply(string $pluginPath): RuleResult
    {
        // Warn-only for most — the replacements need context
        // But we can auto-fix the simple event renames in elgg-plugin.php
        $changes = [];
        $warnings = [];

        foreach ($this->findPhpFiles($pluginPath) as $file) {
            $relativePath = $this->relativePath($pluginPath, $file);
            $code = file_get_contents($file);
            if ($code === false) continue;

            $original = $code;

            // Auto-fix river event renames in elgg-plugin.php
            if (str_ends_with($file, 'elgg-plugin.php')) {
                foreach (self::EVENT_MAP as $key => $info) {
                    if ($info['new_name'] === null) continue;
                    [$oldName, $oldType] = explode(':', $key, 2);
                    $code = str_replace("'{$oldName}'", "'{$info['new_name']}'", $code);
                }
            }

            if ($code !== $original) {
                file_put_contents($file, $code);
                $changes[] = new FileChange(
                    file: $relativePath,
                    type: 'modified',
                    description: 'Renamed deprecated event registrations',
                );
            }

            // Collect warnings for everything else
            $ast = $this->parse($original);
            if ($ast === null) continue;

            $hookCalls = $this->findFunctionCalls($ast, ['elgg_register_plugin_hook_handler']);
            foreach ($hookCalls as $call) {
                $finding = $this->checkHookCall($call, $relativePath);
                if ($finding) {
                    $warnings[] = "{$finding->file}:{$finding->line} — {$finding->description}";
                }
            }

            $eventCalls = $this->findFunctionCalls($ast, ['elgg_register_event_handler']);
            foreach ($eventCalls as $call) {
                $finding = $this->checkEventCall($call, $relativePath);
                if ($finding) {
                    $warnings[] = "{$finding->file}:{$finding->line} — {$finding->description}";
                }
            }
        }

        return new RuleResult(
            ruleId: $this->getId(),
            success: true,
            changes: $changes,
            warnings: $warnings,
        );
    }

    private function checkHookCall(Node\Expr\FuncCall $call, string $file): ?Finding
    {
        if (count($call->args) < 2) return null;

        $hookName = $this->getStringArg($call, 0);
        $hookType = $this->getStringArg($call, 1);
        if ($hookName === null || $hookType === null) return null;

        $key = "{$hookName}:{$hookType}";
        if (isset(self::HOOK_MAP[$key])) {
            $info = self::HOOK_MAP[$key];
            return new Finding(
                file: $file,
                line: $call->getLine(),
                description: $info['note'],
                code: $this->printer()->prettyPrintExpr($call),
            );
        }

        // Check wildcard entries (e.g., filter_tabs:*)
        $wildcardKey = "{$hookName}:*";
        if (isset(self::HOOK_MAP[$wildcardKey])) {
            $info = self::HOOK_MAP[$wildcardKey];
            return new Finding(
                file: $file,
                line: $call->getLine(),
                description: $info['note'],
                code: $this->printer()->prettyPrintExpr($call),
            );
        }

        return null;
    }

    private function checkEventCall(Node\Expr\FuncCall $call, string $file): ?Finding
    {
        if (count($call->args) < 2) return null;

        $eventName = $this->getStringArg($call, 0);
        $eventType = $this->getStringArg($call, 1);
        if ($eventName === null || $eventType === null) return null;

        $key = "{$eventName}:{$eventType}";
        if (isset(self::EVENT_MAP[$key])) {
            $info = self::EVENT_MAP[$key];
            return new Finding(
                file: $file,
                line: $call->getLine(),
                description: $info['note'],
                code: $this->printer()->prettyPrintExpr($call),
            );
        }

        return null;
    }

    private function checkPluginPhpArray(string $code, string $file): array
    {
        $findings = [];

        // Check for deprecated hook names in array keys
        foreach (self::HOOK_MAP as $key => $info) {
            [$hookName, $hookType] = explode(':', $key, 2);
            if ($hookType === '*') {
                if (str_contains($code, "'{$hookName}'")) {
                    $findings[] = new Finding(
                        file: $file,
                        line: 0,
                        description: $info['note'],
                        code: '',
                    );
                }
            } else {
                if (str_contains($code, "'{$hookName}'") && str_contains($code, "'{$hookType}'")) {
                    $findings[] = new Finding(
                        file: $file,
                        line: 0,
                        description: $info['note'],
                        code: '',
                    );
                }
            }
        }

        // Check for deprecated event names
        foreach (self::EVENT_MAP as $key => $info) {
            [$eventName, $eventType] = explode(':', $key, 2);
            if (str_contains($code, "'{$eventName}'") && str_contains($code, "'{$eventType}'")) {
                $findings[] = new Finding(
                    file: $file,
                    line: 0,
                    description: $info['note'],
                    code: '',
                );
            }
        }

        return $findings;
    }

    private function getStringArg(Node\Expr\FuncCall $call, int $index): ?string
    {
        if (!isset($call->args[$index])) return null;
        $value = $call->args[$index]->value;
        return $value instanceof Node\Scalar\String_ ? $value->value : null;
    }
}
