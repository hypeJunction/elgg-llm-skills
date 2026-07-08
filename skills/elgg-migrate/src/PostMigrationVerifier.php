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

        // ---------------------------------------------------------------
        // Failure-class detectors (FC-*). Each closes a static-gate blind
        // spot catalogued in references/migration-failure-catalog.md and
        // .wolf/{cerebrum,buglog}. Gated by the TARGET major so a pattern
        // is only flagged from the version where it first bites. Data-driven
        // off references/removed-functions.json where the shape allows.
        // ---------------------------------------------------------------
        $major = (int) $targetVersion;

        // Version-agnostic (apply at every target).
        $violations = array_merge($violations, $this->checkComposerVersionField($pluginPath));            // composer 'version' shadows git tag
        $violations = array_merge($violations, $this->checkDocblockTerminator($pluginPath));              // doubled '*/' docblock close
        $violations = array_merge($violations, $this->checkLegacyHandlerSignature($pluginPath));          // FC-3x4x-12 / FC-ALL-04
        $violations = array_merge($violations, $this->checkRouteRewriteTiming($pluginPath));              // FC-ALL-05
        $violations = array_merge($violations, $this->checkElggPluginSideEffects($pluginPath));           // FC-ALL-06
        $violations = array_merge($violations, $this->checkLibFunctionsAutoload($pluginPath));            // FC-ALL-07
        $violations = array_merge($violations, $this->checkUnguardedOptionalDeps($pluginPath));           // FC-ALL-08

        if ($major >= 3) {
            $violations = array_merge($violations, $this->checkSearchHookReturn($pluginPath));            // FC-2x3x-03
            $violations = array_merge($violations, $this->checkSiteSecretScrub($pluginPath));             // FC-2x3x-04
        }

        if ($major >= 4) {
            $violations = array_merge($violations, $this->checkCamelCasePluginIds($pluginPath));          // FC-3x4x-10
            $violations = array_merge($violations, $this->checkDetectMimeTypeInstance($pluginPath));      // FC-3x4x-04
            $violations = array_merge($violations, $this->checkInstallSqlNotAutoRun($pluginPath));        // FC-3x4x-13
            $violations = array_merge($violations, $this->checkRelocatedSymbols($pluginPath));            // FC-3x4x-14
        }

        if ($major >= 5) {
            $violations = array_merge($violations, $this->check5xServiceRemovals($pluginPath));           // FC-4x5x-04 / FC-4x5x-05
            $violations = array_merge($violations, $this->check5xMenuJsApi($pluginPath));                 // FC-4x5x-06
            $violations = array_merge($violations, $this->check5xSubtypeAssignment($pluginPath));         // FC-4x5x-07
            $violations = array_merge($violations, $this->check5xTestMocking($pluginPath));               // FC-4x5x-08
        }

        if ($major >= 6) {
            $violations = array_merge($violations, $this->checkSeedAbstractMethods($pluginPath, $targetVersion)); // FC-5x6x-03
        }

        if ($major >= 7) {
            $violations = array_merge($violations, $this->checkRemovedConstants($pluginPath, $targetVersion));    // FC-6x7x-01
            $violations = array_merge($violations, $this->checkMenuAddValue($pluginPath));                // FC-6x7x-03
            $violations = array_merge($violations, $this->checkCssViewRelocation($pluginPath));           // FC-6x7x-05
            $violations = array_merge($violations, $this->checkEsmBareSpecifiers($pluginPath));           // FC-6x7x-06
            $violations = array_merge($violations, $this->checkJqueryGlobal($pluginPath));                // FC-6x7x-07
            $violations = array_merge($violations, $this->checkI18nNamedImport($pluginPath));             // FC-6x7x-08
            $violations = array_merge($violations, $this->checkEmptyFormatElement($pluginPath));          // FC-6x7x-09
            $violations = array_merge($violations, $this->checkDbalColonParams($pluginPath));             // FC-6x7x-10
            $violations = array_merge($violations, $this->checkCanWriteToContainerSubtype($pluginPath));  // FC-6x7x-11
            $violations = array_merge($violations, $this->checkUnbracedMethodInterpolation($pluginPath)); // FC-6x7x-12
            $violations = array_merge($violations, $this->checkAdminPasswordLength($pluginPath));         // FC-6x7x-13
        }

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

    // =====================================================================
    // Failure-class detectors
    // =====================================================================

    /**
     * FC-3x4x-10: camelCase plugin id at a callsite. Elgg 4.x lowercased every
     * plugin id; `elgg_get_plugin_from_id('CamelCase')` and
     * `elgg_get_plugin_setting($name, 'CamelCase')` then silently return false
     * (NOT the default), so settings reads go dark with no error.
     *
     * @return array<Violation>
     */
    private function checkCamelCasePluginIds(string $pluginPath): array
    {
        $violations = [];
        // elgg_get_plugin_from_id('CamelCase') — id is the FIRST arg.
        $fromId = '/elgg_get_plugin_from_id\s*\(\s*[\'"]([^\'"]*[A-Z][^\'"]*)[\'"]/';
        // elgg_get_plugin_setting($name, 'CamelCase') — id is the SECOND arg.
        $setting = '/elgg_get_plugin_(?:user_)?setting\s*\(\s*[\'"][^\'"]*[\'"]\s*,\s*[\'"]([^\'"]*[A-Z][^\'"]*)[\'"]/';

        foreach ($this->phpFiles($pluginPath) as $file) {
            $content = file_get_contents($file);
            if ($content === false) {
                continue;
            }
            $rel = $this->relativePath($pluginPath, $file);
            foreach (explode("\n", $content) as $i => $line) {
                if ($this->isCommentLine($line)) {
                    continue;
                }
                foreach ([$fromId, $setting] as $pattern) {
                    if (preg_match($pattern, $line, $m)) {
                        $violations[] = new Violation(
                            file: $rel,
                            line: $i + 1,
                            severity: 'error',
                            message: "Plugin id '{$m[1]}' is camelCase — Elgg 4.x+ lowercases plugin ids, so this callsite silently returns false. Lowercase it (e.g. '" . strtolower($m[1]) . "').",
                            code: trim($line),
                            category: 'camelcase-plugin-id',
                        );
                    }
                }
            }
        }
        return $violations;
    }

    /**
     * FC-3x4x-04: `$file->detectMimeType()` (instance form). ElggFile::detectMimeType
     * was removed in 4.x. The static form `ElggFile::detectMimeType(` is caught by the
     * call-shaped removed-functions gate; the instance `->detectMimeType(` form is not.
     *
     * @return array<Violation>
     */
    private function checkDetectMimeTypeInstance(string $pluginPath): array
    {
        return $this->regexScan(
            $pluginPath,
            ['php'],
            '/->\s*detectMimeType\s*\(/',
            'error',
            'ElggFile::detectMimeType() was removed in Elgg 4.x — use mime_content_type($path) guarded by is_file($path) (NOT file_exists, which is true for dirs); save the entity before writing bytes.',
            'removed-method',
        );
    }

    /**
     * FC-3x4x-14: `\Elgg\GatekeeperException` relocated to
     * `\Elgg\Exceptions\Http\GatekeeperException` in 4.x. The old FQN is a latent
     * fatal that only fires when the handler runs. The new FQN does not contain the
     * substring `Elgg\GatekeeperException`, so the match is unambiguous.
     *
     * @return array<Violation>
     */
    private function checkRelocatedSymbols(string $pluginPath): array
    {
        return $this->regexScan(
            $pluginPath,
            ['php'],
            '/\\\\?Elgg\\\\GatekeeperException\b/',
            'error',
            '\\Elgg\\GatekeeperException was relocated in Elgg 4.x — use \\Elgg\\Exceptions\\Http\\GatekeeperException in the import/catch.',
            'relocated-symbol',
        );
    }

    /**
     * FC-3x4x-13: an `install/mysql.sql` that no code executes. In 4.x
     * DefaultPluginBootstrap::activate() is a no-op — 4.x does NOT auto-run
     * install/mysql.sql the way 2.x/3.x did. Unless a Bootstrap::activate()
     * override executes the statements, the schema is silently never created.
     *
     * @return array<Violation>
     */
    private function checkInstallSqlNotAutoRun(string $pluginPath): array
    {
        $sql = $pluginPath . '/install/mysql.sql';
        if (!is_file($sql)) {
            return [];
        }
        foreach ($this->phpFiles($pluginPath) as $file) {
            $content = file_get_contents($file);
            if ($content === false) {
                continue;
            }
            // A Bootstrap that overrides activate() and runs statements.
            if (preg_match('/function\s+activate\s*\(/', $content)
                && preg_match('/execute(?:Statement|Query)|->query\s*\(|insertData|updateData/', $content)) {
                return [];
            }
        }
        return [new Violation(
            file: 'install/mysql.sql',
            line: 0,
            severity: 'warning',
            message: 'install/mysql.sql exists but no Bootstrap::activate() override executes it. Elgg 4.x+ does NOT auto-run install SQL (DefaultPluginBootstrap::activate is a no-op) — override activate() to prefix-swap and executeStatement() each statement.',
            code: '',
            category: 'install-sql-not-run',
        )];
    }

    /**
     * FC-3x4x-12 / FC-ALL-04: legacy multi-arg handler signatures. In 4.x+ every
     * hook/event handler takes a SINGLE object (\Elgg\Hook / \Elgg\Event). A 4-arg
     * `($hook, $type, $return, $params)` (3.x hook) or a 3-arg `($event, $type,
     * $object)` (2.x/3.x event) signature is always a leftover — and the 3-arg form
     * is the classic forward-port regression that resurrects on a higher branch.
     *
     * @return array<Violation>
     */
    private function checkLegacyHandlerSignature(string $pluginPath): array
    {
        $violations = [];
        $fourArg = '/function\s+\w+\s*\(\s*\$hook\s*,\s*\$type\s*,\s*\$(?:return|returnvalue|value|return_value)\s*,\s*\$params\s*\)/';
        $threeArg = '/function\s+\w+\s*\(\s*\$event\s*,\s*\$type\s*,\s*\$(?:object|entity|params|return)\s*\)/';

        foreach ($this->phpFiles($pluginPath) as $file) {
            $content = file_get_contents($file);
            if ($content === false) {
                continue;
            }
            $rel = $this->relativePath($pluginPath, $file);
            foreach (explode("\n", $content) as $i => $line) {
                if ($this->isCommentLine($line)) {
                    continue;
                }
                if (preg_match($fourArg, $line)) {
                    $violations[] = new Violation(
                        file: $rel, line: $i + 1, severity: 'error',
                        message: '3.x hook signature ($hook, $type, $return, $params) — 4.x+ handlers take a single \\Elgg\\Hook/\\Elgg\\Event object; use $event->getType()/getValue()/getParam().',
                        code: trim($line), category: 'legacy-handler-signature',
                    );
                } elseif (preg_match($threeArg, $line)) {
                    $violations[] = new Violation(
                        file: $rel, line: $i + 1, severity: 'error',
                        message: 'Legacy 3-arg event handler signature ($event, $type, $object) resurfaced — 4.x+ handlers take a single \\Elgg\\Event object. This is the classic forward-port regression.',
                        code: trim($line), category: 'legacy-handler-signature',
                    );
                }
            }
        }
        return $violations;
    }

    /**
     * FC-2x3x-03: a 'search' hook handler that returns ['entities' => ...] or null.
     * Elgg 3.0 rewrote search — a handler must return the output of elgg_search()
     * (or elgg_list_entities(..., 'elgg_search')). The old shape stops returning the
     * expected value and surfaces as a latent null TypeError, latent 3.x..7.x.
     *
     * @return array<Violation>
     */
    private function checkSearchHookReturn(string $pluginPath): array
    {
        $violations = [];
        foreach ($this->phpFiles($pluginPath) as $file) {
            $content = file_get_contents($file);
            if ($content === false) {
                continue;
            }
            // Only inspect files that actually register/handle a 'search' hook or type.
            if (!preg_match('/[\'"]search[\'"]/', $content)) {
                continue;
            }
            // A safe (migrated) handler calls elgg_search()/elgg_list_entities(...elgg_search).
            $migrated = preg_match('/elgg_search\s*\(|elgg_list_entities[^;]*elgg_search/', $content);
            if ($migrated) {
                continue;
            }
            $rel = $this->relativePath($pluginPath, $file);
            foreach (explode("\n", $content) as $i => $line) {
                if ($this->isCommentLine($line)) {
                    continue;
                }
                if (preg_match('/return\s+.*[\'"]entities[\'"]\s*=>/', $line)) {
                    $violations[] = new Violation(
                        file: $rel, line: $i + 1, severity: 'warning',
                        message: "Search handler returns ['entities' => ...] — Elgg 3.0 rewrote search; return elgg_search() / elgg_list_entities(..., 'elgg_search') instead (latent null TypeError otherwise). Fix at 3.0 and forward-merge.",
                        code: trim($line), category: 'search-hook-return',
                    );
                }
            }
        }
        return $violations;
    }

    /**
     * FC-2x3x-04: a migration/install SQL that empties or deletes the datalists
     * `__site_secret__` (or `site_secret`) row. 2.x regenerated the secret lazily;
     * 3.x+ BootService hard-throws "site secret is not set". Re-seed a fresh secret
     * before the 3.x datalists->config phinx migration.
     *
     * @return array<Violation>
     */
    private function checkSiteSecretScrub(string $pluginPath): array
    {
        $violations = [];
        foreach ($this->sourceFiles($pluginPath, ['sql', 'php']) as $file) {
            $content = file_get_contents($file);
            if ($content === false) {
                continue;
            }
            if (!preg_match('/(?:__site_secret__|site_secret)/', $content)) {
                continue;
            }
            $rel = $this->relativePath($pluginPath, $file);
            foreach (explode("\n", $content) as $i => $line) {
                if ($this->isCommentLine($line)) {
                    continue;
                }
                if (preg_match('/(?:DELETE\s+FROM|UPDATE)\b.*(?:__site_secret__|site_secret)/i', $line)
                    || preg_match('/(?:__site_secret__|site_secret).*(?:=\s*[\'"]{2}|SET\s+value\s*=\s*[\'"]{2})/i', $line)) {
                    $violations[] = new Violation(
                        file: $rel, line: $i + 1, severity: 'warning',
                        message: 'Scrubbing the datalists site secret breaks 3.x+ boot ("site secret is not set") — 2.x regenerated it lazily but 3.x+ BootService hard-throws. Re-seed a fresh secret before the datalists->config migration.',
                        code: trim($line), category: 'site-secret-scrub',
                    );
                }
            }
        }
        return $violations;
    }

    /**
     * FC-4x5x-04 / FC-4x5x-05: 5.x DI/service + session relocations.
     *   - ElggSession::setLoggedInUser()/removeLoggedInUser() moved to session_manager
     *   - PluginHooksService and \DI\get('hooks') removed (drop $hooks from DI)
     *   - \ElggCache removed (use \Elgg\Cache\BaseCache)
     *   - elgg_entity_gatekeeper() now requires a GUID argument
     *
     * @return array<Violation>
     */
    private function check5xServiceRemovals(string $pluginPath): array
    {
        $checks = [
            ['/(?:->|::)setLoggedInUser\s*\(|(?:->|::)removeLoggedInUser\s*\(/',
             'ElggSession::setLoggedInUser()/removeLoggedInUser() moved to session_manager in 5.x — use _elgg_services()->session_manager->setLoggedInUser($user).'],
            ['/\bPluginHooksService\b/',
             'PluginHooksService was removed in 5.x — hooks merged into the events service; drop $hooks from DI/constructors.'],
            ['/\\\\?DI\\\\get\s*\(\s*[\'"]hooks[\'"]\s*\)/',
             "\\DI\\get('hooks') removed in 5.x — the hooks service no longer exists; drop it from the DI graph."],
            ['/\buse\s+\\\\?ElggCache\b/',
             '\\ElggCache was removed in 5.x — extend \\Elgg\\Cache\\BaseCache instead.'],
            ['/elgg_entity_gatekeeper\s*\(\s*\)/',
             'elgg_entity_gatekeeper() requires an entity GUID in 5.x — pass elgg_entity_gatekeeper($guid).'],
        ];
        $violations = [];
        foreach ($this->phpFiles($pluginPath) as $file) {
            $content = file_get_contents($file);
            if ($content === false) {
                continue;
            }
            $rel = $this->relativePath($pluginPath, $file);
            foreach (explode("\n", $content) as $i => $line) {
                if ($this->isCommentLine($line)) {
                    continue;
                }
                foreach ($checks as [$pattern, $message]) {
                    if (preg_match($pattern, $line)) {
                        $violations[] = new Violation(
                            file: $rel, line: $i + 1, severity: 'error',
                            message: $message, code: trim($line), category: 'removed-service',
                        );
                    }
                }
            }
        }
        return $violations;
    }

    /**
     * FC-4x5x-06: 5.x menu/JS API changes.
     *   - require(['jquery-ui', ...]) — jquery-ui split out
     *   - array_keys($menu) on a PreparedMenu (foreach($menu as $section) instead)
     *
     * @return array<Violation>
     */
    private function check5xMenuJsApi(string $pluginPath): array
    {
        $violations = [];
        foreach ($this->sourceFiles($pluginPath, ['php', 'js']) as $file) {
            $content = file_get_contents($file);
            if ($content === false) {
                continue;
            }
            $rel = $this->relativePath($pluginPath, $file);
            foreach (explode("\n", $content) as $i => $line) {
                if ($this->isCommentLine($line)) {
                    continue;
                }
                if (preg_match('/require\s*\(\s*\[[^\]]*[\'"]jquery-ui/', $line)) {
                    $violations[] = new Violation(
                        file: $rel, line: $i + 1, severity: 'warning',
                        message: "jquery-ui was split out in 5.x — drop it from the require([]) dependency list.",
                        code: trim($line), category: 'removed-js-api',
                    );
                }
                if (preg_match('/array_keys\s*\(\s*\$\w*[Mm]enu\b/', $line)) {
                    $violations[] = new Violation(
                        file: $rel, line: $i + 1, severity: 'warning',
                        message: 'array_keys() on a PreparedMenu — 5.x menus are objects; iterate with foreach ($menu as $section) instead.',
                        code: trim($line), category: 'removed-js-api',
                    );
                }
            }
        }
        return $violations;
    }

    /**
     * FC-4x5x-07: direct `$entity->subtype = ...` assignment. 5.x requires a
     * non-empty subtype and throws on a bare property write; use setSubtype().
     * (A `->subtype ==` comparison is excluded by the negative lookahead.)
     *
     * @return array<Violation>
     */
    private function check5xSubtypeAssignment(string $pluginPath): array
    {
        return $this->regexScan(
            $pluginPath,
            ['php'],
            '/->subtype\s*=(?!=)/',
            'error',
            '$entity->subtype = ... throws in 5.x — use $entity->setSubtype($x); groups default to \'group\'.',
            'subtype-assignment',
        );
    }

    /**
     * FC-4x5x-08: mocking \Elgg\Event without disableOriginalConstructor(). The
     * 5.x Event class has a required constructor, so getMockBuilder(Event::class)
     * ->getMock() explodes unless the original constructor is disabled.
     *
     * @return array<Violation>
     */
    private function check5xTestMocking(string $pluginPath): array
    {
        $violations = [];
        foreach ($this->phpFiles($pluginPath) as $file) {
            if (!str_contains($file, '/tests/') && !str_ends_with($file, 'Test.php')) {
                continue;
            }
            $content = file_get_contents($file);
            if ($content === false) {
                continue;
            }
            if (!preg_match('/getMockBuilder\s*\(\s*\\\\?(?:Elgg\\\\)?Event::class/', $content)) {
                continue;
            }
            if (str_contains($content, 'disableOriginalConstructor')) {
                continue;
            }
            $rel = $this->relativePath($pluginPath, $file);
            foreach (explode("\n", $content) as $i => $line) {
                if (preg_match('/getMockBuilder\s*\(\s*\\\\?(?:Elgg\\\\)?Event::class/', $line)) {
                    $violations[] = new Violation(
                        file: $rel, line: $i + 1, severity: 'warning',
                        message: '\\Elgg\\Event has a required constructor in 5.x — call ->disableOriginalConstructor() on the mock builder.',
                        code: trim($line), category: 'test-mock-constructor',
                    );
                }
            }
        }
        return $violations;
    }

    /**
     * FC-5x6x-03: a class `extends ... Seed` (\Elgg\Database\Seeds\Seed) that does
     * not implement BOTH getType() and getCountOptions(). Seed gained these abstract
     * methods in 6.1 — a subclass missing either is an autoload-time fatal on every
     * page.
     *
     * @return array<Violation>
     */
    private function checkSeedAbstractMethods(string $pluginPath, string $targetVersion): array
    {
        $violations = [];
        foreach ($this->phpFiles($pluginPath) as $file) {
            $content = file_get_contents($file);
            if ($content === false) {
                continue;
            }
            if (!preg_match('/class\s+\w+\s+extends\s+\\\\?(?:Elgg\\\\Database\\\\Seeds\\\\)?Seed\b/', $content, $m, PREG_OFFSET_CAPTURE)) {
                continue;
            }
            $hasType = (bool) preg_match('/function\s+getType\s*\(/', $content);
            $hasCount = (bool) preg_match('/function\s+getCountOptions\s*\(/', $content);
            if ($hasType && $hasCount) {
                continue;
            }
            $line = substr_count(substr($content, 0, $m[0][1]), "\n") + 1;
            $missing = [];
            if (!$hasType) {
                $missing[] = 'getType()';
            }
            if (!$hasCount) {
                $missing[] = 'getCountOptions()';
            }
            $violations[] = new Violation(
                file: $this->relativePath($pluginPath, $file),
                line: $line,
                severity: 'error',
                message: "Seed subclass is missing " . implode(' and ', $missing) . " — Seed gained these abstract methods in 6.1; a subclass missing either is an autoload-time fatal on every page in Elgg {$targetVersion}. Implement static getType():string and getCountOptions():array.",
                code: trim(strtok($m[0][0], "\n")),
                category: 'seed-abstract-methods',
            );
        }
        return $violations;
    }

    /**
     * FC-6x7x-01: bare removed CONSTANTS (e.g. ELGG_CACHE_PERSISTENT, dropped in
     * 7.x). The call-shaped removed-functions gate only matches `name(` so a bare
     * constant slips through. Data-driven: any ALL-CAPS key in removed-functions.json
     * at/below the target major is treated as a removed constant.
     *
     * @return array<Violation>
     */
    private function checkRemovedConstants(string $pluginPath, string $targetVersion): array
    {
        $removed = $this->removedFunctionsFor($targetVersion);
        $constants = [];
        foreach ($removed as $name => $replacement) {
            if (preg_match('/^[A-Z][A-Z0-9_]+$/', $name)) {
                $constants[$name] = $replacement;
            }
        }
        if (empty($constants)) {
            return [];
        }
        $pattern = '/(?<![\w\\\\])(' . implode('|', array_map('preg_quote', array_keys($constants))) . ')\b/';
        $violations = [];
        foreach ($this->phpFiles($pluginPath) as $file) {
            $content = file_get_contents($file);
            if ($content === false) {
                continue;
            }
            $rel = $this->relativePath($pluginPath, $file);
            foreach (explode("\n", $content) as $i => $line) {
                if ($this->isCommentLine($line)) {
                    continue;
                }
                if (preg_match($pattern, $line, $m)) {
                    $violations[] = new Violation(
                        file: $rel, line: $i + 1, severity: 'error',
                        message: "{$m[1]} was removed in Elgg {$targetVersion} — {$constants[$m[1]]}",
                        code: trim($line), category: 'removed-constant',
                    );
                }
            }
        }
        return $violations;
    }

    /**
     * FC-6x7x-03: `$return->add(\ElggMenuItem::factory(...))` in a register/menu
     * handler. In 7.x the menu register value is a plain array, not a MenuItems
     * collection — push with `$return[] = \ElggMenuItem::factory([...]); return $return;`.
     *
     * @return array<Violation>
     */
    private function checkMenuAddValue(string $pluginPath): array
    {
        return $this->regexScan(
            $pluginPath,
            ['php'],
            '/\$\w+->add\s*\(\s*\\\\?ElggMenuItem::factory/',
            'error',
            'Menu register value is a plain array in 7.x, not a collection — use $return[] = \\ElggMenuItem::factory([...]); return $return; instead of $return->add(...).',
            'menu-add-value',
        );
    }

    /**
     * FC-6x7x-05: CSS view overrides left at views/*\/css/elements/*.php. In 7.x
     * these relocated to views/*\/elements/*.css; the old-path override is silently
     * orphaned (HTTP 200 but unstyled).
     *
     * @return array<Violation>
     */
    private function checkCssViewRelocation(string $pluginPath): array
    {
        $violations = [];
        foreach ($this->sourceFiles($pluginPath, ['php']) as $file) {
            $rel = $this->relativePath($pluginPath, $file);
            if (preg_match('#(^|/)views/[^/]+/css/elements/[^/]+\.php$#', $rel)) {
                $violations[] = new Violation(
                    file: $rel, line: 0, severity: 'warning',
                    message: 'CSS view override at css/elements/* is orphaned in 7.x (200 but unstyled) — relocate to views/default/elements/*.css (or extend elgg.css for no-counterpart files).',
                    code: '', category: 'css-view-relocation',
                );
            }
        }
        return $violations;
    }

    /**
     * FC-6x7x-06: ESM bare specifiers missing the js/ view-path prefix. In 7.x the
     * importmap key is the full view path minus .mjs (no js/ strip), so a specifier
     * like 'framework/gallery/init' must be 'js/framework/gallery/init'.
     *
     * @return array<Violation>
     */
    private function checkEsmBareSpecifiers(string $pluginPath): array
    {
        $violations = [];
        // View roots that in Elgg live under js/ — a specifier starting with one of
        // these but WITHOUT the js/ prefix is almost certainly an unmapped import.
        $roots = 'framework|elements|components|navigation|input|page|forms|entity|ajax';
        $pattern = '#(?:import\s[^\'"]*from\s*|import\s*\(\s*)[\'"]((?:' . $roots . ')/[\w./-]+)[\'"]#';
        foreach ($this->sourceFiles($pluginPath, ['mjs', 'js']) as $file) {
            $content = file_get_contents($file);
            if ($content === false) {
                continue;
            }
            $rel = $this->relativePath($pluginPath, $file);
            foreach (explode("\n", $content) as $i => $line) {
                if ($this->isCommentLine($line)) {
                    continue;
                }
                if (preg_match($pattern, $line, $m)) {
                    $violations[] = new Violation(
                        file: $rel, line: $i + 1, severity: 'warning',
                        message: "ESM specifier '{$m[1]}' omits the js/ view path — 7.x importmap keys are the full view path minus .mjs; prefix it (js/{$m[1]}) or register the vendored lib in the importmap.",
                        code: trim($line), category: 'esm-bare-specifier',
                    );
                }
            }
        }
        return $violations;
    }

    /**
     * FC-6x7x-07: reliance on a global jQuery. In 7.x jQuery is a deferred ESM
     * module, no longer a global — `window.jQuery` / bare `jQuery(` only work after
     * `import('jquery')` re-exposes it.
     *
     * @return array<Violation>
     */
    private function checkJqueryGlobal(string $pluginPath): array
    {
        $violations = [];
        foreach ($this->sourceFiles($pluginPath, ['js', 'mjs']) as $file) {
            $content = file_get_contents($file);
            if ($content === false) {
                continue;
            }
            // A file that imports jquery has already re-exposed the global.
            if (preg_match('/import[^;]*[\'"]jquery[\'"]/', $content)) {
                continue;
            }
            $rel = $this->relativePath($pluginPath, $file);
            foreach (explode("\n", $content) as $i => $line) {
                if ($this->isCommentLine($line)) {
                    continue;
                }
                if (preg_match('/\bwindow\.jQuery\b|\bjQuery\s*\(/', $line)) {
                    $violations[] = new Violation(
                        file: $rel, line: $i + 1, severity: 'warning',
                        message: "jQuery is no longer a global in 7.x (deferred ESM) — import('jquery') and expose window.jQuery/$ before dependent code.",
                        code: trim($line), category: 'jquery-global',
                    );
                }
            }
        }
        return $violations;
    }

    /**
     * FC-6x7x-08: a NAMED import from elgg/i18n. That module has a DEFAULT export
     * only in 7.x, so `import { echo } from 'elgg/i18n'` is undefined at runtime.
     *
     * @return array<Violation>
     */
    private function checkI18nNamedImport(string $pluginPath): array
    {
        return $this->regexScan(
            $pluginPath,
            ['js', 'mjs'],
            '/import\s*\{[^}]*\}\s*from\s*[\'"]elgg\/i18n[\'"]/',
            'error',
            "elgg/i18n has a DEFAULT export only in 7.x — use `import i18n from 'elgg/i18n'; const echo = (...a) => i18n.echo(...a);` instead of a named import.",
            'i18n-named-import',
        );
    }

    /**
     * FC-6x7x-09: elgg_format_element('') with an empty tag name. 7.x rejects a
     * zero-length tag — emit htmlspecialchars((string) $value) directly instead.
     *
     * @return array<Violation>
     */
    private function checkEmptyFormatElement(string $pluginPath): array
    {
        return $this->regexScan(
            $pluginPath,
            ['php'],
            '/elgg_format_element\s*\(\s*([\'"])\1\s*,/',
            'error',
            "elgg_format_element('') rejects an empty tag name in 7.x — emit htmlspecialchars((string) \$value) directly instead of a zero-tag element.",
            'empty-format-element',
        );
    }

    /**
     * FC-6x7x-10: Doctrine DBAL named-param array keys that carry the leading colon.
     * DBAL matches `:name` in the SQL to the key `name` (no colon) — a `[':name' =>]`
     * key silently fails to bind in 7.x.
     *
     * @return array<Violation>
     */
    private function checkDbalColonParams(string $pluginPath): array
    {
        $violations = [];
        foreach ($this->phpFiles($pluginPath) as $file) {
            $content = file_get_contents($file);
            if ($content === false) {
                continue;
            }
            // Only files that talk to executeStatement/executeQuery are candidates.
            if (!preg_match('/execute(?:Statement|Query)\s*\(/', $content)) {
                continue;
            }
            $rel = $this->relativePath($pluginPath, $file);
            foreach (explode("\n", $content) as $i => $line) {
                if ($this->isCommentLine($line)) {
                    continue;
                }
                if (preg_match('/[\'"]:\w+[\'"]\s*=>/', $line)) {
                    $violations[] = new Violation(
                        file: $rel, line: $i + 1, severity: 'warning',
                        message: "DBAL named-param key carries a ':' — drop it (DBAL matches :name in SQL to the key 'name'); a ':name' key fails to bind in 7.x.",
                        code: trim($line), category: 'dbal-colon-param',
                    );
                }
            }
        }
        return $violations;
    }

    /**
     * FC-6x7x-11: canWriteToContainer() called with a null / ELGG_ENTITIES_ANY_VALUE
     * subtype. 7.x requires a non-null string subtype — resolve it (e.g. Group::SUBTYPE)
     * before the container check.
     *
     * @return array<Violation>
     */
    private function checkCanWriteToContainerSubtype(string $pluginPath): array
    {
        return $this->regexScan(
            $pluginPath,
            ['php'],
            '/canWriteToContainer\s*\([^)]*(?:null|ELGG_ENTITIES_ANY_VALUE)[^)]*\)/',
            'warning',
            'canWriteToContainer() requires a non-null string subtype in 7.x — resolve $subtype (e.g. Group::SUBTYPE) before the container check.',
            'canwrite-null-subtype',
        );
    }

    /**
     * FC-6x7x-12: a method call inside a double-quoted string without braces, e.g.
     * "members/listing/$event->getType()". PHP interpolates simple `$var` and
     * `$var->prop` but NOT `$var->method()` — the parens are emitted literally.
     * Brace it: "members/listing/{$event->getType()}".
     *
     * @return array<Violation>
     */
    private function checkUnbracedMethodInterpolation(string $pluginPath): array
    {
        $violations = [];
        foreach ($this->phpFiles($pluginPath) as $file) {
            $content = file_get_contents($file);
            if ($content === false) {
                continue;
            }
            $rel = $this->relativePath($pluginPath, $file);
            foreach (explode("\n", $content) as $i => $line) {
                if ($this->isCommentLine($line)) {
                    continue;
                }
                // A double-quoted string segment containing $var->method( with no
                // preceding brace. Heuristic but low-FP for this specific bug.
                if (preg_match('/"[^"]*(?<!\{)\$\w+->\w+\s*\([^"]*"/', $line)) {
                    $violations[] = new Violation(
                        file: $rel, line: $i + 1, severity: 'warning',
                        message: 'Method call interpolated in a double-quoted string without braces — PHP emits the parens literally. Brace it: "{$obj->method()}".',
                        code: trim($line), category: 'unbraced-method-interpolation',
                    );
                }
            }
        }
        return $violations;
    }

    /**
     * FC-6x7x-13: an admin/install password shorter than 16 chars. 7.x raised the
     * minimum password length to 16 — batchInstall silently fails admin creation
     * with a short password.
     *
     * @return array<Violation>
     */
    private function checkAdminPasswordLength(string $pluginPath): array
    {
        $violations = [];
        foreach ($this->sourceFiles($pluginPath, ['sh', 'yml', 'yaml', 'env', 'php']) as $file) {
            $rel = $this->relativePath($pluginPath, $file);
            if (!preg_match('/install|batchinstall|admin/i', $rel . ' ' . (string) file_get_contents($file))) {
                continue;
            }
            $content = file_get_contents($file);
            if ($content === false) {
                continue;
            }
            foreach (explode("\n", $content) as $i => $line) {
                if ($this->isCommentLine($line)) {
                    continue;
                }
                if (preg_match('/(?:--password[= ]+|password["\']?\s*[:=]\s*["\']?)([^\s"\']+)/i', $line, $m)) {
                    if (strlen($m[1]) < 16 && !str_starts_with($m[1], '$')) {
                        $violations[] = new Violation(
                            file: $rel, line: $i + 1, severity: 'warning',
                            message: '7.x raised the minimum password length to 16 — a shorter admin password makes batchInstall silently fail admin creation. Use a >=16-char password.',
                            code: trim($line), category: 'admin-password-length',
                        );
                    }
                }
            }
        }
        return $violations;
    }

    /**
     * composer.json 'version' field on a tagged release commit overrides the git
     * tag Composer would otherwise derive — drop the field so the tag governs.
     * (MEMORY feedback_composer_version_field_shadows_tag.)
     *
     * @return array<Violation>
     */
    private function checkComposerVersionField(string $pluginPath): array
    {
        $composer = $pluginPath . '/composer.json';
        if (!is_file($composer)) {
            return [];
        }
        $raw = file_get_contents($composer);
        if ($raw === false) {
            return [];
        }
        $data = json_decode($raw, true);
        if (!is_array($data) || !array_key_exists('version', $data)) {
            return [];
        }
        $line = 0;
        foreach (explode("\n", $raw) as $i => $l) {
            if (preg_match('/^\s*"version"\s*:/', $l)) {
                $line = $i + 1;
                break;
            }
        }
        return [new Violation(
            file: 'composer.json',
            line: $line,
            severity: 'warning',
            message: "composer.json has a top-level \"version\" field — on a tagged release it shadows/overrides the git tag Composer derives. Drop the field and let the tag govern.",
            code: '"version": "' . (is_scalar($data['version']) ? (string) $data['version'] : '') . '"',
            category: 'composer-version-field',
        )];
    }

    /**
     * A malformed docblock terminator — a doubled comment-close (two adjacent
     * star-slash sequences), the classic artifact of an AddDocBlocks pass
     * re-terminating an already-closed block. Left uncaught it is a parse error
     * or a swallowed following line.
     *
     * @return array<Violation>
     */
    private function checkDocblockTerminator(string $pluginPath): array
    {
        return $this->regexScan(
            $pluginPath,
            ['php'],
            '#\*/\s*\*/#',
            'warning',
            "Malformed docblock: doubled '*/' terminator — remove the extra close (AddDocBlocks re-terminating an already-closed block).",
            'docblock-terminator',
            skipComments: false,
        );
    }

    /**
     * FC-ALL-05: a route:rewrite handler registered declaratively (elgg-plugin.php
     * 'events') or in an init handler. route:rewrite fires at BOOT, before init —
     * register it in Bootstrap::boot() early or the early service can 500 every page.
     *
     * @return array<Violation>
     */
    private function checkRouteRewriteTiming(string $pluginPath): array
    {
        $violations = [];
        $pluginPhp = $pluginPath . '/elgg-plugin.php';
        if (is_file($pluginPhp)) {
            $content = (string) file_get_contents($pluginPhp);
            foreach (explode("\n", $content) as $i => $line) {
                if (!$this->isCommentLine($line) && preg_match('/[\'"]route:rewrite[\'"]/', $line)) {
                    $violations[] = new Violation(
                        file: 'elgg-plugin.php', line: $i + 1, severity: 'warning',
                        message: "route:rewrite fires at BOOT, before init — declaring it in elgg-plugin.php 'events' (init-time) can 500 every page. Register it in Bootstrap::boot() early.",
                        code: trim($line), category: 'route-rewrite-timing',
                    );
                }
            }
        }
        foreach ($this->phpFiles($pluginPath) as $file) {
            if (basename($file) === 'elgg-plugin.php') {
                continue;
            }
            $content = file_get_contents($file);
            if ($content === false) {
                continue;
            }
            $rel = $this->relativePath($pluginPath, $file);
            foreach (explode("\n", $content) as $i => $line) {
                if ($this->isCommentLine($line)) {
                    continue;
                }
                if (preg_match('/elgg_register_event_handler\s*\(\s*[\'"]route:rewrite[\'"]/', $line)) {
                    $violations[] = new Violation(
                        file: $rel, line: $i + 1, severity: 'warning',
                        message: 'route:rewrite is registered outside Bootstrap::boot() — it fires at boot, before init; register it in boot() early and guard early services with an ELGG_CACHE_RUNTIME fallback.',
                        code: trim($line), category: 'route-rewrite-timing',
                    );
                }
            }
        }
        return $violations;
    }

    /**
     * FC-ALL-06: elgg-plugin.php include-time side effects, and class-constant use
     * inside the 'entities' block. elgg-plugin.php is parsed before the classes/
     * autoloader is wired, so `MyClass::SUBTYPE` / `\Ns\Class::class` in entities
     * fatals; and any mkdir/file write above the return runs on every include.
     *
     * @return array<Violation>
     */
    private function checkElggPluginSideEffects(string $pluginPath): array
    {
        $pluginPhp = $pluginPath . '/elgg-plugin.php';
        if (!is_file($pluginPhp)) {
            return [];
        }
        $content = file_get_contents($pluginPhp);
        if ($content === false) {
            return [];
        }
        $lines = explode("\n", $content);
        $violations = [];

        // (a) filesystem side effects before the top-level return.
        $returnLine = PHP_INT_MAX;
        foreach ($lines as $i => $line) {
            if (preg_match('/^\s*return\b/', $line)) {
                $returnLine = $i;
                break;
            }
        }
        foreach ($lines as $i => $line) {
            if ($i >= $returnLine || $this->isCommentLine($line)) {
                continue;
            }
            if (preg_match('/\b(mkdir|file_put_contents|copy|unlink|rename|touch|fopen|fwrite)\s*\(/', $line, $m)) {
                $violations[] = new Violation(
                    file: 'elgg-plugin.php', line: $i + 1, severity: 'warning',
                    message: "Include-time filesystem side effect ({$m[1]}()) above the return — runs on every include. Move it to Bootstrap::boot()/init().",
                    code: trim($line), category: 'elgg-plugin-side-effects',
                );
            }
        }

        // (b) class constants inside the 'entities' block.
        $inBlock = false;
        $depth = 0;
        foreach ($lines as $i => $line) {
            if (!$inBlock) {
                if (preg_match('/[\'"]entities[\'"]\s*=>\s*\[/', $line)) {
                    $inBlock = true;
                    $depth = 0;
                } else {
                    continue;
                }
            }
            $depth += substr_count($line, '[') - substr_count($line, ']');
            if (!$this->isCommentLine($line)
                && preg_match('/\w+::(?:class|[A-Z][A-Z0-9_]+)\b/', $line, $m)) {
                $violations[] = new Violation(
                    file: 'elgg-plugin.php', line: $i + 1, severity: 'warning',
                    message: "Class constant ({$m[0]}) in the 'entities' block — elgg-plugin.php is parsed before the classes/ autoloader; use a 'class' => 'Ns\\\\Class' string literal.",
                    code: trim($line), category: 'elgg-plugin-side-effects',
                );
            }
            if ($depth <= 0) {
                $inBlock = false;
            }
        }
        return $violations;
    }

    /**
     * FC-ALL-07: a lib/functions.php that declares procedural helpers but is not
     * required at the TOP of elgg-plugin.php. composer autoload.files does not fire
     * for git-tracked customs early enough, and a Bootstrap require_once is too late.
     *
     * @return array<Violation>
     */
    private function checkLibFunctionsAutoload(string $pluginPath): array
    {
        $lib = $pluginPath . '/lib/functions.php';
        $pluginPhp = $pluginPath . '/elgg-plugin.php';
        if (!is_file($lib) || !is_file($pluginPhp)) {
            return [];
        }
        $libContent = (string) file_get_contents($lib);
        if (!preg_match('/^\s*function\s+\w+\s*\(/m', $libContent)) {
            return []; // no global helpers declared
        }
        $head = implode("\n", array_slice(explode("\n", (string) file_get_contents($pluginPhp)), 0, 20));
        if (preg_match('#require(?:_once)?[^;\n]*lib/functions\.php#', $head)) {
            return [];
        }
        return [new Violation(
            file: 'elgg-plugin.php',
            line: 1,
            severity: 'warning',
            message: "lib/functions.php declares global helpers but elgg-plugin.php does not require_once it at the top — composer autoload.files/Bootstrap load too late for git-tracked customs. Add require_once __DIR__ . '/lib/functions.php'; at the top of elgg-plugin.php.",
            code: '',
            category: 'lib-functions-autoload',
        )];
    }

    /**
     * FC-ALL-08: a call to a known optional-dependency global helper without a
     * function_exists()/elgg()->has() guard nearby. Unguarded, the plugin fails to
     * activate in a standalone stack that lacks the optional dependency.
     *
     * @return array<Violation>
     */
    private function checkUnguardedOptionalDeps(string $pluginPath): array
    {
        // Conservative, curated list of hype-ecosystem optional-dep helpers.
        $optional = ['hypeApps', 'hypeList', 'hypeLists', 'hypeShortcode', 'elgg_get_shortcodes', 'hj_lists_get_menu'];
        $names = implode('|', array_map('preg_quote', $optional));
        $callPattern = '/(?<![\w>$:\\\\])(' . $names . ')\s*\(/';
        $violations = [];
        foreach ($this->phpFiles($pluginPath) as $file) {
            $content = file_get_contents($file);
            if ($content === false) {
                continue;
            }
            // If the file guards anywhere, treat it as intentional and skip.
            if (preg_match('/function_exists\s*\(|elgg\(\)\s*->\s*has\s*\(/', $content)) {
                continue;
            }
            $rel = $this->relativePath($pluginPath, $file);
            foreach (explode("\n", $content) as $i => $line) {
                if ($this->isCommentLine($line)) {
                    continue;
                }
                if (preg_match($callPattern, $line, $m)) {
                    $violations[] = new Violation(
                        file: $rel, line: $i + 1, severity: 'warning',
                        message: "Optional-dependency helper {$m[1]}() called without a function_exists()/elgg()->has() guard — the plugin fails to activate in a standalone stack lacking the dependency.",
                        code: trim($line), category: 'unguarded-optional-dep',
                    );
                }
            }
        }
        return $violations;
    }

    // =====================================================================
    // Detector helpers
    // =====================================================================

    /**
     * Generic single-pattern line scan over files of the given extensions.
     *
     * @param array<string> $exts
     * @return array<Violation>
     */
    private function regexScan(
        string $pluginPath,
        array $exts,
        string $pattern,
        string $severity,
        string $message,
        string $category,
        bool $skipComments = true,
    ): array {
        $violations = [];
        foreach ($this->sourceFiles($pluginPath, $exts) as $file) {
            $content = file_get_contents($file);
            if ($content === false) {
                continue;
            }
            $rel = $this->relativePath($pluginPath, $file);
            foreach (explode("\n", $content) as $i => $line) {
                if ($skipComments && $this->isCommentLine($line)) {
                    continue;
                }
                if (preg_match($pattern, $line)) {
                    $violations[] = new Violation(
                        file: $rel,
                        line: $i + 1,
                        severity: $severity,
                        message: $message,
                        code: trim($line),
                        category: $category,
                    );
                }
            }
        }
        return $violations;
    }

    /**
     * Best-effort "this line is a comment" test — a docblock/line/hash comment.
     */
    private function isCommentLine(string $line): bool
    {
        $t = ltrim($line);
        return $t === ''
            || $t[0] === '*'
            || str_starts_with($t, '//')
            || str_starts_with($t, '/*')
            || str_starts_with($t, '#');
    }

    /**
     * Iterate files of the given extensions, skipping third-party/nested-plugin
     * trees exactly like phpFiles().
     *
     * @param array<string> $exts
     * @return \Generator<string>
     */
    private function sourceFiles(string $dir, array $exts): \Generator
    {
        if (!is_dir($dir)) {
            return;
        }
        $exts = array_map('strtolower', $exts);
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
        );
        foreach ($iterator as $file) {
            /** @var \SplFileInfo $file */
            if (!in_array(strtolower($file->getExtension()), $exts, true)) {
                continue;
            }
            $path = $file->getPathname();
            if (str_contains($path, '/vendor/') || str_contains($path, '/vendors/') || str_contains($path, '/mod/')) {
                continue;
            }
            yield $path;
        }
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
