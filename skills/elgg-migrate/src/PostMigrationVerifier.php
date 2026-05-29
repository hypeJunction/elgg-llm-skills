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
            // exactly the bodyology chain contamination (bd elgg-migrate-xs2g6).
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

        $errors = array_filter($violations, fn(Violation $v) => $v->severity === 'error');

        return new VerificationResult(
            targetVersion: $targetVersion,
            violations: $violations,
            passed: count($errors) === 0,
        );
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

        foreach ($lines as $lineNum => $line) {
            if (preg_match("/['\"]events['\"]\s*=>/", $line)) {
                $inEventsBlock = true;
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
