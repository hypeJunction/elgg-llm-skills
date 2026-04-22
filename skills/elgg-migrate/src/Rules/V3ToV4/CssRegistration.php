<?php

declare(strict_types=1);

namespace ElggMigrate\Rules\V3ToV4;

use ElggMigrate\AbstractRule;
use ElggMigrate\Finding;
use ElggMigrate\RuleAnalysis;
use ElggMigrate\RuleResult;

/**
 * Flags CSS/JS registration functions removed in Elgg 4.x.
 *
 * These functions were replaced by elgg_register_external_file() and
 * elgg_load_external_file(), which require a type argument ('css' or 'js')
 * prepended to the original argument list. Manual review is required.
 */
final class CssRegistration extends AbstractRule
{
    /**
     * Map of removed function → replacement note.
     */
    private const MAP = [
        'elgg_register_css' => "Use elgg_register_external_file('css', \$name, \$url, \$priority)",
        'elgg_load_css'     => "Use elgg_load_external_file('css', \$name)",
        'elgg_register_js'  => "Use elgg_register_external_file('js', \$name, \$url, \$priority)",
        'elgg_load_js'      => "Use elgg_load_external_file('js', \$name)",
        'elgg_get_loaded_css' => "Use elgg_get_loaded_external_files('css', 'head')",
        'elgg_get_loaded_js'  => "Use elgg_get_loaded_external_files('js', \$location)",
    ];

    public function getId(): string
    {
        return 'css-js-registration-4x';
    }

    public function getDescription(): string
    {
        return 'Flag CSS/JS registration functions removed in Elgg 4.x';
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
            if ($code === false) continue;

            $ast = $this->parse($code);
            if ($ast === null) continue;

            $calls = $this->findFunctionCalls($ast, $targetNames);
            $printer = $this->printer();

            foreach ($calls as $call) {
                $funcName = $call->name->toString();
                $note = self::MAP[$funcName];

                $findings[] = new Finding(
                    file: $relativePath,
                    line: $call->getLine(),
                    description: "{$funcName}() removed in 4.0: {$note}",
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
                ? sprintf('Found %d CSS/JS registration call(s) to update', count($findings))
                : 'No removed CSS/JS registration calls found',
        );
    }

    public function apply(string $pluginPath): RuleResult
    {
        // Warn-only — replacement requires adding a type argument ('css'/'js') as first arg
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
}
