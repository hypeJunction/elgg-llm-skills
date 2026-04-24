<?php

declare(strict_types=1);

namespace ElggMigrate\Rules\V3ToV4;

use ElggMigrate\AbstractRule;
use ElggMigrate\Finding;
use ElggMigrate\RuleAnalysis;
use ElggMigrate\RuleResult;
use PhpParser\Node;

/**
 * ~150 core callback functions were renamed from _elgg_xxx/_groups_xxx/_members_xxx
 * procedural functions to Class::method handlers in Elgg 4.0. Plugins that
 * unregister core handlers by the old names get silent failures at runtime.
 */
final class CallbackRenames extends AbstractRule
{
    // Prefixes used by old procedural core callbacks
    private const OLD_PREFIXES = ['_elgg_', '_groups_', '_members_'];

    private const UNREGISTER_FUNCTIONS = [
        'elgg_unregister_plugin_hook_handler',
        'elgg_unregister_event_handler',
    ];

    public function getId(): string
    {
        return 'callback-renames-4x';
    }

    public function getDescription(): string
    {
        return 'Flag elgg_unregister_* calls using old _elgg_/* procedural callback names renamed in 4.0';
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

            $calls = $this->findFunctionCalls($ast, self::UNREGISTER_FUNCTIONS);
            $printer = $this->printer();

            foreach ($calls as $call) {
                $callback = $this->detectOldCallback($call);
                if ($callback === null) continue;

                $funcName = $call->name->toString();
                $findings[] = new Finding(
                    file: $relativePath,
                    line: $call->getLine(),
                    description: "{$funcName}() uses old callback '{$callback}' — renamed in 4.0 to a Class::method handler; check upgrade notes",
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
                ? sprintf('Found %d unregister call(s) using renamed callbacks', count($findings))
                : 'No unregister calls with old _elgg_* callback names found',
        );
    }

    public function apply(string $pluginPath): RuleResult
    {
        $analysis = $this->analyze($pluginPath);
        $warnings = [];

        foreach ($analysis->findings as $finding) {
            $warnings[] = "{$finding->file}:{$finding->line} — {$finding->description}";
        }

        return new RuleResult(
            ruleId: $this->getId(),
            success: true,
            changes: [],
            warnings: $warnings,
        );
    }

    /**
     * Returns the callback string if any argument uses an old procedural prefix,
     * or null if no old-style callback is detected.
     */
    private function detectOldCallback(Node\Expr\FuncCall $call): ?string
    {
        foreach ($call->args as $arg) {
            if (!$arg instanceof Node\Arg) continue;
            $value = $arg->value;

            if ($value instanceof Node\Scalar\String_) {
                foreach (self::OLD_PREFIXES as $prefix) {
                    if (str_starts_with($value->value, $prefix)) {
                        return $value->value;
                    }
                }
            }
        }

        return null;
    }
}
