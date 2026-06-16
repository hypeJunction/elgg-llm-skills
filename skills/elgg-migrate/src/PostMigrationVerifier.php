<?php

declare(strict_types=1);

namespace ElggMigrate;

/**
 * Scans migrated plugin code for API usage that belongs to versions
 * beyond the migration target. Catches the most common consistency
 * failure: subagents applying future-version patterns.
 *
 * Each version boundary defines:
 * - Functions that only exist in that version or later
 * - Type hints that only exist in that version or later
 * - Config patterns that only exist in that version or later
 * - Files that should NOT exist at that version
 */
final class PostMigrationVerifier
{
    /**
     * Version-specific API boundaries.
     *
     * Key = version where the API was INTRODUCED.
     * If target is 4.x, any usage from 5.x+ boundaries is a violation.
     */
    private const VERSION_BOUNDARIES = [
        '6.x' => [
            // ES module loading is 6.x-only (references/version-api-boundaries.md).
            // AMD (elgg_define_js/elgg_require_js) is the ≤5.x equivalent. Seeing these
            // on a 3.x/4.x/5.x branch means an AMD→ESM sweep leaked a future API down —
            // exactly the chain-contamination case (bd elgg-migrate-xs2g6).
            'functions' => [
                'elgg_import_esm',
                'elgg_register_esm',
            ],
            'type_hints' => [],
            'config_patterns' => [],
            'forbidden_files' => [],
        ],
        '5.x' => [
            // elgg_trigger_event_results is the only unambiguously 5.x-only function name.
            // elgg_register_event_handler / elgg_unregister_event_handler exist in 3.x and 4.x
            // for genuine events (create/object, init/system) — they are only wrong when used
            // with hook names. That's checked contextually in check4xHookEventConfusion.
            //
            // \Elgg\Event has existed since 3.x for typed event handlers. In 5.x it also subsumes
            // hooks. We cannot distinguish without parsing the registration context, so we rely
            // on the hook-event confusion check on elgg-plugin.php structure instead.
            'functions' => [
                'elgg_trigger_event_results',
            ],
            'type_hints' => [],
            'config_patterns' => [],
            'forbidden_files' => [],
        ],
        '4.x' => [
            // elgg_-prefixed renames that do NOT exist in 3.x (the elgg_ prefixing
            // initiative landed in 4.x). 3.x code must use the unprefixed forms:
            //   elgg_get_current_language()    -> get_current_language()
            //   elgg_register_error_message()  -> register_error()
            //   elgg_register_success_message()-> system_message()
            // See references/breaking-changes/overview.md "Function Renames".
            'functions' => [
                'elgg_get_current_language',
                'elgg_register_error_message',
                'elgg_register_success_message',
                // elgg_string_to_array() lives in engine/lib/input.php from 4.x; in 3.x
                // the equivalent is string_to_tag_array().
                'elgg_string_to_array',
                // The capability system (and this lookup) is 4.x+; 3.x has no
                // 'capabilities' entity registration to query.
                'elgg_entity_types_with_capability',
            ],
            'type_hints' => [],
            'config_patterns' => [],
            'forbidden_files' => [
                'start.php',
                'activate.php',
                'deactivate.php',
            ],
        ],
        '3.x' => [
            'functions' => [
                'elgg_generate_url',
                'elgg_register_route',
            ],
            'type_hints' => [],
            'config_patterns' => [],
            'forbidden_files' => [],
        ],
    ];

    /**
     * Functions that are DEPRECATED but still WORK in a given version.
     * These should NOT be flagged as violations — they're the correct
     * choice when targeting that version.
     */
    private const DEPRECATED_BUT_VALID = [
        '4.x' => [
            'elgg_trigger_plugin_hook',
            'elgg_register_plugin_hook_handler',
            'elgg_unregister_plugin_hook_handler',
            'elgg_clear_plugin_hook_handlers',
        ],
        '3.x' => [
            'elgg_register_page_handler',
            'elgg_register_library',
            'elgg_load_library',
        ],
    ];

    /**
     * Hooks vs events distinction in 4.x.
     * In 4.x, these are HOOKS (not events). Using elgg_register_event_handler
     * for these is a 5.x pattern applied to 4.x code.
     */
    private const HOOKS_IN_4X = [
        'view',
        'view_vars',
        'register',       // menu hooks
        'prepare',         // menu hooks
        'route',
        'route:rewrite',
        'permissions_check',
        'permissions_check:comment',
        'container_permissions_check',
        'container_logic_check',
        'access:collections:write',
        'setting',
        'plugin_setting',
        'search:fields',
        'action:validate',
        'output',
        'entity:icon:url',
        'entity:url',
    ];

