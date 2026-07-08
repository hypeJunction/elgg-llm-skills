<?php

declare(strict_types=1);

namespace ElggMigrate\Tests;

use ElggMigrate\VersionGuard;
use ElggMigrate\VersionMismatchException;
use PHPUnit\Framework\TestCase;

final class VersionGuardTest extends TestCase
{
    private VersionGuard $guard;

    protected function setUp(): void
    {
        $this->guard = new VersionGuard();
    }

    // --- Version Detection Tests ---

    public function testDetects2xPlugin(): void
    {
        $dir = $this->makePluginDir([
            'start.php' => "<?php\nelgg_register_event_handler('init', 'system', 'myplugin_init');",
            'manifest.xml' => '<?xml version="1.0"?><plugin_manifest/>',
        ]);

        try {
            $this->assertSame('2.x', $this->guard->detectVersion($dir));
        } finally {
            $this->removeDir($dir);
        }
    }

    public function testDetects3xPlugin(): void
    {
        $dir = $this->makePluginDir([
            'start.php' => "<?php\nreturn function() {};",
            'elgg-plugin.php' => "<?php\nreturn ['routes' => []];",
            'manifest.xml' => '<?xml version="1.0"?><plugin_manifest/>',
        ]);

        try {
            $this->assertSame('3.x', $this->guard->detectVersion($dir));
        } finally {
            $this->removeDir($dir);
        }
    }

    public function testDetects3xPluginWithElggPluginPhpAndManifestXml(): void
    {
        $dir = $this->makePluginDir([
            'elgg-plugin.php' => "<?php\nreturn ['routes' => []];",
            'manifest.xml' => '<?xml version="1.0"?><plugin_manifest/>',
        ]);

        try {
            $this->assertSame('3.x', $this->guard->detectVersion($dir));
        } finally {
            $this->removeDir($dir);
        }
    }

    public function testDetects4xPlugin(): void
    {
        $dir = $this->makePluginDir([
            'elgg-plugin.php' => "<?php\nreturn [\n    'hooks' => [\n        'register' => [],\n    ],\n];",
        ]);

        try {
            $this->assertSame('4.x', $this->guard->detectVersion($dir));
        } finally {
            $this->removeDir($dir);
        }
    }

    public function testDetects4xPluginWithBothHooksAndEvents(): void
    {
        $dir = $this->makePluginDir([
            'elgg-plugin.php' => "<?php\nreturn [\n    'hooks' => [],\n    'events' => [],\n];",
        ]);

        try {
            $this->assertSame('4.x', $this->guard->detectVersion($dir));
        } finally {
            $this->removeDir($dir);
        }
    }

    public function testDetects5xPlugin(): void
    {
        $dir = $this->makePluginDir([
            'elgg-plugin.php' => "<?php\nreturn [\n    'events' => [\n        'register' => [],\n    ],\n];",
        ]);

        try {
            $this->assertSame('5.x', $this->guard->detectVersion($dir));
        } finally {
            $this->removeDir($dir);
        }
    }

    public function testDetects6xPlugin(): void
    {
        $dir = $this->makePluginDir([
            'elgg-plugin.php' => "<?php\nreturn ['events' => []];",
        ]);
        // Add a PHP file with ESM registration
        mkdir($dir . '/classes', 0755, true);
        file_put_contents($dir . '/classes/Bootstrap.php', "<?php\nelgg_register_esm('mymodule');\n");

        try {
            $this->assertSame('6.x', $this->guard->detectVersion($dir));
        } finally {
            $this->removeDir($dir);
        }
    }

    public function testThrowsForUnrecognizedPlugin(): void
    {
        $dir = sys_get_temp_dir() . '/elgg-migrate-' . uniqid();
        mkdir($dir, 0755, true);
        // Empty directory — no recognizable files
        file_put_contents($dir . '/README.md', 'nothing');

        try {
            $this->expectException(\RuntimeException::class);
            $this->expectExceptionMessageMatches('/Cannot detect Elgg version/');
            $this->guard->detectVersion($dir);
        } finally {
            $this->removeDir($dir);
        }
    }

    // --- Validation Tests ---

    public function testValidatePassesOnMatch(): void
    {
        $dir = $this->makePluginDir([
            'start.php' => "<?php\nreturn function() {};",
            'elgg-plugin.php' => "<?php\nreturn [];",
            'manifest.xml' => '<?xml version="1.0"?><plugin_manifest/>',
        ]);

        try {
            // Plugin is 3.x, manifest says from=3.x — should pass
            $this->guard->validate($dir, ['from' => '3.x', 'to' => '4.x']);
            $this->assertTrue(true); // No exception
        } finally {
            $this->removeDir($dir);
        }
    }

