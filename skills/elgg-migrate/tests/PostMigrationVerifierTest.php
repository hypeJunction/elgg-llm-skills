<?php

declare(strict_types=1);

namespace ElggMigrate\Tests;

use ElggMigrate\PostMigrationVerifier;
use PHPUnit\Framework\TestCase;

final class PostMigrationVerifierTest extends TestCase
{
    private PostMigrationVerifier $verifier;

    protected function setUp(): void
    {
        $this->verifier = new PostMigrationVerifier();
    }

    // --- 4.x target: catch 5.x leakage ---

    public function testCatches5xFunctionIn4xTarget(): void
    {
        $dir = $this->makePluginDir([
            'classes/MyPlugin/Events.php' => "<?php\nnamespace MyPlugin;\nclass Events {\n    public static function handle() {\n        elgg_trigger_event_results('hook', 'type', [], 'default');\n    }\n}\n",
            'elgg-plugin.php' => "<?php\nreturn ['hooks' => []];",
        ]);

        try {
            $result = $this->verifier->verify($dir, '4.x');
            $this->assertFalse($result->passed);

            $errors = $result->errors();
            $this->assertNotEmpty($errors);

            $messages = array_map(fn($v) => $v->message, $errors);
            $this->assertStringContainsString('elgg_trigger_event_results', implode(' ', $messages));
        } finally {
            $this->removeDir($dir);
        }
    }

    public function testCatchesEsmImportIn3xTarget(): void
    {
        // AMD→ESM sweep leaking elgg_import_esm() (6.x) onto a 3.x branch —
        // a real chain-contamination case (bd elgg-migrate-xs2g6).
        $dir = $this->makePluginDir([
            'views/default/menu.php' => "<?php\nelgg_import_esm('navigation/menu/folders');\n",
            'elgg-plugin.php' => "<?php\nreturn [];",
        ]);

        try {
            $result = $this->verifier->verify($dir, '3.x');
            $this->assertFalse($result->passed);
            $messages = array_map(fn($v) => $v->message, $result->errors());
            $this->assertStringContainsString('elgg_import_esm', implode(' ', $messages));
        } finally {
            $this->removeDir($dir);
        }
    }

    public function testEsmImportAllowedIn6xTarget(): void
    {
        // ESM is valid from 6.x — must NOT be flagged when targeting 6.x.
        $dir = $this->makePluginDir([
            'views/default/menu.php' => "<?php\nelgg_import_esm('navigation/menu/folders');\n",
            'elgg-plugin.php' => "<?php\nreturn [];",
        ]);

        try {
            $result = $this->verifier->verify($dir, '6.x');
            foreach ($result->errors() as $e) {
                $this->assertStringNotContainsString('elgg_import_esm', $e->message);
            }
            $this->assertTrue(true);
        } finally {
            $this->removeDir($dir);
        }
    }

    public function testCatches4xRenameIn3xTarget(): void
    {
        // elgg_-prefixed renames that don't exist in 3.x.
        $dir = $this->makePluginDir([
            'classes/MyPlugin/Field.php' => "<?php\nnamespace MyPlugin;\nclass Field {\n    public function lang() {\n        return elgg_get_current_language();\n    }\n    public function flags(\$v) {\n        return elgg_string_to_array(\$v);\n    }\n}\n",
            'elgg-plugin.php' => "<?php\nreturn [];",
        ]);

        try {
            $result = $this->verifier->verify($dir, '3.x');
            $this->assertFalse($result->passed);
            $messages = implode(' ', array_map(fn($v) => $v->message, $result->errors()));
            $this->assertStringContainsString('elgg_get_current_language', $messages);
            $this->assertStringContainsString('elgg_string_to_array', $messages);
        } finally {
            $this->removeDir($dir);
        }
    }

