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
        // 7.x: composer.json requires elgg/elgg ~7.0. Must precede the 6.x check —
        // a 7.x plugin also registers ESM, so hasEsmRegistration would claim it
        // first and the 6.x->7.x completeness block would never be reachable.
        // Rule 000-update-manifest-version sets this constraint, so it is the
        // authoritative marker that a plugin has been migrated to 7.x.
        ['version' => '7.x', 'check' => 'hasElgg7Constraint'],
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
     * @return string Version string like '2.x', '3.x', '4.x', '5.x', '6.x', '7.x'
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
            '2.x->3.x' => [
                [
                    'id' => 'removed-function-call-3x',
                    'detector' => 'removedFunctionForTarget',
                    'target' => '3.x',
                    'fix' => 'Function was removed in Elgg 3.x — see references/removed-functions.json for the replacement (metastrings query API dropped with the metastrings table).',
                ],
            ],
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
            '4.x->5.x' => [
                [
                    'id' => 'elgg-hook-param',
                    'detector' => 'elggHookParamType',
                    'fix' => 'Convert `\\Elgg\\Hook $hook` parameter to `\\Elgg\\Event $event`; rename body references ($hook → $event) — 5.x unified hooks/events as Event.',
                ],
                [
                    'id' => 'hooks-key-in-elgg-plugin',
                    'detector' => 'hooksKeyInElggPlugin',
                    'fix' => "Rename the top-level 'hooks' key in elgg-plugin.php to 'events' — 5.x merged the two.",
                ],
                [
                    'id' => 'removed-function-call-5x',
                    'detector' => 'removedIn5xFunctionCall',
                    'fix' => 'Function was removed in Elgg 5.x — see the suggested replacement.',
                ],
            ],
            '5.x->6.x' => [
                [
                    'id' => 'removed-function-call-6x',
                    'detector' => 'removedFunctionForTarget',
                    'target' => '6.x',
                    'fix' => 'Function was removed in Elgg 6.x — see references/removed-functions.json (plugin-hook procedural API + register_error/system_message/forward family replaced by event equivalents).',
                ],
            ],
            '6.x->7.x' => [
                [
                    'id' => 'removed-function-call-7x',
                    'detector' => 'removedFunctionForTarget',
                    'target' => '7.x',
                    'fix' => 'Function was removed in Elgg 7.x — see references/removed-functions.json for the replacement.',
                ],
            ],
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
        // The canonical Elgg 3.x hook signature is ($hook, $type, $return, $params)
        // but third-party plugins (e.g. ColdTrick's) name the 3rd param differently.
        // Match any of the common variants to avoid false negatives.
        $shapes = [
            ['hook', 'type', 'return', 'params'],
            ['hook', 'type', 'returnvalue', 'params'],
            ['hook', 'type', 'value', 'params'],
            ['hook', 'type', 'return_value', 'params'],
        ];
        foreach ($functions as $fn) {
            /** @var Node\FunctionLike $fn */
            $params = $fn->getParams();
            if (count($params) !== 4) continue;
            $actual = array_map(
                fn (Node\Param $p) => $p->var instanceof Node\Expr\Variable && is_string($p->var->name) ? $p->var->name : '',
                $params,
            );
            if (!in_array($actual, $shapes, true)) continue;

            $name = $fn instanceof Node\Stmt\Function_ ? $fn->name->toString()
                  : ($fn instanceof Node\Stmt\ClassMethod ? $fn->name->toString() : '<closure>');
            $hits[] = [
                'line' => $fn->getStartLine(),
                'description' => sprintf(
                    "Function '%s(\$%s, \$%s, \$%s, \$%s)' uses 3.x hook handler signature",
                    $name,
                    $actual[0],
                    $actual[1],
                    $actual[2],
                    $actual[3],
                ),
            ];
        }
        return $hits;
    }

    /**
     * Detector: calls to functions removed in Elgg 4.x. Conservative list
     * derived from real-world snapshot-test findings (2026-05-26) plus
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
     * Detector: parameter typed as `\Elgg\Hook` (or `Hook` with a `use Elgg\Hook;`
     * import). In Elgg 5.x the hook + event APIs merged into a single Event class;
     * handlers must take `\Elgg\Event` instead.
     */
    private function find_elggHookParamType(array $ast, array $pattern): array
    {
        $finder = new NodeFinder();
        $hits = [];

        // Track `use Elgg\Hook` imports (so unqualified `Hook` resolves to it).
        $hookImported = false;
        foreach ($finder->findInstanceOf($ast, Node\Stmt\Use_::class) as $u) {
            assert($u instanceof Node\Stmt\Use_);
            if ($u->type !== Node\Stmt\Use_::TYPE_NORMAL && $u->type !== Node\Stmt\Use_::TYPE_UNKNOWN) continue;
            foreach ($u->uses as $uu) {
                if ($uu->name->toString() === 'Elgg\\Hook') {
                    $hookImported = true;
                }
            }
        }

        $functions = $finder->find($ast, fn (Node $n) => $n instanceof Node\FunctionLike);
        foreach ($functions as $fn) {
            /** @var Node\FunctionLike $fn */
            foreach ($fn->getParams() as $param) {
                $type = $param->type;
                if ($type === null) continue;
                $typeName = match (true) {
                    $type instanceof Node\Name => $type->toString(),
                    $type instanceof Node\NullableType && $type->type instanceof Node\Name => $type->type->toString(),
                    default => null,
                };
                if ($typeName === null) continue;
                $isElggHook = $typeName === 'Elgg\\Hook' || ($typeName === 'Hook' && $hookImported);
                if (!$isElggHook) continue;

                $method = $fn instanceof Node\Stmt\Function_ ? $fn->name->toString()
                       : ($fn instanceof Node\Stmt\ClassMethod ? $fn->name->toString() : '<closure>');
                $hits[] = [
                    'line' => $param->getStartLine(),
                    'description' => sprintf(
                        "Parameter '%s \$%s' in %s() uses Elgg 4.x \\Elgg\\Hook type",
                        $typeName,
                        $param->var instanceof Node\Expr\Variable && is_string($param->var->name) ? $param->var->name : '?',
                        $method,
                    ),
                ];
            }
        }
        return $hits;
    }

    /**
     * Detector: top-level `'hooks' =>` key in elgg-plugin.php. 5.x merged hooks
     * and events under a single `'events' =>` key.
     */
    private function find_hooksKeyInElggPlugin(array $ast, array $pattern): array
    {
        // Only meaningful when the file is elgg-plugin.php at the root. The
        // scanner passes us every PHP file's AST though, so we recognise the
        // canonical shape: a top-level Return_ statement returning an Array_
        // with a string key 'hooks'. False positives on other files are negligible.
        $hits = [];
        foreach ($ast as $stmt) {
            if (!$stmt instanceof Node\Stmt\Return_) continue;
            if (!$stmt->expr instanceof Node\Expr\Array_) continue;
            foreach ($stmt->expr->items as $item) {
                if (!$item instanceof Node\Expr\ArrayItem) continue;
                if (!$item->key instanceof Node\Scalar\String_) continue;
                if ($item->key->value !== 'hooks') continue;
                $hits[] = [
                    'line' => $item->getStartLine(),
                    'description' => "Top-level 'hooks' key in elgg-plugin.php — 5.x merged hooks/events under 'events'",
                ];
            }
        }
        return $hits;
    }

    /**
     * Functions removed/deprecated at the 4.x → 5.x boundary that need
     * rewriting. Conservative list; extend as new gaps surface.
     */
    private const REMOVED_IN_5X = [
        'elgg_register_plugin_hook_handler' => 'Use elgg_register_event_handler($event, $type, $callback) or the events key in elgg-plugin.php',
        'elgg_unregister_plugin_hook_handler' => 'Use elgg_unregister_event_handler',
        'elgg_trigger_plugin_hook' => 'Use elgg_trigger_event_results / elgg_trigger_before_event / elgg_trigger_after_event',
        'elgg_clear_plugin_hook_handlers' => 'Use elgg_clear_event_handlers',
    ];

    /**
     * @param array<Node\Stmt> $ast
     * @return array<int, array{line:int, description:string}>
     */
    private function find_removedIn5xFunctionCall(array $ast, array $pattern): array
    {
        $finder = new NodeFinder();
        $hits = [];
        foreach ($finder->findInstanceOf($ast, Node\Expr\FuncCall::class) as $call) {
            assert($call instanceof Node\Expr\FuncCall);
            if (!$call->name instanceof Node\Name) continue;
            $name = $call->name->toString();
            if (!isset(self::REMOVED_IN_5X[$name])) continue;
            $hits[] = [
                'line' => $call->getStartLine(),
                'description' => sprintf("Call to '%s()' — removed in Elgg 5.x. %s", $name, self::REMOVED_IN_5X[$name]),
            ];
        }
        return $hits;
    }

    /**
     * Generic, data-driven removed-function detector. Reads the target major's
     * block from references/removed-functions.json (the single source of truth
     * shared with PostMigrationVerifier) so a step's leftover check needs only
     * a manifest entry, not a hand-maintained constant table. Per-major (not
     * cumulative): flags calls to functions removed AT $pattern['target'],
     * matching the semantics of the version-specific detectors above.
     *
     * @param array<Node\Stmt> $ast
     * @param array{target?:string} $pattern
     * @return array<int, array{line:int, description:string}>
     */
    private function find_removedFunctionForTarget(array $ast, array $pattern): array
    {
        $target = $pattern['target'] ?? '';
        $removed = self::removedFunctionsForMajor($target);
        if (empty($removed)) {
            return [];
        }

        $finder = new NodeFinder();
        $hits = [];
        foreach ($finder->findInstanceOf($ast, Node\Expr\FuncCall::class) as $call) {
            assert($call instanceof Node\Expr\FuncCall);
            if (!$call->name instanceof Node\Name) continue;
            $name = $call->name->toString();
            if (!isset($removed[$name])) continue;
            $hits[] = [
                'line' => $call->getStartLine(),
                'description' => sprintf("Call to '%s()' — removed in Elgg %s. Use: %s", $name, $target, $removed[$name]),
            ];
        }
        return $hits;
    }

    /**
     * Load exactly one major's removed-function map from
     * references/removed-functions.json (e.g. '7.x' → its block only). Bare
     * constants (no parens) present in the JSON for documentation are skipped —
     * they are not function calls.
     *
     * @return array<string, string>
     */
    private static function removedFunctionsForMajor(string $version): array
    {
        $path = __DIR__ . '/../references/removed-functions.json';
        if (!is_file($path)) {
            return [];
        }
        $data = json_decode((string) file_get_contents($path), true);
        if (!is_array($data) || !isset($data[$version]) || !is_array($data[$version])) {
            return [];
        }
        $map = [];
        foreach ($data[$version] as $fn => $replacement) {
            // A bare constant (documented for completeness) has no call shape.
            if (preg_match('/^[A-Z][A-Z0-9_]+$/', (string) $fn)) {
                continue;
            }
            // Static/instance method forms (Class::method) aren't FuncCall nodes.
            if (str_contains((string) $fn, '::')) {
                continue;
            }
            $map[$fn] = (string) $replacement;
        }
        return $map;
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

    /**
     * True when composer.json declares a require on elgg/elgg whose major is 7.
     *
     * Constraint spellings vary ("~7.0.0", "^7.0", ">=7.0 <8.0", "7.*"); the first
     * integer in the constraint is the major in every form Composer accepts here.
     */
    private function hasElgg7Constraint(string $path): bool
    {
        return $this->elggMajorConstraint($path) === 7;
    }

    /**
     * Major version from composer.json's require."elgg/elgg" constraint, or null
     * when there is no composer.json, no such require, or no parseable major.
     */
    private function elggMajorConstraint(string $path): ?int
    {
        $composer = $path . '/composer.json';
        if (!is_file($composer)) {
            return null;
        }
        $data = json_decode((string) file_get_contents($composer), true);
        if (!is_array($data)) {
            return null;
        }
        $constraint = $data['require']['elgg/elgg'] ?? null;
        if (!is_string($constraint) || !preg_match('/(\d+)/', $constraint, $m)) {
            return null;
        }
        return (int) $m[1];
    }

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