    public function testValidateThrowsOnMismatch(): void
    {
        $dir = $this->makePluginDir([
            'start.php' => "<?php\nelgg_register_event_handler('init', 'system', 'init');",
            'manifest.xml' => '<?xml version="1.0"?><plugin_manifest/>',
        ]);

        try {
            // Plugin is 2.x, but manifest says from=3.x
            $this->expectException(VersionMismatchException::class);
            $this->guard->validate($dir, ['from' => '3.x', 'to' => '4.x']);
        } finally {
            $this->removeDir($dir);
        }
    }

    public function testValidateExceptionContainsVersions(): void
    {
        $dir = $this->makePluginDir([
            'elgg-plugin.php' => "<?php\nreturn ['hooks' => []];",
        ]);

        try {
            $this->guard->validate($dir, ['from' => '3.x', 'to' => '4.x']);
            $this->fail('Expected VersionMismatchException');
        } catch (VersionMismatchException $e) {
            $this->assertSame('4.x', $e->detectedVersion);
            $this->assertSame('3.x', $e->expectedVersion);
        } finally {
            $this->removeDir($dir);
        }
    }

    public function testValidateThrowsOnMissingFromField(): void
    {
        $dir = $this->makePluginDir([
            'elgg-plugin.php' => "<?php\nreturn [];",
        ]);

        try {
            $this->expectException(\RuntimeException::class);
            $this->expectExceptionMessageMatches('/missing "from"/');
            $this->guard->validate($dir, ['to' => '4.x']);
        } finally {
            $this->removeDir($dir);
        }
    }

    // --- Incomplete-pattern detection (deep guards) ---

    public function testIncompletePatternsFlags4xShapeWith3xHookSignature(): void
    {
        // Plugin shape: 4.x (elgg-plugin.php, no start.php, no manifest.xml).
        // Content: still a 3.x 4-arg hook handler — the gap hypeinbox hit.
        $dir = $this->makePluginDir([
            'elgg-plugin.php' => "<?php\nreturn ['hooks' => []];",
            'classes/Foo/Router.php' => <<<'PHP'
                <?php
                namespace Foo;
                class Router {
                    public static function resolvePageOwner($hook, $type, $return, $params) {
                        return $return;
                    }
                }
                PHP,
        ]);

        try {
            $findings = $this->guard->detectIncompletePatterns($dir);
            $this->assertCount(1, $findings);
            $this->assertSame('old-hook-signature', $findings[0]->patternId);
            $this->assertSame('3.x', $findings[0]->sourceVersion);
            $this->assertSame('4.x', $findings[0]->claimedVersion);
            $this->assertStringContainsString('resolvePageOwner', $findings[0]->description);
            $this->assertStringContainsString('Router.php', $findings[0]->file);
        } finally {
            $this->removeDir($dir);
        }
    }

    public function testIncompletePatternsFlagsRemovedIn4xFunctionCalls(): void
    {
        $dir = $this->makePluginDir([
            'elgg-plugin.php' => "<?php\nreturn ['hooks' => []];",
            'classes/Foo/Boot.php' => <<<'PHP'
                <?php
                namespace Foo;
                class Boot {
                    public function init(): void {
                        \elgg_register_admin_menu_item('a', 'b', 'c');
                        $x = sanitize_string($input);
                        $y = sanitize_int($n);
                        \elgg_set_plugin_setting('k', 'v', 'p');
                    }
                }
                PHP,
        ]);

        try {
            $findings = $this->guard->detectIncompletePatterns($dir);
            $patternIds = array_map(fn ($f) => $f->patternId, $findings);
            $descriptions = array_map(fn ($f) => $f->description, $findings);

            // 4 removed-function calls, no hook-signature hits.
            $this->assertCount(4, $findings);
            $this->assertSame(['removed-function-call', 'removed-function-call', 'removed-function-call', 'removed-function-call'], $patternIds);
            $this->assertTrue(str_contains(implode('|', $descriptions), 'elgg_register_admin_menu_item'));
            $this->assertTrue(str_contains(implode('|', $descriptions), 'sanitize_string'));
            $this->assertTrue(str_contains(implode('|', $descriptions), 'sanitize_int'));
            $this->assertTrue(str_contains(implode('|', $descriptions), 'elgg_set_plugin_setting'));
        } finally {
            $this->removeDir($dir);
        }
    }

    public function testIncompletePatternsClearsAfterMigration(): void
    {
        // A genuinely-clean 4.x plugin returns zero findings.
        $dir = $this->makePluginDir([
            'elgg-plugin.php' => "<?php\nreturn ['hooks' => []];",
            'classes/Foo/Router.php' => <<<'PHP'
                <?php
                namespace Foo;
                class Router {
                    public static function resolvePageOwner(\Elgg\Hook $hook) {
                        return $hook->getValue();
                    }
                }
                PHP,
        ]);

        try {
            $this->assertEmpty($this->guard->detectIncompletePatterns($dir));
        } finally {
            $this->removeDir($dir);
        }
    }