    /**
     * Verify a plugin doesn't use APIs from versions beyond the target.
     *
     * @param string $pluginPath Path to the migrated plugin
     * @param string $targetVersion The version being migrated TO (e.g., '4.x')
     * @return VerificationResult
     */
    public function verify(string $pluginPath, string $targetVersion): VerificationResult
    {
        $violations = [];

        // Determine which version boundaries to check against
        $futureVersions = $this->getFutureVersions($targetVersion);

        foreach ($futureVersions as $futureVersion) {
            $boundary = self::VERSION_BOUNDARIES[$futureVersion] ?? null;
            if ($boundary === null) {
                continue;
            }

            // Check for future-version function usage
            $violations = array_merge(
                $violations,
                $this->checkFunctions($pluginPath, $boundary['functions'], $futureVersion),
            );

            // Check for future-version type hints
            $violations = array_merge(
                $violations,
                $this->checkTypeHints($pluginPath, $boundary['type_hints'], $futureVersion),
            );
        }

        // Check for files that should NOT exist at the TARGET version
        $targetBoundary = self::VERSION_BOUNDARIES[$targetVersion] ?? null;
        if ($targetBoundary !== null) {
            $violations = array_merge(
                $violations,
                $this->checkForbiddenFiles($pluginPath, $targetBoundary['forbidden_files'], $targetVersion),
            );
        }

        // Version-specific contextual checks
        if ($targetVersion === '4.x') {
            $violations = array_merge($violations, $this->check4xHookEventConfusion($pluginPath));
            $violations = array_merge($violations, $this->check4xConfigKeys($pluginPath));
        }

        if ($targetVersion === '3.x') {
            $violations = array_merge($violations, $this->check3xStartPhpExists($pluginPath));
        }

        // Smoke-test scaffold check applies to every target version that supports
        // \Elgg\IntegrationTestCase (3.x onwards). Reported as a warning so it
        // surfaces in output without breaking the gate for plugins that haven't
        // been re-scaffolded yet — the retroactive sweep (elgg-migrate-b6vax)
        // promotes this to required once every plugin has been swept.
        $violations = array_merge($violations, $this->checkSmokeTestScaffold($pluginPath));

        // Calls to functions REMOVED at (or before) the target version — the
        // "undefined symbol at runtime" class that the shape-based completeness
        // gate is blind to (bd elgg-migrate-abyju). Data-driven from
        // references/removed-functions.json.
        $violations = array_merge($violations, $this->checkRemovedFunctions($pluginPath, $targetVersion));

        // Core types whose KIND changed across a major (interface -> abstract class,
        // etc.). The type still exists, so checkRemovedFunctions() is blind to it, and
        // the shape gate never sees it — but `implements X` fatals on boot once X is
        // no longer an interface. Data-driven from references/changed-class-contracts.json.
        // (bd elgg-migrate — caught by verify-migration-chain.sh at 5x->6x, 2026-06-05).
        $violations = array_merge($violations, $this->checkChangedClassContracts($pluginPath, $targetVersion));

        // Upgrade classes registered under elgg-plugin.php 'upgrades' => [...]
        // whose class no longer resolves to a file. A bare `Foo::class` on an
        // undefined class does NOT autoload, so the registration loads cleanly and
        // pages render — but `elgg-cli upgrade` aborts non-zero ("Upgrade class …
        // was not found", Locator.php). A forward-port that deleted the class but
        // left the registration is the canonical trigger.
        // (bd elgg-migrate-kg3kb; version-agnostic — checked at every target.)
        $violations = array_merge($violations, $this->checkDanglingUpgradeClasses($pluginPath));

        $errors = array_filter($violations, fn(Violation $v) => $v->severity === 'error');

        return new VerificationResult(
            targetVersion: $targetVersion,
            violations: $violations,
            passed: count($errors) === 0,
        );
    }

