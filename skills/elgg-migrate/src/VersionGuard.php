<?php

declare(strict_types=1);

namespace ElggMigrate;

/**
 * Detects a plugin's current Elgg version and validates that a manifest
 * targets the correct "from" version. Prevents version-skipping and
 * wrong-manifest application.
 */
final class VersionGuard
{
    /**
     * Ordered indicators used to detect the plugin's current Elgg version.
     * Each check is tried in sequence; the first match wins.
     */
    private const VERSION_INDICATORS = [
        // 6.x: ES modules
        ['version' => '6.x', 'check' => 'hasEsmRegistration'],
        // 5.x: events-only (no hooks key)
        ['version' => '5.x', 'check' => 'hasEventsOnlyConfig'],
        // 4.x: elgg-plugin.php with hooks key, no start.php, no manifest.xml
        ['version' => '4.x', 'check' => 'hasDeclarativeConfigOnly'],
        // 3.x: elgg-plugin.php AND (start.php or manifest.xml)
        ['version' => '3.x', 'check' => 'hasTransitionalConfig'],
        // 2.x: start.php with top-level event handler, manifest.xml, no elgg-plugin.php
        ['version' => '2.x', 'check' => 'hasProceduralConfig'],
    ];

    /**
     * Detect the plugin's current Elgg version from its file structure and content.
     *
     * @return string Version string like '2.x', '3.x', '4.x', '5.x', '6.x'
     * @throws \RuntimeException If version cannot be determined
     */
    public function detectVersion(string $pluginPath): string
    {
        foreach (self::VERSION_INDICATORS as $indicator) {
            $method = $indicator['check'];
            if ($this->$method($pluginPath)) {
                return $indicator['version'];
            }
        }

        throw new \RuntimeException(
            "Cannot detect Elgg version for plugin at {$pluginPath}. "
            . 'No recognized version indicators found (no start.php, no elgg-plugin.php, no manifest.xml).'
        );
    }

    /**
     * Validate that a manifest's "from" version matches the plugin's detected version.
     *
     * @param array{from: string, to: string} $manifest Parsed manifest structure
     * @throws VersionMismatchException If the plugin version doesn't match the manifest
     */
    public function validate(string $pluginPath, array $manifest): void
    {
        $detected = $this->detectVersion($pluginPath);
        $expected = $manifest['from'] ?? null;

        if ($expected === null) {
            throw new \RuntimeException('Manifest missing "from" version field');
        }

        if ($detected !== $expected) {
            throw new VersionMismatchException(
                "Version mismatch: plugin at {$pluginPath} appears to be Elgg {$detected}, "
                . "but manifest targets migration from {$expected} to {$manifest['to']}. "
                . $this->suggestCorrectManifest($detected, $manifest['to']),
                $detected,
                $expected,
            );
        }
    }

    // --- Version detection methods ---

    private function hasEsmRegistration(string $path): bool
    {
        $pluginPhp = $path . '/elgg-plugin.php';
        if (!is_file($pluginPhp)) {
            return false;
        }

        // Check for ESM-specific patterns in PHP files
        foreach ($this->phpFiles($path) as $file) {
            $code = file_get_contents($file);
            if ($code === false) continue;
            if (str_contains($code, 'elgg_register_esm') || str_contains($code, 'elgg_import_esm')) {
                return true;
            }
        }

        return false;
    }

    private function hasEventsOnlyConfig(string $path): bool
    {
        $pluginPhp = $path . '/elgg-plugin.php';
        if (!is_file($pluginPhp)) {
            return false;
        }

        // Must NOT have start.php or manifest.xml
        if (is_file($path . '/start.php') || is_file($path . '/manifest.xml')) {
            return false;
        }

        $content = file_get_contents($pluginPhp);
        if ($content === false) {
            return false;
        }

        // Has 'events' key but no 'hooks' key
        $hasEvents = (bool) preg_match("/['\"]events['\"]\s*=>/", $content);
        $hasHooks = (bool) preg_match("/['\"]hooks['\"]\s*=>/", $content);

        return $hasEvents && !$hasHooks;
    }

    private function hasDeclarativeConfigOnly(string $path): bool
    {
        return is_file($path . '/elgg-plugin.php')
            && !is_file($path . '/start.php')
            && !is_file($path . '/manifest.xml');
    }

    private function hasTransitionalConfig(string $path): bool
    {
        return is_file($path . '/elgg-plugin.php')
            && (is_file($path . '/start.php') || is_file($path . '/manifest.xml'));
    }

    private function hasProceduralConfig(string $path): bool
    {
        return is_file($path . '/start.php')
            && !is_file($path . '/elgg-plugin.php');
    }

    // --- Helpers ---

    private function suggestCorrectManifest(string $detected, string $targetTo): string
    {
        $major = (int) $detected[0];
        $nextMajor = $major + 1;
        $suggestedManifest = "rules/{$major}x-to-{$nextMajor}x/manifest.json";

        return "Did you mean to use {$suggestedManifest}?";
    }

    /**
     * @return \Generator<string>
     */
    private function phpFiles(string $dir): \Generator
    {
        if (!is_dir($dir)) {
            return;
        }

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
        );

        foreach ($iterator as $file) {
            /** @var \SplFileInfo $file */
            if ($file->getExtension() !== 'php') continue;
            $path = $file->getPathname();
            if (str_contains($path, '/vendor/') || str_contains($path, '/vendors/') || str_contains($path, '/mod/')) {
                continue;
            }
            yield $path;
        }
    }
}