    public function testIncompletePatternsReturnsEmptyForUnknownPriorVersion(): void
    {
        // 2.x has no prior — must short-circuit to [].
        $dir = $this->makePluginDir([
            'start.php' => "<?php\nelgg_register_event_handler('init', 'system', 'foo');",
            'manifest.xml' => '<?xml version="1.0"?><plugin_manifest/>',
        ]);

        try {
            $this->assertEmpty($this->guard->detectIncompletePatterns($dir));
        } finally {
            $this->removeDir($dir);
        }
    }

    public function testIncompletePatternsRespectsExplicitClaimedVersion(): void
    {
        // Tell the guard to check this 4.x plugin for 6.x-leftover patterns.
        // The 5.x->6.x step is defined, but this fixture makes no removed-6.x
        // function calls — so the leftover scan must return [].
        $dir = $this->makePluginDir([
            'elgg-plugin.php' => "<?php\nreturn ['hooks' => []];",
        ]);

        try {
            $this->assertEmpty($this->guard->detectIncompletePatterns($dir, '6.x'));
        } finally {
            $this->removeDir($dir);
        }
    }

    public function testIncompletePatternsFlags5xShapeWith4xHookParam(): void
    {
        // Plugin shape: 5.x (elgg-plugin.php events-only, no start.php).
        // Content: a method still typed against \Elgg\Hook — 4.x signature.
        $dir = $this->makePluginDir([
            'elgg-plugin.php' => "<?php\nreturn ['events' => []];",
            'classes/Foo/Router.php' => <<<'PHP'
                <?php
                namespace Foo;
                class Router {
                    public static function handle(\Elgg\Hook $hook) {
                        return $hook->getValue();
                    }
                }
                PHP,
        ]);

        try {
            $findings = $this->guard->detectIncompletePatterns($dir);
            $patternIds = array_map(fn ($f) => $f->patternId, $findings);
            $this->assertContains('elgg-hook-param', $patternIds);
            $this->assertSame('4.x', $findings[0]->sourceVersion);
            $this->assertSame('5.x', $findings[0]->claimedVersion);
        } finally {
            $this->removeDir($dir);
        }
    }

    public function testIncompletePatternsFlagsHooksKeyOnly5xShape(): void
    {
        // 5.x must use 'events' — top-level 'hooks' key is a 4.x leftover.
        // We have to make this 5.x SHAPE: events key present, no hooks key,
        // no start.php, no manifest.xml. The hooks-leftover is in a OTHER
        // PHP file (not elgg-plugin.php itself, since that would make the
        // shape detector say 4.x).
        $dir = $this->makePluginDir([
            'elgg-plugin.php' => "<?php\nreturn ['events' => []];",
            'config/hooks-old.php' => "<?php\nreturn ['hooks' => ['action' => []]];",
        ]);

        try {
            $findings = $this->guard->detectIncompletePatterns($dir);
            $patternIds = array_map(fn ($f) => $f->patternId, $findings);
            $this->assertContains('hooks-key-in-elgg-plugin', $patternIds);
        } finally {
            $this->removeDir($dir);
        }
    }

    public function testIncompletePatternsFlagsRemovedIn5xFunctionCalls(): void
    {
        $dir = $this->makePluginDir([
            'elgg-plugin.php' => "<?php\nreturn ['events' => []];",
            'classes/Foo/Boot.php' => <<<'PHP'
                <?php
                namespace Foo;
                class Boot {
                    public function init(): void {
                        \elgg_register_plugin_hook_handler('register', 'menu:entity', 'foo_setup');
                        \elgg_trigger_plugin_hook('something', 'system', [], null);
                    }
                }
                PHP,
        ]);

        try {
            $findings = $this->guard->detectIncompletePatterns($dir);
            $descs = implode('|', array_map(fn ($f) => $f->description, $findings));
            $this->assertStringContainsString('elgg_register_plugin_hook_handler', $descs);
            $this->assertStringContainsString('elgg_trigger_plugin_hook', $descs);
        } finally {
            $this->removeDir($dir);
        }
    }

    public function testIncompletePatternsIgnoresVendorAndTestsDirs(): void
    {
        $dir = $this->makePluginDir([
            'elgg-plugin.php' => "<?php\nreturn ['hooks' => []];",
            'vendor/bad-lib/legacy.php' => "<?php\nfunction stale(\$hook, \$type, \$return, \$params) {}",
            'mod/sub-plugin/legacy.php' => "<?php\nfunction also_stale(\$hook, \$type, \$return, \$params) {}",
        ]);

        try {
            $this->assertEmpty($this->guard->detectIncompletePatterns($dir));
        } finally {
            $this->removeDir($dir);
        }
    }

    // --- Leftover detectors for the previously-blind steps (doai1) ---

