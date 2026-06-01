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
        // the bodyology chain contamination (bd elgg-migrate-xs2g6).
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