    /**
     * Flag calls to global functions REMOVED at (or before) the target major.
     *
     * This is the dual of the future-version check: where checkFunctions()
     * catches APIs from the FUTURE leaking backward, this catches legacy APIs
     * that were removed and would fatal with "Call to undefined function" at
     * runtime on the target core. The shape-based completeness gate
     * (VersionGuard::detectIncompletePatterns) is blind to this class.
     *
     * Data-driven from references/removed-functions.json. The map is treated
     * cumulatively: a function removed at 4.x is still removed at 6.x.
     *
     * @return array<Violation>
     */
    private function checkRemovedFunctions(string $pluginPath, string $targetVersion): array
    {
        $removed = $this->removedFunctionsFor($targetVersion);
        if (empty($removed)) {
            return [];
        }

        $names = array_keys($removed);
        $pattern = '/(?<![\w>$:\\\\])(' . implode('|', array_map('preg_quote', $names)) . ')\s*\(/';

        $violations = [];
        foreach ($this->phpFiles($pluginPath) as $file) {
            $content = file_get_contents($file);
            if ($content === false) continue;

            $relativePath = $this->relativePath($pluginPath, $file);
            foreach (explode("\n", $content) as $lineNum => $line) {
                $trimmed = ltrim($line);
                // Skip comment lines (best-effort) — a removed name mentioned in
                // a docblock or // comment is not a live call.
                if ($trimmed === '' || $trimmed[0] === '*' || str_starts_with($trimmed, '//') || str_starts_with($trimmed, '#')) {
                    continue;
                }
                if (preg_match($pattern, $line, $m)) {
                    $func = $m[1];
                    // Guard against method calls / definitions the lookbehind
                    // can't fully exclude (e.g. "function forward(").
                    if (preg_match('/(?:->|::|function)\s*' . preg_quote($func, '/') . '\s*\(/', $line)) {
                        continue;
                    }
                    $replacement = $removed[$func];
                    $violations[] = new Violation(
                        file: $relativePath,
                        line: $lineNum + 1,
                        severity: 'error',
                        message: "{$func}() was removed in Elgg {$targetVersion} — use: {$replacement}",
                        code: trim($line),
                        category: 'removed-function',
                    );
                }
            }
        }

        return $violations;
    }

    /**
     * Flag classes that `implements` a core type whose KIND changed at (or
     * before) the target major — e.g. Elgg\Upgrade\Batch, an interface in
     * 3.x-5.x, became an abstract class in 6.x. `implements Batch` then fatals
     * on boot ("cannot implement Elgg\Upgrade\Batch - it is not an interface"),
     * but the type still EXISTS so checkRemovedFunctions() and the shape gate
     * are both blind to it. Data-driven from references/changed-class-contracts.json.
     *
     * Detection is conservative: the file must both reference the type (a
     * `use <FQN>;` import of its short name, or the inline FQN) AND use the
     * illegal keyword (`implements`) against that name. This avoids flagging an
     * unrelated local class that happens to be called `Batch`.
     *
     * @return array<Violation>
     */
    private function checkChangedClassContracts(string $pluginPath, string $targetVersion): array
    {
        $contracts = $this->changedContractsFor($targetVersion);
        if (empty($contracts)) {
            return [];
        }

        $violations = [];
        foreach ($this->phpFiles($pluginPath) as $file) {
            $content = file_get_contents($file);
            if ($content === false) {
                continue;
            }
            $relativePath = $this->relativePath($pluginPath, $file);
            $lines = explode("\n", $content);

            foreach ($contracts as $fqn => $info) {
                $short = ltrim((string) strrchr('\\' . $fqn, '\\'), '\\');
                $fqnEscaped = preg_quote(ltrim($fqn, '\\'), '/');
                $shortEscaped = preg_quote($short, '/');
                $keyword = preg_quote((string) ($info['illegal_keyword'] ?? 'implements'), '/');

                // Is the type referenced in this file by import or inline FQN?
                $imported = (bool) preg_match('/use\s+\\\\?' . $fqnEscaped . '\s*;/', $content);
                $usesFqnInline = (bool) preg_match('/\\\\?' . $fqnEscaped . '\b/', $content);
                if (!$imported && !$usesFqnInline) {
                    continue;
                }

                foreach ($lines as $lineNum => $line) {
                    // `implements ... Batch` (short name, valid only when imported)
                    // or `implements ... \Elgg\Upgrade\Batch` (inline FQN).
                    $hitShort = $imported && preg_match('/\b' . $keyword . '\b[^{]*\b' . $shortEscaped . '\b/', $line);
                    $hitFqn = preg_match('/\b' . $keyword . '\b[^{]*\\\\?' . $fqnEscaped . '\b/', $line);
                    if ($hitShort || $hitFqn) {
                        $violations[] = new Violation(
                            file: $relativePath,
                            line: $lineNum + 1,
                            severity: 'error',
                            message: "{$fqn} was a(n) {$info['was']} but is a(n) {$info['now']} in Elgg {$targetVersion} — {$info['fix']}",
                            code: trim($line),
                            category: 'changed-class-contract',
                        );
                    }
                }
            }
        }

        return $violations;
    }

