<?php

declare(strict_types=1);

namespace ElggMigrate\Rules\V4ToV5;

use ElggMigrate\AbstractRule;
use ElggMigrate\FileChange;
use ElggMigrate\Finding;
use ElggMigrate\RuleAnalysis;
use ElggMigrate\RuleResult;

/**
 * Updates composer.json to target Elgg 5.x, PHP >=8.1, and adds ext-intl.
 *
 * Elgg 5.x requires PHP 8.1+ and the intl extension. This rule bumps the
 * elgg/elgg constraint to ~5.0.0, sets php to >=8.1, and adds ext-intl if
 * not already present.
 */
final class UpdateManifestVersion extends AbstractRule
{
    public function getId(): string
    {
        return 'update-manifest-version-5x';
    }

    public function getDescription(): string
    {
        return 'Update composer.json to target Elgg 5.x (elgg/elgg ~5.0.0, PHP >=8.1, ext-intl)';
    }

    public function canAutomate(): bool
    {
        return true;
    }

    public function analyze(string $pluginPath): RuleAnalysis
    {
        $findings = [];

        $composerPath = $pluginPath . '/composer.json';
        if (!is_file($composerPath)) {
            return new RuleAnalysis(
                ruleId: $this->getId(),
                applicable: false,
                findings: [],
                summary: 'No composer.json found',
            );
        }

        $json = json_decode(file_get_contents($composerPath), true);

        $elggReq = $json['require']['elgg/elgg'] ?? null;
        if ($elggReq !== null && !str_contains($elggReq, '5')) {
            $findings[] = new Finding('composer.json', 0, "elgg/elgg is \"{$elggReq}\", should target ~5.0.0", '');
        }

        $phpReq = $json['require']['php'] ?? null;
        if ($phpReq !== null && $phpReq !== '>=8.1') {
            $findings[] = new Finding('composer.json', 0, "php is \"{$phpReq}\", should be >=8.1", '');
        }

        if (!isset($json['require']['ext-intl'])) {
            $findings[] = new Finding('composer.json', 0, 'ext-intl is missing (required by Elgg 5.x)', '');
        }

        $applicable = count($findings) > 0;

        return new RuleAnalysis(
            ruleId: $this->getId(),
            applicable: $applicable,
            findings: $findings,
            summary: $applicable
                ? sprintf('Found %d requirement(s) to update for 5.x', count($findings))
                : 'Already targets 5.x with correct PHP and ext-intl requirements',
        );
    }

    public function apply(string $pluginPath): RuleResult
    {
        $changes = [];

        $composerPath = $pluginPath . '/composer.json';
        if (!is_file($composerPath)) {
            return new RuleResult(
                ruleId: $this->getId(),
                success: true,
                changes: [],
            );
        }

        $raw = file_get_contents($composerPath);
        $json = json_decode($raw, true);
        $modified = false;

        // Update elgg/elgg constraint
        $elggReq = $json['require']['elgg/elgg'] ?? null;
        if ($elggReq !== null && !str_contains($elggReq, '5')) {
            $json['require']['elgg/elgg'] = '~5.0.0';
            $changes[] = new FileChange('composer.json', 'modified', "Updated elgg/elgg from \"{$elggReq}\" to \"~5.0.0\"");
            $modified = true;
        }

        // Update PHP requirement
        $phpReq = $json['require']['php'] ?? null;
        if ($phpReq !== null && $phpReq !== '>=8.1') {
            $json['require']['php'] = '>=8.1';
            $changes[] = new FileChange('composer.json', 'modified', "Updated php from \"{$phpReq}\" to \">=8.1\"");
            $modified = true;
        }

        // Add ext-intl if not present
        if (!isset($json['require']['ext-intl'])) {
            $json['require']['ext-intl'] = '*';
            $changes[] = new FileChange('composer.json', 'modified', 'Added ext-intl requirement (required by Elgg 5.x)');
            $modified = true;
        }

        if ($modified) {
            file_put_contents($composerPath, json_encode($json, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
        }

        return new RuleResult(
            ruleId: $this->getId(),
            success: true,
            changes: $changes,
        );
    }
}