    public function testIgnoresElggEventTypeHintIn4x(): void
    {
        // \Elgg\Event has existed since 3.x for typed event handlers.
        // We cannot tell from the type hint alone whether it's a 4.x event handler
        // or a 5.x hook handler, so we don't flag it.
        $dir = $this->makePluginDir([
            'classes/MyPlugin/Events.php' => "<?php\nnamespace MyPlugin;\nuse Elgg\\Event;\nclass Events {\n    public static function onCreate(\\Elgg\\Event \$event) {\n        return true;\n    }\n}\n",
            'elgg-plugin.php' => "<?php\nreturn ['hooks' => [], 'events' => []];",
        ]);

        try {
            $result = $this->verifier->verify($dir, '4.x');
            $errors = $result->errors();
            // Should have no errors related to Elgg\Event type hint
            foreach ($errors as $e) {
                $this->assertStringNotContainsString('Elgg\\Event', $e->message);
            }
            $this->assertTrue(true);
        } finally {
            $this->removeDir($dir);
        }
    }

    public function testCatchesStartPhpIn4xTarget(): void
    {
        $dir = $this->makePluginDir([
            'start.php' => "<?php\n// leftover",
            'elgg-plugin.php' => "<?php\nreturn ['hooks' => []];",
        ]);

        try {
            $result = $this->verifier->verify($dir, '4.x');
            $this->assertFalse($result->passed);

            $errors = $result->errors();
            $found = false;
            foreach ($errors as $e) {
                if (str_contains($e->message, 'start.php')) {
                    $found = true;
                    break;
                }
            }
            $this->assertTrue($found, 'Expected violation for start.php existing in 4.x plugin');
        } finally {
            $this->removeDir($dir);
        }
    }

    public function testCatchesHookEventConfusionIn4x(): void
    {
        $dir = $this->makePluginDir([
            'elgg-plugin.php' => "<?php\nreturn [\n    'events' => [\n        'register' => [\n            'menu:entity' => [\n                'MyPlugin\\Menus::entityMenu' => [],\n            ],\n        ],\n    ],\n];",
        ]);

        try {
            $result = $this->verifier->verify($dir, '4.x');

            // Should find hook-event confusion for 'register' under 'events'
            $violations = $result->violations;
            $confusionViolations = array_filter(
                $violations,
                fn($v) => $v->category === 'hook-event-confusion',
            );
            $this->assertNotEmpty($confusionViolations, 'Expected hook-event confusion violation for register under events key');
        } finally {
            $this->removeDir($dir);
        }
    }

    public function testCatchesEventsOnlyConfigIn4x(): void
    {
        $dir = $this->makePluginDir([
            'elgg-plugin.php' => "<?php\nreturn [\n    'events' => [\n        'create' => [\n            'object' => [],\n        ],\n    ],\n];",
        ]);

        try {
            $result = $this->verifier->verify($dir, '4.x');

            $warnings = $result->warnings();
            $found = false;
            foreach ($warnings as $w) {
                if (str_contains($w->message, "'events' key but no 'hooks' key")) {
                    $found = true;
                    break;
                }
            }
            $this->assertTrue($found, 'Expected warning about events-only config in 4.x');
        } finally {
            $this->removeDir($dir);
        }
    }

    public function testHooksAfterEventsBlockNotFlagged(): void
    {
        // Regression: hook names under a 'hooks' block that appears AFTER the
        // 'events' block must NOT be flagged. The inEventsBlock flag must reset
        // when the events array ends (on the sibling 'hooks' key).
        $dir = $this->makePluginDir([
            'elgg-plugin.php' => "<?php\nreturn [\n    'events' => [\n        'create' => [\n            'object' => [],\n        ],\n    ],\n    'hooks' => [\n        'register' => [\n            'menu:entity' => [],\n        ],\n        'prepare' => [\n            'notification:publish:object:foo' => [],\n        ],\n    ],\n];",
        ]);

        try {
            $result = $this->verifier->verify($dir, '4.x');
            $confusion = array_filter($result->violations, fn($v) => $v->category === 'hook-event-confusion');
            $this->assertEmpty($confusion, 'register/prepare under a hooks block after events must not be flagged');
        } finally {
            $this->removeDir($dir);
        }
    }

    // --- Clean plugin passes ---