    public function testIncompletePatternsFlagsRemovedIn3xFunctionCall(): void
    {
        // 2.x->3.x: a call to a function dropped with the metastrings table.
        $dir = $this->makePluginDir([
            'start.php' => "<?php\nreturn function () {};",
            'classes/Foo/Sort.php' => "<?php\nnamespace Foo;\nclass Sort {\n  public function order() { return elgg_get_metastring_id('x'); }\n}\n",
        ]);

        try {
            $findings = $this->guard->detectIncompletePatterns($dir, '3.x');
            $ids = array_map(fn ($f) => $f->patternId, $findings);
            $this->assertContains('removed-function-call-3x', $ids);
            $hit = array_values(array_filter($findings, fn ($f) => $f->patternId === 'removed-function-call-3x'))[0];
            $this->assertSame('2.x', $hit->sourceVersion);
            $this->assertSame('3.x', $hit->claimedVersion);
            $this->assertStringContainsString('elgg_get_metastring_id', $hit->description);
        } finally {
            $this->removeDir($dir);
        }
    }

    public function testIncompletePatternsFlagsRemovedIn6xFunctionCall(): void
    {
        // 5.x->6.x: procedural plugin-hook API removed in 6.x.
        $dir = $this->makePluginDir([
            'elgg-plugin.php' => "<?php\nreturn ['events' => []];",
            'classes/Foo/Hooks.php' => "<?php\nnamespace Foo;\nclass Hooks {\n  public function reg() { elgg_register_plugin_hook_handler('a', 'b', 'c'); }\n}\n",
        ]);

        try {
            $findings = $this->guard->detectIncompletePatterns($dir, '6.x');
            $ids = array_map(fn ($f) => $f->patternId, $findings);
            $this->assertContains('removed-function-call-6x', $ids);
            $hit = array_values(array_filter($findings, fn ($f) => $f->patternId === 'removed-function-call-6x'))[0];
            $this->assertSame('5.x', $hit->sourceVersion);
            $this->assertStringContainsString('elgg_register_plugin_hook_handler', $hit->description);
        } finally {
            $this->removeDir($dir);
        }
    }

    public function testIncompletePatternsFlagsRemovedIn7xFunctionCall(): void
    {
        // 6.x->7.x: a global removed in 7.x still called after a claimed 7.x migration.
        // elgg_reset_system_cache is the call-shaped removal that first bites at 7.x
        // (per the 2026-07-08 core-verified removed-functions.json).
        $dir = $this->makePluginDir([
            'elgg-plugin.php' => "<?php\nreturn ['events' => []];",
            'classes/Foo/Cache.php' => "<?php\nnamespace Foo;\nclass Cache {\n  public function bust() { return elgg_reset_system_cache(); }\n}\n",
        ]);

        try {
            $findings = $this->guard->detectIncompletePatterns($dir, '7.x');
            $ids = array_map(fn ($f) => $f->patternId, $findings);
            $this->assertContains('removed-function-call-7x', $ids);
            $hit = array_values(array_filter($findings, fn ($f) => $f->patternId === 'removed-function-call-7x'))[0];
            $this->assertSame('6.x', $hit->sourceVersion);
            $this->assertStringContainsString('elgg_reset_system_cache', $hit->description);
        } finally {
            $this->removeDir($dir);
        }
    }

    public function testIncompletePatternsSilentOnMigrated7xCode(): void
    {
        // The replacements must NOT trip the 7.x leftover detector.
        $dir = $this->makePluginDir([
            'elgg-plugin.php' => "<?php\nreturn ['events' => []];",
            'classes/Foo/Access.php' => "<?php\nnamespace Foo;\nclass Access {\n  public function can() { return elgg_get_session()->isAdmin(); }\n}\n",
        ]);

        try {
            $findings = $this->guard->detectIncompletePatterns($dir, '7.x');
            $ids = array_map(fn ($f) => $f->patternId, $findings);
            $this->assertNotContains('removed-function-call-7x', $ids);
        } finally {
            $this->removeDir($dir);
        }
    }

    // --- Helpers ---

    private function makePluginDir(array $files): string
    {
        $dir = sys_get_temp_dir() . '/elgg-migrate-' . uniqid();
        mkdir($dir, 0755, true);

        foreach ($files as $name => $content) {
            $path = $dir . '/' . $name;
            $parentDir = dirname($path);
            if (!is_dir($parentDir)) {
                mkdir($parentDir, 0755, true);
            }
            file_put_contents($path, $content);
        }

        return $dir;
    }

    private function removeDir(string $dir): void
    {
        if (!is_dir($dir)) return;
        foreach (new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        ) as $f) {
            $f->isDir() ? rmdir($f->getPathname()) : unlink($f->getPathname());
        }
        rmdir($dir);
    }
}