    /**
     * Cumulative map of contract-changed core types for a target version: every
     * data entry whose major <= the target major.
     *
     * @return array<string,array<string,string>>  FQN => {was, now, illegal_keyword, fix}
     */
    private function changedContractsFor(string $targetVersion): array
    {
        $path = __DIR__ . '/../references/changed-class-contracts.json';
        if (!is_file($path)) {
            return [];
        }
        $data = json_decode((string) file_get_contents($path), true);
        if (!is_array($data)) {
            return [];
        }
        $targetMajor = (int) $targetVersion;
        $map = [];
        foreach ($data as $version => $entries) {
            if (!is_array($entries) || !preg_match('/^\d/', (string) $version)) {
                continue; // skip _meta and malformed keys
            }
            if ((int) $version <= $targetMajor) {
                foreach ($entries as $fqn => $info) {
                    if (is_array($info)) {
                        $map[$fqn] = $info;
                    }
                }
            }
        }
        return $map;
    }

    /**
     * Build the cumulative removed-function map for a target version: union of
     * every data entry whose major version is <= the target major.
     *
     * @return array<string,string>  removed function name => replacement hint
     */
    private function removedFunctionsFor(string $targetVersion): array
    {
        $path = __DIR__ . '/../references/removed-functions.json';
        if (!is_file($path)) {
            return [];
        }
        $data = json_decode((string) file_get_contents($path), true);
        if (!is_array($data)) {
            return [];
        }
        $targetMajor = (int) $targetVersion;
        $map = [];
        foreach ($data as $version => $entries) {
            if (!is_array($entries) || !preg_match('/^\d/', (string) $version)) {
                continue; // skip _meta and malformed keys
            }
            if ((int) $version <= $targetMajor) {
                foreach ($entries as $fn => $replacement) {
                    $map[$fn] = (string) $replacement;
                }
            }
        }
        return $map;
    }

    /**
     * Verify the plugin ships an auto-scaffolded SmokeTest.php at the canonical
     * location. The scaffold is produced by
     * skills/elgg-test-writer/bin/scaffold-smoke-tests.sh.
     *
     * @return array<Violation>
     */
    private function checkSmokeTestScaffold(string $pluginPath): array
    {
        $smoke = $pluginPath . '/tests/phpunit/integration/SmokeTest.php';
        if (is_file($smoke)) {
            return [];
        }
        return [
            new Violation(
                file: 'tests/phpunit/integration/SmokeTest.php',
                line: 0,
                severity: 'warning',
                message: 'No baseline smoke test found. Run skills/elgg-test-writer/bin/scaffold-smoke-tests.sh from the plugin directory to generate one.',
                code: '',
                category: 'smoke-test-scaffold',
            ),
        ];
    }

    /**
     * Get all version strings that are BEYOND the target.
     *
     * @return array<string>
     */
    private function getFutureVersions(string $target): array
    {
        $allVersions = ['3.x', '4.x', '5.x', '6.x', '7.x'];
        $targetIndex = array_search($target, $allVersions, true);

        if ($targetIndex === false) {
            return [];
        }

        return array_slice($allVersions, $targetIndex + 1);
    }

    /**
     * Scan PHP files for function calls that belong to a future version.
     *
     * @return array<Violation>
     */
    private function checkFunctions(string $pluginPath, array $functions, string $introducedIn): array
    {
        if (empty($functions)) {
            return [];
        }

        $violations = [];
        $pattern = '/\b(' . implode('|', array_map('preg_quote', $functions)) . ')\s*\(/';

        foreach ($this->phpFiles($pluginPath) as $file) {
            $content = file_get_contents($file);
            if ($content === false) continue;

            $relativePath = $this->relativePath($pluginPath, $file);
            $lines = explode("\n", $content);

            foreach ($lines as $lineNum => $line) {
                if (preg_match($pattern, $line, $matches)) {
                    $funcName = $matches[1];
                    $violations[] = new Violation(
                        file: $relativePath,
                        line: $lineNum + 1,
                        severity: 'error',
                        message: "{$funcName}() is only available from Elgg {$introducedIn}+",
                        code: trim($line),
                        category: 'future-version-api',
                    );
                }
            }
        }

        return $violations;
    }