    public function testClean4xPluginPasses(): void
    {
        $dir = $this->makePluginDir([
            'elgg-plugin.php' => "<?php\nreturn [\n    'hooks' => [\n        'register' => [\n            'menu:entity' => [\n                'MyPlugin\\Menus::class . ::entityMenu' => [],\n            ],\n        ],\n    ],\n    'events' => [\n        'create' => [\n            'object' => [],\n        ],\n    ],\n];",
            'classes/MyPlugin/Menus.php' => "<?php\nnamespace MyPlugin;\nclass Menus {\n    public static function entityMenu(\\Elgg\\Hook \$hook) {\n        return \$hook->getValue();\n    }\n}\n",
        ]);

        try {
            $result = $this->verifier->verify($dir, '4.x');
            // Should have no errors (may have warnings from register under events)
            $errors = $result->errors();
            // Filter out hook-event confusion since the test has register under events
            $nonConfusionErrors = array_filter(
                $errors,
                fn($v) => $v->category !== 'hook-event-confusion'
            );
            $this->assertEmpty($nonConfusionErrors, 'Clean 4.x plugin should have no version-boundary errors');
        } finally {
            $this->removeDir($dir);
        }
    }

    // --- 3.x target ---

    public function testClean3xPluginPasses(): void
    {
        $dir = $this->makePluginDir([
            'start.php' => "<?php\nreturn function() {};",
            'elgg-plugin.php' => "<?php\nreturn ['routes' => []];",
            'manifest.xml' => '<plugin_manifest/>',
        ]);

        try {
            $result = $this->verifier->verify($dir, '3.x');
            $this->assertTrue($result->passed);
        } finally {
            $this->removeDir($dir);
        }
    }

    public function testWarnsAboutMissingStartPhpIn3x(): void
    {
        $dir = $this->makePluginDir([
            'elgg-plugin.php' => "<?php\nreturn [];",
        ]);

        try {
            $result = $this->verifier->verify($dir, '3.x');
            $warnings = $result->warnings();
            $found = false;
            foreach ($warnings as $w) {
                if (str_contains($w->message, 'start.php should still exist')) {
                    $found = true;
                    break;
                }
            }
            $this->assertTrue($found, 'Expected warning about missing start.php in 3.x');
        } finally {
            $this->removeDir($dir);
        }
    }

    // --- Result helpers ---

    public function testResultErrorsAndWarningsSeparation(): void
    {
        $dir = $this->makePluginDir([
            'start.php' => "<?php\n// leftover",
            'elgg-plugin.php' => "<?php\nreturn [\n    'events' => [\n        'view' => [\n            'all' => [],\n        ],\n    ],\n];",
            'classes/MyPlugin/Bad.php' => "<?php\nelgg_trigger_event_results('a','b',[]);\n",
        ]);

        try {
            $result = $this->verifier->verify($dir, '4.x');
            $this->assertNotEmpty($result->errors());
            $this->assertNotEmpty($result->warnings());
        } finally {
            $this->removeDir($dir);
        }
    }

    // --- removed-function check (bd elgg-migrate-abyju) ---

    public function testCatchesRemovedHookTriggerIn6xTarget(): void
    {
        // elgg_trigger_plugin_hook was deprecated in 5.x, REMOVED in 6.x.
        // The shape-based completeness gate is blind to this; --verify must catch it.
        $dir = $this->makePluginDir([
            'classes/MyPlugin/Graph.php' => "<?php\nnamespace MyPlugin;\nclass Graph {\n    public function run() {\n        return elgg_trigger_plugin_hook('aliases', 'graph', null, []);\n    }\n}\n",
            'elgg-plugin.php' => "<?php\nreturn [];",
        ]);

        try {
            $result = $this->verifier->verify($dir, '6.x');
            $this->assertFalse($result->passed);
            $messages = array_map(fn($v) => $v->message, $result->errors());
            $joined = implode(' ', $messages);
            $this->assertStringContainsString('elgg_trigger_plugin_hook', $joined);
            // The replacement hint must point at the value-returning event fn,
            // NOT elgg_trigger_event (3-arg, returns bool).
            $this->assertStringContainsString('elgg_trigger_event_results', $joined);
        } finally {
            $this->removeDir($dir);
        }
    }

