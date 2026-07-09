<?php

declare(strict_types=1);

namespace ElggMigrate\Rules\V3ToV4;

use ElggMigrate\AbstractRule;
use ElggMigrate\FileChange;
use ElggMigrate\Finding;
use ElggMigrate\RuleAnalysis;
use ElggMigrate\RuleResult;

/**
 * Replaces $vars['plugin'] with $vars['entity'] in plugin settings views.
 *
 * In Elgg 4.x the variable passed to plugin settings forms was renamed from
 * $vars['plugin'] to $vars['entity']. This is a safe text replacement because
 * $vars['plugin'] only appears in plugin settings views and forms.
 */
final class PluginSettingsVars extends AbstractRule
{
    private const OLD = "\$vars['plugin']";
    private const NEW = "\$vars['entity']";

    public function getId(): string
    {
        return 'plugin-settings-vars-4x';
    }

    public function getDescription(): string
    {
        return "Replace \$vars['plugin'] with \$vars['entity'] in plugin settings views";
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

            if (!str_contains($code, self::OLD)) continue;

            $count = substr_count($code, self::OLD);
            $findings[] = new Finding(
                file: $relativePath,
                line: 0,
                description: self::OLD . ' → ' . self::NEW . " ({$count} occurrence(s))",
                code: '',
            );
        }

        $applicable = count($findings) > 0;

        return new RuleAnalysis(
            ruleId: $this->getId(),
            applicable: $applicable,
            findings: $findings,
            summary: $applicable
                ? sprintf("Found %d file(s) with %s", count($findings), self::OLD)
                : self::OLD . ' not found',
        );
    }

    public function apply(string $pluginPath): RuleResult
    {
        $changes = [];

        foreach ($this->findPhpFiles($pluginPath) as $file) {
            $relativePath = $this->relativePath($pluginPath, $file);
            $code = file_get_contents($file);
            if ($code === false) continue;

            if (!str_contains($code, self::OLD)) continue;

            $updated = str_replace(self::OLD, self::NEW, $code);
            file_put_contents($file, $updated);

            $changes[] = new FileChange(
                file: $relativePath,
                type: 'modified',
                description: "Replaced \$vars['plugin'] with \$vars['entity']",
            );
        }

        return new RuleResult(
            ruleId: $this->getId(),
            success: true,
            changes: $changes,
        );
    }
}
