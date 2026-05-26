<?php

declare(strict_types=1);

namespace ElggMigrate;

use PhpParser\Node;
use PhpParser\NodeFinder;
use PhpParser\ParserFactory;

/**
 * Detects a plugin's current Elgg version and validates that a manifest
 * targets the correct "from" version. Prevents version-skipping and
 * wrong-manifest application.
 *
 * Also exposes `detectIncompletePatterns()` — a deeper check that scans for
 * prior-version code patterns lingering inside a plugin whose file shape
 * already looks like a newer version. Shape-only detection can't catch
 * partial migrations (e.g. start.php is gone but 3.x hook signatures
 * remain). That's the gap hypeinbox migrate/elgg-4.x demonstrated — shape
 * says 4.x, content has 3.x hook handlers + removed-in-4.x function calls.
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

    /**
     * Scan a plugin for prior-version code patterns that contradict the
     * claimed version. Returns concrete file/line findings so the caller can
     * decide whether to warn, block, or just report.
     *
     * Use cases:
     *  - Pre-flight: a plugin whose shape says 4.x but content has 3.x
     *    leftovers should be migrated as 3.x → 4.x (the file-shape guard
     *    would normally refuse this). Caller can pass --no-guard with the
     *    confidence that the completeness check identified real work.
     *  - Post-flight: after a migration run, verify no source-version
     *    patterns remain. Treat any finding as a regression.
     *
     * @param string $pluginPath Plugin directory to scan
     * @param string|null $claimedVersion Defaults to detectVersion() output
     * @return array<IncompletePatternFinding>
     */
    public function detectIncompletePatterns(string $pluginPath, ?string $claimedVersion = null): array
    {
        $claimed = $claimedVersion ?? $this->detectVersion($pluginPath);
        $sourceVersion = $this->priorVersion($claimed);
        if ($sourceVersion === null) {
            return [];
        }

        $findings = [];
        foreach ($this->phpFiles($pluginPath) as $file) {
            $code = file_get_contents($file);
            if ($code === false) continue;
            $ast = $this->parseQuietly($code);
            if ($ast === null) continue;

            $rel = str_replace($pluginPath . '/', '', $file);

            foreach (self::leftoverPatternsFor($sourceVersion, $claimed) as $pattern) {
                $method = 'find_' . $pattern['detector'];
                foreach ($this->$method($ast, $pattern) as $hit) {
                    $findings[] = new IncompletePatternFinding(
                        file: $rel,
                        line: $hit['line'],
                        sourceVersion: $sourceVersion,
                        claimedVersion: $claimed,
                        patternId: $pattern['id'],
                        description: $hit['description'],
                        fix: $pattern['fix'],
                    );
                }
            }
        }
        return $findings;
    }

    /**
     * Returns the version immediately prior to $version, or null at 2.x.
     */
    private function priorVersion(string $version): ?string
    {
        $major = (int) $version[0];
        return $major >= 3 ? ($major - 1) . '.x' : null;
    }

    /**
     * Known leftover patterns to scan for, keyed by (sourceVersion, claimedVersion).
     * Each pattern specifies a detector method on this class plus a fix hint.
     *
     * @return array<int, array{id:string, detector:string, fix:string, ...}>
     */
    private static function leftoverPatternsFor(string $sourceVersion, string $claimedVersion): array
    {
        $key = "{$sourceVersion}->{$claimedVersion}";
        $patterns = [
            '3.x->4.x' => [
                [
                    'id' => 'old-hook-signature',
                    'detector' => 'old4ArgHookSignature',
                    'fix' => 'Convert to `\\Elgg\\Hook $hook` single-param signature; use $hook->getValue(), getType(), getParam(), getParams().',
                ],
                [
                    'id' => 'removed-function-call',
                    'detector' => 'removedIn4xFunctionCall',
                    'fix' => 'Function was removed in Elgg 4.x — see RemovedFunctions rule notes for the replacement.',
                ],
            ],
            // Add 4.x->5.x, 5.x->6.x, 6.x->7.x as we encounter them.
        ];
        return $patterns[$key] ?? [];
    }

    /**
     * Detector: 4-argument hook handler signature characteristic of Elgg 3.x.
     *
     * Matches both standalone functions and class methods whose parameter list
     * is exactly `($hook, $type, $return, $params)` — the Elgg 3.x hook
     * signature replaced in 4.x by a single \Elgg\Hook object.
     *
     * @param array<Node\Stmt> $ast
     * @param array $pattern
     * @return array<int, array{line:int, description:string}>
     */
    private function find_old4ArgHookSignature(array $ast, array $pattern): array
    {
        $finder = new NodeFinder();
        $hits = [];

        $functions = $finder->find($ast, fn (Node $n) =>
            $n instanceof Node\FunctionLike
        );
        foreach ($functions as $fn) {
            /** @var Node\FunctionLike $fn */
            $params = $fn->getParams();
            if (count($params) !== 4) continue;
            $expected = ['hook', 'type', 'return', 'params'];
            $actual = array_map(
                fn (Node\Param $p) => $p->var instanceof Node\Expr\Variable && is_string($p->var->name) ? $p->var->name : '',
                $params,
            );
            if ($actual !== $expected) continue;

            $name = $fn instanceof Node\Stmt\Function_ ? $fn->name->toString()
                  : ($fn instanceof Node\Stmt\ClassMethod ? $fn->name->toString() : '<closure>');
            $hits[] = [
                'line' => $fn->getStartLine(),
                'description' => sprintf(
                    "Function '%s(\$hook, \$type, \$return, \$params)' uses 3.x hook handler signature",
                    $name,
                ),
            ];
        }
        return $hits;
    }

    /**
     * Detector: calls to functions removed in Elgg 4.x. Conservative list
     * derived from real bodyology snapshot-test findings (2026-05-26) plus
     * a small set of high-signal removals. Add more as new gaps surface.
     */
    private const REMOVED_IN_4X = [
        'sanitize_string' => 'Use htmlspecialchars($x, ENT_QUOTES, "UTF-8")',
        'sanitize_int' => 'Cast to (int)',
        'elgg_register_admin_menu_item' => 'Removed — admin menu entries auto-discovered from views/default/admin/*',
        'elgg_set_plugin_setting' => 'Use $plugin->setSetting($name, $value)',
        'elgg_unset_plugin_setting' => 'Use $plugin->unsetSetting($name)',
    ];

    /**
     * @param array<Node\Stmt> $ast
     * @param array $pattern
     * @return array<int, array{line:int, description:string}>
     */
    private function find_removedIn4xFunctionCall(array $ast, array $pattern): array
    {
        $finder = new NodeFinder();
        $hits = [];
        $calls = $finder->findInstanceOf($ast, Node\Expr\FuncCall::class);
        foreach ($calls as $call) {
            assert($call instanceof Node\Expr\FuncCall);
            if (!$call->name instanceof Node\Name) continue;
            $name = $call->name->toString();
            if (!isset(self::REMOVED_IN_4X[$name])) continue;

            $hits[] = [
                'line' => $call->getStartLine(),
                'description' => sprintf("Call to '%s()' — removed in Elgg 4.x. %s", $name, self::REMOVED_IN_4X[$name]),
            ];
        }
        return $hits;
    }

    /**
     * Parse PHP source without raising — returns null on syntax errors so the
     * scan continues over the rest of the plugin.
     *
     * @return array<Node\Stmt>|null
     */
    private function parseQuietly(string $code): ?array
    {
        try {
            $parser = (new ParserFactory())->createForNewestSupportedVersion();
            return $parser->parse($code);
        } catch (\Throwable) {
            return null;
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
