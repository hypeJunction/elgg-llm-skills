<?php

declare(strict_types=1);

namespace ElggMigrate\Rules\V3ToV4;

use ElggMigrate\AbstractRule;
use ElggMigrate\FileChange;
use ElggMigrate\Finding;
use ElggMigrate\RuleAnalysis;
use ElggMigrate\RuleResult;

/**
 * Updates manifest.xml to target Elgg 4.x and composer.json to ^4.3.
 *
 * In Elgg 4.x, manifest.xml is still read but elgg-plugin.php is preferred.
 * This rule updates the version requirements; the GenerateElggPluginPhp rule
 * handles creating elgg-plugin.php.
 */
final class UpdateManifestVersion extends AbstractRule
{
    public function getId(): string
    {
        return 'update-manifest-version-4x';
    }

    public function getDescription(): string
    {
        return 'Update manifest.xml and composer.json to target Elgg 4.x';
    }

    public function canAutomate(): bool
    {
        return true;
    }

    public function analyze(string $pluginPath): RuleAnalysis
    {
        $findings = [];

        $manifestPath = $pluginPath . '/manifest.xml';
        if (is_file($manifestPath)) {
            $content = file_get_contents($manifestPath);
            if (preg_match('/<type>elgg_release<\/type>\s*<version>([^<]+)<\/version>/s', $content, $m)) {
                $v = trim($m[1]);
                if (version_compare($v, '4.0', '<')) {
                    $findings[] = new Finding('manifest.xml', 0, "elgg_release is {$v}, should be 4.0", $m[0]);
                }
            }
        }

        $composerPath = $pluginPath . '/composer.json';
        if (is_file($composerPath)) {
            $json = json_decode(file_get_contents($composerPath), true);
            $req = $json['require']['elgg/elgg'] ?? null;
            if ($req && !str_contains($req, '4') && !str_contains($req, '>=4')) {
                $findings[] = new Finding('composer.json', 0, "elgg/elgg is \"{$req}\", should target ^4.3", '');
            }
        }

        $applicable = count($findings) > 0;

        return new RuleAnalysis(
            ruleId: $this->getId(),
            applicable: $applicable,
            findings: $findings,
            summary: $applicable
                ? sprintf('Found %d version requirement(s) to update for 4.x', count($findings))
                : 'Already targets 4.x',
        );
    }

    public function apply(string $pluginPath): RuleResult
    {
        $changes = [];

        $manifestPath = $pluginPath . '/manifest.xml';
        if (is_file($manifestPath)) {
            $content = file_get_contents($manifestPath);
            $original = $content;
            $content = preg_replace(
                '/(<type>elgg_release<\/type>\s*<version>)[^<]+(<\/version>)/s',
                '${1}4.0${2}',
                $content,
            );
            if ($content !== $original) {
                file_put_contents($manifestPath, $content);
                $changes[] = new FileChange('manifest.xml', 'modified', 'Updated elgg_release to 4.0');
            }
        }

        $composerPath = $pluginPath . '/composer.json';
        if (is_file($composerPath)) {
            $json = json_decode(file_get_contents($composerPath), true);
            $req = $json['require']['elgg/elgg'] ?? null;
            if ($req && !str_contains($req, '4') && !str_contains($req, '>=4')) {
                $json['require']['elgg/elgg'] = '^4.3';
                file_put_contents($composerPath, json_encode($json, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
                $changes[] = new FileChange('composer.json', 'modified', "Updated elgg/elgg from \"{$req}\" to \"^4.3\"");
            }
        }

        return new RuleResult(
            ruleId: $this->getId(),
            success: true,
            changes: $changes,
        );
    }
}