    public function testCatchesLegacyRemovedFunctionsIn6xTarget(): void
    {
        // 2.x-era functions that leaked onto a 6.x branch (the never-migrated
        // a real custom-plugin backlog).
        $dir = $this->makePluginDir([
            'actions/save.php' => "<?php\nregister_error('nope');\nforward('/');\n",
            'elgg-plugin.php' => "<?php\nreturn [];",
        ]);

        try {
            $result = $this->verifier->verify($dir, '6.x');
            $this->assertFalse($result->passed);
            $cats = array_map(fn($v) => $v->category, $result->errors());
            $this->assertContains('removed-function', $cats);
            $joined = implode(' ', array_map(fn($v) => $v->message, $result->errors()));
            $this->assertStringContainsString('register_error', $joined);
            $this->assertStringContainsString('forward', $joined);
        } finally {
            $this->removeDir($dir);
        }
    }

    public function testDoesNotFlagReplacementOrCommentOrMethod(): void
    {
        // The correct replacements + comment mentions + method calls of the
        // same name must NOT be flagged.
        $dir = $this->makePluginDir([
            'actions/save.php' => "<?php\n// register_error() was removed — using the new API\nelgg_register_error_message('ok');\n\$svc->forward('/x');\n",
            'elgg-plugin.php' => "<?php\nreturn [];",
        ]);

        try {
            $result = $this->verifier->verify($dir, '6.x');
            foreach ($result->errors() as $e) {
                $this->assertNotSame('removed-function', $e->category, "unexpected removed-function flag: {$e->message}");
            }
            $this->assertTrue(true);
        } finally {
            $this->removeDir($dir);
        }
    }

    public function testCatchesImplementsBatchIn6xTarget(): void
    {
        // Elgg\Upgrade\Batch became an abstract class in 6.x; `implements Batch`
        // fatals on boot. The type still exists, so removed-function + shape gates
        // miss it — this is the verify-migration-chain.sh 5x->6x catch (2026-06-05).
        $dir = $this->makePluginDir([
            'classes/Acme/Upgrades/EncodeSettingsAsJson.php' =>
                "<?php\nnamespace Acme\\Upgrades;\n\nuse Elgg\\Upgrade\\Batch;\nuse Elgg\\Upgrade\\Result;\n\nclass EncodeSettingsAsJson implements Batch {\n}\n",
            'elgg-plugin.php' => "<?php\nreturn [];",
        ]);

        try {
            $result = $this->verifier->verify($dir, '6.x');
            $this->assertFalse($result->passed);
            $cats = array_map(fn($v) => $v->category, $result->errors());
            $this->assertContains('changed-class-contract', $cats);
            $joined = implode(' ', array_map(fn($v) => $v->message, $result->errors()));
            $this->assertStringContainsString('AsynchronousUpgrade', $joined);
        } finally {
            $this->removeDir($dir);
        }
    }

    public function testExtendsAsynchronousUpgradePassesIn7xTarget(): void
    {
        // The CORRECT 6.x+ form must not be flagged (cumulative: applies at 7.x too).
        $dir = $this->makePluginDir([
            'classes/Acme/Upgrades/EncodeSettingsAsJson.php' =>
                "<?php\nnamespace Acme\\Upgrades;\n\nuse Elgg\\Upgrade\\AsynchronousUpgrade;\nuse Elgg\\Upgrade\\Result;\n\nclass EncodeSettingsAsJson extends AsynchronousUpgrade {\n}\n",
            'elgg-plugin.php' => "<?php\nreturn [];",
        ]);

        try {
            $result = $this->verifier->verify($dir, '7.x');
            foreach ($result->errors() as $e) {
                $this->assertNotSame('changed-class-contract', $e->category, "unexpected contract flag: {$e->message}");
            }
            $this->assertTrue(true);
        } finally {
            $this->removeDir($dir);
        }
    }

    public function testImplementsBatchAllowedIn5xTarget(): void
    {
        // Below the 6.x boundary, Batch is still an interface — `implements Batch`
        // is correct and must NOT be flagged.
        $dir = $this->makePluginDir([
            'classes/Acme/Upgrades/EncodeSettingsAsJson.php' =>
                "<?php\nnamespace Acme\\Upgrades;\n\nuse Elgg\\Upgrade\\Batch;\n\nclass EncodeSettingsAsJson implements Batch {\n}\n",
            'elgg-plugin.php' => "<?php\nreturn [];",
        ]);

        try {
            $result = $this->verifier->verify($dir, '5.x');
            $cats = array_map(fn($v) => $v->category, $result->violations);
            $this->assertNotContains('changed-class-contract', $cats);
        } finally {
            $this->removeDir($dir);
        }
    }

