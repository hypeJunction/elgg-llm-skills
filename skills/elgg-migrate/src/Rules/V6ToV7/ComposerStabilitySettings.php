<?php

declare(strict_types=1);

namespace ElggMigrate\Rules\V6ToV7;

use ElggMigrate\AbstractRule;
use ElggMigrate\FileChange;
use ElggMigrate\Finding;
use ElggMigrate\RuleAnalysis;
use ElggMigrate\RuleResult;

/**
 * Ensures composer.json has the stability settings required by Elgg 7.x.
 *
 * Elgg 7.x plugins must declare:
 *   - "minimum-stability": "dev"
 *   - "prefer-stable": true
 *   - A repository entry for https://asset-packagist.org
 */
final class ComposerStabilitySettings extends AbstractRule
{
    private const ASSET_PACKAGIST_URL = 'https://asset-packagist.org';

    public function getId(): string
    {
        return 'composer-stability-settings-7x';
    }

    public function getDescription(): string
    {
        return 'Add minimum-stability:dev + prefer-stable:true + asset-packagist to composer.json';
    }

    public function canAutomate(): bool
    {
        return true;
    }

    public function analyze(string $pluginPath): RuleAnalysis
    {
        $findings = [];

        $composerFile = $pluginPath . '/composer.json';
        if (!is_file($composerFile)) {
            return new RuleAnalysis(
                ruleId: $this->getId(),
                applicable: false,
                findings: [],
                summary: 'No composer.json found — skipping',
            );
        }

        $raw = file_get_contents($composerFile);
        if ($raw === false) {
            return new RuleAnalysis(
                ruleId: $this->getId(),
                applicable: false,
                findings: [],
                summary: 'Could not read composer.json',
            );
        }

        $data = json_decode($raw, true);
        if (!is_array($data)) {
            return new RuleAnalysis(
                ruleId: $this->getId(),
                applicable: false,
                findings: [],
                summary: 'composer.json is not valid JSON — skipping',
            );
        }

        if (!isset($data['minimum-stability']) || $data['minimum-stability'] !== 'dev') {
            $findings[] = new Finding(
                file: 'composer.json',
                line: 0,
                description: '"minimum-stability" is missing or not set to "dev"',
                code: '',
            );
        }

        if (!isset($data['prefer-stable']) || $data['prefer-stable'] !== true) {
            $findings[] = new Finding(
                file: 'composer.json',
                line: 0,
                description: '"prefer-stable" is missing or not set to true',
                code: '',
            );
        }

        if (!$this->hasAssetPackagist($data)) {
            $findings[] = new Finding(
                file: 'composer.json',
                line: 0,
                description: 'Missing asset-packagist repository entry (https://asset-packagist.org)',
                code: '',
            );
        }

        $applicable = count($findings) > 0;

        return new RuleAnalysis(
            ruleId: $this->getId(),
            applicable: $applicable,
            findings: $findings,
            summary: $applicable
                ? sprintf('composer.json is missing %d required stability setting(s)', count($findings))
                : 'composer.json already has all required stability settings',
        );
    }

    public function apply(string $pluginPath): RuleResult
    {
        $composerFile = $pluginPath . '/composer.json';
        if (!is_file($composerFile)) {
            return new RuleResult(
                ruleId: $this->getId(),
                success: true,
                changes: [],
                warnings: ['No composer.json found — nothing to update'],
            );
        }

        $raw = file_get_contents($composerFile);
        if ($raw === false) {
            return new RuleResult(
                ruleId: $this->getId(),
                success: false,
                changes: [],
                warnings: ['Could not read composer.json'],
            );
        }

        $data = json_decode($raw, true);
        if (!is_array($data)) {
            return new RuleResult(
                ruleId: $this->getId(),
                success: false,
                changes: [],
                warnings: ['composer.json is not valid JSON — skipping'],
            );
        }

        $modified = false;

        if (!isset($data['minimum-stability']) || $data['minimum-stability'] !== 'dev') {
            $data['minimum-stability'] = 'dev';
            $modified = true;
        }

        if (!isset($data['prefer-stable']) || $data['prefer-stable'] !== true) {
            $data['prefer-stable'] = true;
            $modified = true;
        }

        if (!$this->hasAssetPackagist($data)) {
            if (!isset($data['repositories']) || !is_array($data['repositories'])) {
                $data['repositories'] = [];
            }
            $data['repositories'][] = [
                'type' => 'composer',
                'url' => self::ASSET_PACKAGIST_URL,
            ];
            $modified = true;
        }

        if (!$modified) {
            return new RuleResult(
                ruleId: $this->getId(),
                success: true,
                changes: [],
                warnings: [],
            );
        }

        $encoded = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        if ($encoded === false) {
            return new RuleResult(
                ruleId: $this->getId(),
                success: false,
                changes: [],
                warnings: ['Failed to re-encode composer.json as JSON'],
            );
        }

        file_put_contents($composerFile, $encoded . "\n");

        return new RuleResult(
            ruleId: $this->getId(),
            success: true,
            changes: [
                new FileChange(
                    file: 'composer.json',
                    type: 'modified',
                    description: 'Added minimum-stability:dev, prefer-stable:true, and asset-packagist repository',
                ),
            ],
            warnings: [],
        );
    }

    /**
     * Check if the composer.json data already has an asset-packagist repository entry.
     *
     * @param array<mixed> $data
     */
    private function hasAssetPackagist(array $data): bool
    {
        $repos = $data['repositories'] ?? [];
        if (!is_array($repos)) {
            return false;
        }

        foreach ($repos as $repo) {
            if (is_array($repo) && ($repo['url'] ?? '') === self::ASSET_PACKAGIST_URL) {
                return true;
            }
        }

        return false;
    }
}
