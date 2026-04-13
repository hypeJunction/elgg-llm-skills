<?php

declare(strict_types=1);

namespace ElggMigrate\Rules\V2ToV3;

use ElggMigrate\AbstractRule;
use ElggMigrate\FileChange;
use ElggMigrate\Finding;
use ElggMigrate\RuleAnalysis;
use ElggMigrate\RuleResult;

/**
 * Updates manifest.xml elgg_release version and composer.json elgg/elgg
 * requirement to target Elgg 3.x.
 *
 * manifest.xml: <version>2.x</version> → <version>3.0</version>
 * composer.json: "elgg/elgg": "2.*" → "elgg/elgg": "^3.3"
 */
final class UpdateManifestVersion extends AbstractRule
{
    public function getId(): string
    {
        return 'update-manifest-version';
    }

    public function getDescription(): string
    {
        return 'Update manifest.xml and composer.json to target Elgg 3.x';
    }

    public function canAutomate(): bool
    {
        return true;
    }

    public function analyze(string $pluginPath): RuleAnalysis
    {
        $findings = [];

        // Check manifest.xml
        $manifestPath = $pluginPath . '/manifest.xml';
        if (is_file($manifestPath)) {
            $content = file_get_contents($manifestPath);
            if (preg_match('/<type>elgg_release<\/type>\s*<version>([^<]+)<\/version>/s', $content, $m)) {
                $currentVersion = trim($m[1]);
                if (version_compare($currentVersion, '3.0', '<')) {
                    $findings[] = new Finding(
                        file: 'manifest.xml',
                        line: $this->findLineNumber($content, $m[0]),
                        description: "elgg_release requirement is {$currentVersion}, should be 3.0",
                        code: $m[0],
                    );
                }
            }
        }

        // Check composer.json
        $composerPath = $pluginPath . '/composer.json';
        if (is_file($composerPath)) {
            $content = file_get_contents($composerPath);
            $json = json_decode($content, true);
            if ($json) {
                $elggReq = $json['require']['elgg/elgg'] ?? null;
                if ($elggReq && !$this->satisfies3x($elggReq)) {
                    $findings[] = new Finding(
                        file: 'composer.json',
                        line: $this->findLineNumber($content, '"elgg/elgg"'),
                        description: "elgg/elgg requirement is \"{$elggReq}\", should target ^3.3",
                        code: "\"elgg/elgg\": \"{$elggReq}\"",
                    );
                }
            }
        }

        $applicable = count($findings) > 0;

        return new RuleAnalysis(
            ruleId: $this->getId(),
            applicable: $applicable,
            findings: $findings,
            summary: $applicable
                ? sprintf('Found %d version requirement(s) to update', count($findings))
                : 'Version requirements already target 3.x or higher',
        );
    }

    public function apply(string $pluginPath): RuleResult
    {
        $changes = [];
        $warnings = [];

        // Update manifest.xml
        $manifestPath = $pluginPath . '/manifest.xml';
        if (is_file($manifestPath)) {
            $content = file_get_contents($manifestPath);
            $original = $content;

            // Replace elgg_release version
            $content = preg_replace(
                '/(<type>elgg_release<\/type>\s*<version>)[^<]+(\.?\d*)?(<\/version>)/s',
                '${1}3.0${3}',
                $content,
            );

            if ($content !== $original) {
                file_put_contents($manifestPath, $content);
                $changes[] = new FileChange(
                    file: 'manifest.xml',
                    type: 'modified',
                    description: 'Updated elgg_release requirement to 3.0',
                );
            }
        }

        // Update composer.json
        $composerPath = $pluginPath . '/composer.json';
        if (is_file($composerPath)) {
            $content = file_get_contents($composerPath);
            $json = json_decode($content, true);

            if ($json) {
                $elggReq = $json['require']['elgg/elgg'] ?? null;

                if ($elggReq && !$this->satisfies3x($elggReq)) {
                    $json['require']['elgg/elgg'] = '^3.3';
                    $newContent = json_encode($json, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";
                    file_put_contents($composerPath, $newContent);
                    $changes[] = new FileChange(
                        file: 'composer.json',
                        type: 'modified',
                        description: "Updated elgg/elgg requirement from \"{$elggReq}\" to \"^3.3\"",
                    );
                }
            }
        }

        if (empty($changes)) {
            return new RuleResult(
                ruleId: $this->getId(),
                success: true,
                changes: [],
                warnings: ['Version requirements already target 3.x or higher'],
            );
        }

        return new RuleResult(
            ruleId: $this->getId(),
            success: true,
            changes: $changes,
            warnings: $warnings,
        );
    }

    private function satisfies3x(string $constraint): bool
    {
        // Simple check: does the constraint allow 3.x?
        return str_contains($constraint, '3.')
            || str_contains($constraint, '^3')
            || str_contains($constraint, '~3')
            || str_contains($constraint, '>=3');
    }

    private function findLineNumber(string $content, string $needle): int
    {
        $pos = strpos($content, $needle);
        if ($pos === false) {
            return 0;
        }
        return substr_count($content, "\n", 0, $pos) + 1;
    }
}
