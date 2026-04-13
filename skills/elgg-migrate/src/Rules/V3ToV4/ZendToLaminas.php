<?php

declare(strict_types=1);

namespace ElggMigrate\Rules\V3ToV4;

use ElggMigrate\AbstractRule;
use ElggMigrate\FileChange;
use ElggMigrate\Finding;
use ElggMigrate\RuleAnalysis;
use ElggMigrate\RuleResult;

/**
 * Replaces Zend\Mail namespace references with Laminas\Mail.
 *
 * Zend Framework was rebranded to Laminas — Elgg 4.x ships with Laminas.
 */
final class ZendToLaminas extends AbstractRule
{
    private const NAMESPACE_MAP = [
        'Zend\\Mail' => 'Laminas\\Mail',
    ];

    private const COMPOSER_MAP = [
        'zendframework/zend-mail' => 'laminas/laminas-mail',
    ];

    public function getId(): string
    {
        return 'zend-to-laminas';
    }

    public function getDescription(): string
    {
        return 'Replace Zend\\Mail with Laminas\\Mail';
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

            foreach (self::NAMESPACE_MAP as $old => $new) {
                if (str_contains($code, $old)) {
                    // Count occurrences
                    $count = substr_count($code, $old);
                    $findings[] = new Finding(
                        file: $relativePath,
                        line: 0,
                        description: "{$old} → {$new} ({$count} occurrence(s))",
                        code: '',
                    );
                }
            }
        }

        // Check composer.json
        $composerPath = $pluginPath . '/composer.json';
        if (is_file($composerPath)) {
            $json = json_decode(file_get_contents($composerPath), true);
            foreach (self::COMPOSER_MAP as $old => $new) {
                if (isset($json['require'][$old]) || isset($json['require-dev'][$old])) {
                    $findings[] = new Finding(
                        file: 'composer.json',
                        line: 0,
                        description: "{$old} → {$new}",
                        code: '',
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
                ? sprintf('Found %d Zend reference(s) to replace', count($findings))
                : 'No Zend references found',
        );
    }

    public function apply(string $pluginPath): RuleResult
    {
        $changes = [];

        foreach ($this->findPhpFiles($pluginPath) as $file) {
            $relativePath = $this->relativePath($pluginPath, $file);
            $code = file_get_contents($file);
            if ($code === false) continue;

            $original = $code;
            foreach (self::NAMESPACE_MAP as $old => $new) {
                $code = str_replace($old, $new, $code);
            }

            if ($code !== $original) {
                file_put_contents($file, $code);
                $changes[] = new FileChange(
                    file: $relativePath,
                    type: 'modified',
                    description: 'Replaced Zend\\Mail with Laminas\\Mail',
                );
            }
        }

        // Update composer.json
        $composerPath = $pluginPath . '/composer.json';
        if (is_file($composerPath)) {
            $json = json_decode(file_get_contents($composerPath), true);
            $modified = false;

            foreach (['require', 'require-dev'] as $section) {
                if (!isset($json[$section])) continue;
                foreach (self::COMPOSER_MAP as $old => $new) {
                    if (isset($json[$section][$old])) {
                        $version = $json[$section][$old];
                        unset($json[$section][$old]);
                        $json[$section][$new] = $version;
                        $modified = true;
                    }
                }
            }

            if ($modified) {
                file_put_contents($composerPath, json_encode($json, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
                $changes[] = new FileChange(
                    file: 'composer.json',
                    type: 'modified',
                    description: 'Replaced zendframework packages with laminas equivalents',
                );
            }
        }

        return new RuleResult(
            ruleId: $this->getId(),
            success: true,
            changes: $changes,
        );
    }
}