    /**
     * Scan PHP files for type hints that belong to a future version.
     *
     * @return array<Violation>
     */
    private function checkTypeHints(string $pluginPath, array $typeHints, string $introducedIn): array
    {
        if (empty($typeHints)) {
            return [];
        }

        $violations = [];

        foreach ($this->phpFiles($pluginPath) as $file) {
            $content = file_get_contents($file);
            if ($content === false) continue;

            $relativePath = $this->relativePath($pluginPath, $file);
            $lines = explode("\n", $content);

            foreach ($typeHints as $typeHint) {
                // Normalize: \Elgg\Event → Elgg\\Event for pattern matching
                $escaped = preg_quote(ltrim($typeHint, '\\'), '/');
                // Match in use statements, type hints, instanceof checks
                $pattern = '/(?:use\s+|\\\\|instanceof\s+)' . $escaped . '\b/';

                foreach ($lines as $lineNum => $line) {
                    if (preg_match($pattern, $line)) {
                        $violations[] = new Violation(
                            file: $relativePath,
                            line: $lineNum + 1,
                            severity: 'error',
                            message: "{$typeHint} is only available from Elgg {$introducedIn}+",
                            code: trim($line),
                            category: 'future-version-type',
                        );
                    }
                }
            }
        }

        return $violations;
    }

    /**
     * Check that files which should have been removed at the target version don't exist.
     *
     * @return array<Violation>
     */
    private function checkForbiddenFiles(string $pluginPath, array $files, string $targetVersion): array
    {
        $violations = [];

        foreach ($files as $file) {
            if (is_file($pluginPath . '/' . $file)) {
                $violations[] = new Violation(
                    file: $file,
                    line: 0,
                    severity: 'error',
                    message: "{$file} must not exist in an Elgg {$targetVersion} plugin",
                    code: '',
                    category: 'forbidden-file',
                );
            }
        }

        return $violations;
    }

    /**
     * In 4.x, view/view_vars/register/prepare are HOOKS, not events.
     * Using elgg_register_event_handler() for these is a 5.x pattern.
     */
    private function check4xHookEventConfusion(string $pluginPath): array
    {
        $violations = [];
        $pluginPhp = $pluginPath . '/elgg-plugin.php';

        if (!is_file($pluginPhp)) {
            return [];
        }

        $content = file_get_contents($pluginPhp);
        if ($content === false) {
            return [];
        }

        // Check if hook-type registrations are under 'events' key instead of 'hooks'
        // This is a structural check on elgg-plugin.php
        $lines = explode("\n", $content);
        $inEventsBlock = false;
        // Top-level elgg-plugin.php keys that are siblings of 'events'. Hitting any
        // of these means the 'events' array has ended — reset the flag so hook names
        // in a later 'hooks' (or other) block are not false-flagged.
        $siblingKeys = ['hooks', 'actions', 'routes', 'entities', 'views', 'view_extensions',
            'view_options', 'widgets', 'group_tools', 'notifications', 'plugin', 'bootstrap', 'upgrades', 'settings'];

        foreach ($lines as $lineNum => $line) {
            if (preg_match("/['\"]events['\"]\s*=>/", $line)) {
                $inEventsBlock = true;
            } elseif ($inEventsBlock) {
                foreach ($siblingKeys as $sk) {
                    if (preg_match("/['\"]" . preg_quote($sk, '/') . "['\"]\s*=>/", $line)) {
                        $inEventsBlock = false;
                        break;
                    }
                }
            }

            if ($inEventsBlock) {
                foreach (self::HOOKS_IN_4X as $hookName) {
                    $escaped = preg_quote($hookName, '/');
                    if (preg_match("/['\"]" . $escaped . "['\"]\s*=>/", $line)) {
                        $violations[] = new Violation(
                            file: 'elgg-plugin.php',
                            line: $lineNum + 1,
                            severity: 'error',
                            message: "'{$hookName}' is a HOOK in Elgg 4.x, not an event. Move from 'events' to 'hooks' key.",
                            code: trim($line),
                            category: 'hook-event-confusion',
                        );
                    }
                }
            }
        }

        // Check PHP code for elgg_register_event_handler used with KNOWN HOOK names.
        // The function itself is valid in 4.x for events like create/object, init/system.
        // It's only a violation when used with hook-type names (view, register, prepare, etc).
        $hookNamesPattern = "'(?:" . implode('|', array_map(
            fn($h) => preg_quote($h, '/'),
            self::HOOKS_IN_4X
        )) . ")'";

        foreach ($this->phpFiles($pluginPath) as $file) {
            if (basename($file) === 'elgg-plugin.php') continue;

            $code = file_get_contents($file);
            if ($code === false) continue;

            $relativePath = $this->relativePath($pluginPath, $file);
            $codeLines = explode("\n", $code);

            foreach ($codeLines as $lineNum => $codeLine) {
                // Only flag elgg_register_event_handler when it's called with a hook name
                if (preg_match("/elgg_register_event_handler\s*\(\s*{$hookNamesPattern}/", $codeLine)) {
                    $violations[] = new Violation(
                        file: $relativePath,
                        line: $lineNum + 1,
                        severity: 'error',
                        message: 'elgg_register_event_handler() called with a hook name. In Elgg 4.x these are HOOKS, not events. Use elgg_register_plugin_hook_handler().',
                        code: trim($codeLine),
                        category: 'hook-event-confusion',
                    );
                }
            }
        }

        return $violations;
    }