    // --- dangling upgrade-class check (bd elgg-migrate-kg3kb) ---

    public function testCatchesDanglingUpgradeClass(): void
    {
        // Forward-port deleted the class but left the registration. A bare
        // Foo::class doesn't autoload, so pages render — but elgg-cli upgrade
        // aborts ("Upgrade class … was not found"). Real-world 7x case.
        $dir = $this->makePluginDir([
            'elgg-plugin.php' => "<?php\nreturn [\n    'upgrades' => [\n        \\Acme\\Upgrades\\MigrateSwitchSettings::class,\n    ],\n];",
        ]);

        try {
            $result = $this->verifier->verify($dir, '7.x');
            $this->assertFalse($result->passed);
            $cats = array_map(fn($v) => $v->category, $result->errors());
            $this->assertContains('dangling-upgrade-class', $cats);
            $joined = implode(' ', array_map(fn($v) => $v->message, $result->errors()));
            $this->assertStringContainsString('MigrateSwitchSettings', $joined);
        } finally {
            $this->removeDir($dir);
        }
    }

    public function testCatchesDanglingUpgradeClassStringLiteral(): void
    {
        // Same gap registered as a quoted string instead of ::class.
        $dir = $this->makePluginDir([
            'elgg-plugin.php' => "<?php\nreturn [\n    'upgrades' => [\n        'Acme\\\\Upgrades\\\\GoneAway',\n    ],\n];",
        ]);

        try {
            $result = $this->verifier->verify($dir, '6.x');
            $cats = array_map(fn($v) => $v->category, $result->errors());
            $this->assertContains('dangling-upgrade-class', $cats);
        } finally {
            $this->removeDir($dir);
        }
    }

    public function testResolvedUpgradeClassNotFlagged(): void
    {
        // The class exists at its canonical classes/ path — must NOT be flagged.
        $dir = $this->makePluginDir([
            'elgg-plugin.php' => "<?php\nreturn [\n    'upgrades' => [\n        \\Acme\\Upgrades\\EncodeAsJson::class,\n    ],\n];",
            'classes/Acme/Upgrades/EncodeAsJson.php' =>
                "<?php\nnamespace Acme\\Upgrades;\nuse Elgg\\Upgrade\\AsynchronousUpgrade;\nclass EncodeAsJson extends AsynchronousUpgrade {\n}\n",
        ]);

        try {
            $result = $this->verifier->verify($dir, '7.x');
            $cats = array_map(fn($v) => $v->category, $result->violations);
            $this->assertNotContains('dangling-upgrade-class', $cats);
        } finally {
            $this->removeDir($dir);
        }
    }

    public function testRelocatedUpgradeClassNotFlagged(): void
    {
        // Class lives off the canonical path (custom composer PSR-4 prefix) but a
        // declaration exists — conservative resolver must accept it.
        $dir = $this->makePluginDir([
            'elgg-plugin.php' => "<?php\nreturn [\n    'upgrades' => [\n        \\Acme\\Upgrades\\Moved::class,\n    ],\n];",
            'src/Upgrades/Moved.php' =>
                "<?php\nnamespace Acme\\Upgrades;\nclass Moved {\n}\n",
        ]);

        try {
            $result = $this->verifier->verify($dir, '7.x');
            $cats = array_map(fn($v) => $v->category, $result->violations);
            $this->assertNotContains('dangling-upgrade-class', $cats);
        } finally {
            $this->removeDir($dir);
        }
    }

    public function testNoUpgradesKeyIsClean(): void
    {
        $dir = $this->makePluginDir([
            'elgg-plugin.php' => "<?php\nreturn [\n    'hooks' => [\n        'register' => ['menu:entity' => []],\n    ],\n];",
        ]);

        try {
            $result = $this->verifier->verify($dir, '7.x');
            $cats = array_map(fn($v) => $v->category, $result->violations);
            $this->assertNotContains('dangling-upgrade-class', $cats);
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