    /**
     * In 4.x, elgg-plugin.php should have a 'hooks' key (not only 'events').
     */
    private function check4xConfigKeys(string $pluginPath): array
    {
        $violations = [];
        $pluginPhp = $pluginPath . '/elgg-plugin.php';

        if (!is_file($pluginPhp)) {
            return [];
        }

        $content = file_get_contents($pluginPhp);
        if ($content === false) {
            return [];
        }

        // If there's an 'events' key but no 'hooks' key, warn about 5.x-style config
        $hasEvents = (bool) preg_match("/['\"]events['\"]\s*=>/", $content);
        $hasHooks = (bool) preg_match("/['\"]hooks['\"]\s*=>/", $content);

        if ($hasEvents && !$hasHooks) {
            $violations[] = new Violation(
                file: 'elgg-plugin.php',
                line: 0,
                severity: 'warning',
                message: "elgg-plugin.php has 'events' key but no 'hooks' key. In Elgg 4.x, hooks and events are separate. Verify hook registrations aren't missing.",
                code: '',
                category: 'config-structure',
            );
        }

        return $violations;
    }

    /**
     * In 3.x, start.php should still exist (it's removed in 4.x).
     */
    private function check3xStartPhpExists(string $pluginPath): array
    {
        // In 3.x, it's valid to have start.php — it's the 4.x migration that removes it
        // But if the plugin has elgg-plugin.php without start.php, that's a 4.x pattern
        if (is_file($pluginPath . '/elgg-plugin.php') && !is_file($pluginPath . '/start.php')) {
            return [new Violation(
                file: 'start.php',
                line: 0,
                severity: 'warning',
                message: 'Plugin has elgg-plugin.php but no start.php. This is a 4.x pattern — in 3.x, start.php should still exist (returning a closure).',
                code: '',
                category: 'missing-file',
            )];
        }

        return [];
    }

    /**
     * Flag upgrade classes registered under elgg-plugin.php 'upgrades' => [...]
     * that no longer resolve to a file in the plugin.
     *
     * Elgg loads the registration (a class-string) lazily; `\Foo::class` on an
     * undefined class is just a string literal and does NOT trigger autoloading.
     * So a stale registration looks fine — pages render — until `elgg-cli upgrade`
     * runs and the Locator fails to resolve the class ("Upgrade class … was not
     * found"), aborting non-zero. This catches the whole class of forward-port
     * gaps where a class was deleted/renamed but the registration stayed.
     *
     * Conservative resolution: a class is considered present if EITHER the
     * canonical Elgg classes/ path exists OR a declaration of its short name is
     * found anywhere in the plugin's PHP (covers composer PSR-4 with a custom
     * prefix or a relocated file). Only when neither holds do we flag it.
     *
     * @return array<Violation>
     */
    private function checkDanglingUpgradeClasses(string $pluginPath): array
    {
        $pluginPhp = $pluginPath . '/elgg-plugin.php';
        if (!is_file($pluginPhp)) {
            return [];
        }
        $content = file_get_contents($pluginPhp);
        if ($content === false) {
            return [];
        }

        $violations = [];
        foreach ($this->extractUpgradeClassRefs($content) as [$class, $lineNum]) {
            if ($this->classResolvesInPlugin($pluginPath, $class)) {
                continue;
            }
            $violations[] = new Violation(
                file: 'elgg-plugin.php',
                line: $lineNum,
                severity: 'error',
                message: "Registered upgrade class {$class} cannot be resolved to a file — `elgg-cli upgrade` aborts non-zero (\"Upgrade class … was not found\"). Remove the stale registration or restore/rename the class.",
                code: $class,
                category: 'dangling-upgrade-class',
            );
        }

        return $violations;
    }

    /**
     * Extract the class-strings registered under the 'upgrades' key of
     * elgg-plugin.php, with the 1-based line each was found on. Tracks bracket
     * depth from the opening `'upgrades' => [` so nested arrays don't end the
     * block early.
     *
     * @return array<array{0:string,1:int}>
     */
    private function extractUpgradeClassRefs(string $content): array
    {
        $lines = explode("\n", $content);
        $refs = [];
        $inBlock = false;
        $depth = 0;

        foreach ($lines as $i => $line) {
            if (!$inBlock) {
                if (!preg_match("/['\"]upgrades['\"]\s*=>\s*\[/", $line)) {
                    continue;
                }
                $inBlock = true;
                $depth = 0;
            }

            $depth += substr_count($line, '[') - substr_count($line, ']');
            foreach ($this->classRefsOnLine($line) as $class) {
                $refs[] = [$class, $i + 1];
            }
            if ($depth <= 0) {
                $inBlock = false;
            }
        }

        return $refs;
    }

    /**
     * Pull namespaced class references out of a single source line: both
     * `\Foo\Bar::class` constants and `'Foo\Bar'` / "Foo\\Bar" string literals.
     * A namespace separator is required, so plain array keys aren't matched.
     *
     * @return array<string>
     */
    private function classRefsOnLine(string $line): array
    {
        // Collapse runs of backslashes to one so double-quoted ("Foo\\Bar") and
        // single-quoted ('Foo\Bar') source forms normalize identically.
        $norm = preg_replace('/\\\\+/', '\\', $line);

        $classes = [];
        if (preg_match_all('/\\\\?([A-Za-z_]\w*(?:\\\\[A-Za-z_]\w*)+)::class/', $norm, $m)) {
            $classes = array_merge($classes, $m[1]);
        }
        if (preg_match_all('/[\'"]\\\\?([A-Za-z_]\w*(?:\\\\[A-Za-z_]\w*)+)[\'"]/', $norm, $m2)) {
            $classes = array_merge($classes, $m2[1]);
        }

        return array_values(array_unique($classes));
    }

    /**
     * Conservatively decide whether a class-string resolves to a file shipped by
     * the plugin (canonical classes/ path, or any matching class declaration).
     */
    private function classResolvesInPlugin(string $pluginPath, string $class): bool
    {
        $class = ltrim($class, '\\');

        // Canonical Elgg autoload location: classes/<Ns/Path>.php
        $canonical = $pluginPath . '/classes/' . str_replace('\\', '/', $class) . '.php';
        if (is_file($canonical)) {
            return true;
        }

        // Fallback: a declaration of the short name anywhere in the plugin (a
        // composer custom PSR-4 prefix or a relocated file). Keeps false positives
        // near zero at the cost of not catching a same-named class in a wrong ns.
        $short = ltrim((string) strrchr('\\' . $class, '\\'), '\\');
        $declPattern = '/\b(?:abstract\s+|final\s+)?class\s+' . preg_quote($short, '/') . '\b/';
        foreach ($this->phpFiles($pluginPath) as $file) {
            $content = file_get_contents($file);
            if ($content === false) {
                continue;
            }
            if (preg_match($declPattern, $content)) {
                return true;
            }
        }

        return false;
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

    private function relativePath(string $base, string $path): string
    {
        $base = rtrim($base, '/') . '/';
        if (str_starts_with($path, $base)) {
            return substr($path, strlen($base));
        }
        return $path;
    }
}
