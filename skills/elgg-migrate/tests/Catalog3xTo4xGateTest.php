<?php

declare(strict_types=1);

namespace ElggMigrate\Tests;

use ElggMigrate\PostMigrationVerifier;
use PHPUnit\Framework\TestCase;

/**
 * Catalog-driven proof that the migration gates DETECT every '3.x → 4.x' failure
 * class in references/migration-failure-catalog.md that is marked as gate-covered
 * (gate: YES / partial). Each failure class gets two fixtures:
 *   - a minimal DIRTY fixture carrying the failure signature — asserted FLAGGED
 *   - a minimal CLEAN fixture using the documented fix — asserted NOT flagged
 *
 * Only the catalog classes handled by a static gate are covered here. The rows
 * marked gate: rule (FC-3x4x-10/14/15) are AST rules exercised by the Rules/
 * suite; the rows marked gate: NO (FC-3x4x-11/12/13) have no static gate yet.
 */
final class Catalog3xTo4xGateTest extends TestCase
{
    private PostMigrationVerifier $verifier;

    protected function setUp(): void
    {
        $this->verifier = new PostMigrationVerifier();
    }

    // --- FC-3x4x-01 — start.php / activate.php / deactivate.php must be DELETED ---

    public function testFc01FlagsForbiddenStartActivateDeactivate(): void
    {
        foreach (['start.php', 'activate.php', 'deactivate.php'] as $forbidden) {
            $dir = $this->makePluginDir([
                $forbidden => "<?php\n// leftover 3.x bootstrap side effects\n",
                'elgg-plugin.php' => "<?php\nreturn ['bootstrap' => 'MyPlugin\\Bootstrap'];",
            ]);

            try {
                $result = $this->verifier->verify($dir, '4.x');
                $this->assertFalse($result->passed, "{$forbidden} should fail --verify at 4.x");
                $cats = array_map(fn($v) => $v->category, $result->errors());
                $this->assertContains('forbidden-file', $cats, "Expected forbidden-file for {$forbidden}");
                $joined = implode(' ', array_map(fn($v) => $v->message, $result->errors()));
                $this->assertStringContainsString($forbidden, $joined);
            } finally {
                $this->removeDir($dir);
            }
        }
    }

    public function testFc01CleanPluginWithoutForbiddenFilesNotFlagged(): void
    {
        // Fix: side effects moved into Bootstrap::activate(); no start/activate/deactivate files.
        $dir = $this->makePluginDir([
            'elgg-plugin.php' => "<?php\nreturn ['bootstrap' => 'MyPlugin\\Bootstrap'];",
            'classes/MyPlugin/Bootstrap.php' => "<?php\nnamespace MyPlugin;\nuse Elgg\\DefaultPluginBootstrap;\nclass Bootstrap extends DefaultPluginBootstrap {\n    public function activate(): void {}\n}\n",
        ]);

        try {
            $result = $this->verifier->verify($dir, '4.x');
            $cats = array_map(fn($v) => $v->category, $result->violations);
            $this->assertNotContains('forbidden-file', $cats);
        } finally {
            $this->removeDir($dir);
        }
    }

    // --- FC-3x4x-02 — CSS/JS registration functions removed ---

    public function testFc02FlagsRemovedCssRegistration(): void
    {
        $dir = $this->makePluginDir([
            'classes/MyPlugin/Init.php' => "<?php\nnamespace MyPlugin;\nclass Init {\n    public static function boot() {\n        elgg_register_css('myplugin', elgg_get_simplecache_url('myplugin.css'));\n        elgg_load_css('myplugin');\n    }\n}\n",
            'elgg-plugin.php' => "<?php\nreturn [];",
        ]);

        try {
            $result = $this->verifier->verify($dir, '4.x');
            $this->assertFalse($result->passed);
            $cats = array_map(fn($v) => $v->category, $result->errors());
            $this->assertContains('removed-function', $cats);
            $joined = implode(' ', array_map(fn($v) => $v->message, $result->errors()));
            $this->assertStringContainsString('elgg_register_css', $joined);
            $this->assertStringContainsString('elgg_load_css', $joined);
        } finally {
            $this->removeDir($dir);
        }
    }

    public function testFc02CleanExternalFileRegistrationNotFlagged(): void
    {
        // Fix: elgg_register_external_file() / elgg_load_external_file() — both exist in 4.x.
        $dir = $this->makePluginDir([
            'classes/MyPlugin/Init.php' => "<?php\nnamespace MyPlugin;\nclass Init {\n    public static function boot() {\n        elgg_register_external_file('css', 'myplugin', elgg_get_simplecache_url('myplugin.css'));\n        elgg_load_external_file('css', 'myplugin');\n    }\n}\n",
            'elgg-plugin.php' => "<?php\nreturn [];",
        ]);

        try {
            $result = $this->verifier->verify($dir, '4.x');
            foreach ($result->errors() as $e) {
                $this->assertNotSame('removed-function', $e->category, "unexpected removed-function flag: {$e->message}");
            }
            $this->assertTrue(true);
        } finally {
            $this->removeDir($dir);
        }
    }

    // --- FC-3x4x-03 — elgg_format_attributes() removed ---

    public function testFc03FlagsRemovedFormatAttributes(): void
    {
        $dir = $this->makePluginDir([
            'views/default/myplugin/wrapper.php' => "<?php\necho '<div ' . elgg_format_attributes(\$vars['attrs']) . '>';\n",
            'elgg-plugin.php' => "<?php\nreturn [];",
        ]);

        try {
            $result = $this->verifier->verify($dir, '4.x');
            $this->assertFalse($result->passed);
            $cats = array_map(fn($v) => $v->category, $result->errors());
            $this->assertContains('removed-function', $cats);
            $joined = implode(' ', array_map(fn($v) => $v->message, $result->errors()));
            $this->assertStringContainsString('elgg_format_attributes', $joined);
        } finally {
            $this->removeDir($dir);
        }
    }

    public function testFc03CleanFormatterServiceNotFlagged(): void
    {
        // Fix: _elgg_services()->html_formatter->formatAttributes($attrs) — a method call.
        $dir = $this->makePluginDir([
            'views/default/myplugin/wrapper.php' => "<?php\necho '<div ' . _elgg_services()->html_formatter->formatAttributes(\$vars['attrs']) . '>';\n",
            'elgg-plugin.php' => "<?php\nreturn [];",
        ]);

        try {
            $result = $this->verifier->verify($dir, '4.x');
            foreach ($result->errors() as $e) {
                $this->assertNotSame('removed-function', $e->category, "unexpected removed-function flag: {$e->message}");
            }
            $this->assertTrue(true);
        } finally {
            $this->removeDir($dir);
        }
    }

    // --- FC-3x4x-04 — ElggFile::detectMimeType() removed (static form; gate: partial) ---

    public function testFc04FlagsStaticDetectMimeType(): void
    {
        $dir = $this->makePluginDir([
            'classes/MyPlugin/Files.php' => "<?php\nnamespace MyPlugin;\nclass Files {\n    public function mime(\$path) {\n        return ElggFile::detectMimeType(\$path);\n    }\n}\n",
            'elgg-plugin.php' => "<?php\nreturn [];",
        ]);

        try {
            $result = $this->verifier->verify($dir, '4.x');
            $this->assertFalse($result->passed);
            $cats = array_map(fn($v) => $v->category, $result->errors());
            $this->assertContains('removed-function', $cats);
            $joined = implode(' ', array_map(fn($v) => $v->message, $result->errors()));
            $this->assertStringContainsString('ElggFile::detectMimeType', $joined);
        } finally {
            $this->removeDir($dir);
        }
    }

    public function testFc04CleanMimeContentTypeNotFlagged(): void
    {
        // Fix: mime_content_type($path) guarded by is_file($path).
        $dir = $this->makePluginDir([
            'classes/MyPlugin/Files.php' => "<?php\nnamespace MyPlugin;\nclass Files {\n    public function mime(\$path) {\n        return is_file(\$path) ? mime_content_type(\$path) : null;\n    }\n}\n",
            'elgg-plugin.php' => "<?php\nreturn [];",
        ]);

        try {
            $result = $this->verifier->verify($dir, '4.x');
            foreach ($result->errors() as $e) {
                $this->assertNotSame('removed-function', $e->category, "unexpected removed-function flag: {$e->message}");
            }
            $this->assertTrue(true);
        } finally {
            $this->removeDir($dir);
        }
    }

    // --- FC-3x4x-05 — access_get_show_hidden_status() removed ---

    public function testFc05FlagsRemovedAccessShowHiddenStatus(): void
    {
        $dir = $this->makePluginDir([
            'classes/MyPlugin/Repo.php' => "<?php\nnamespace MyPlugin;\nclass Repo {\n    public function fetch() {\n        \$hidden = access_get_show_hidden_status();\n        return \$hidden;\n    }\n}\n",
            'elgg-plugin.php' => "<?php\nreturn [];",
        ]);

        try {
            $result = $this->verifier->verify($dir, '4.x');
            $this->assertFalse($result->passed);
            $cats = array_map(fn($v) => $v->category, $result->errors());
            $this->assertContains('removed-function', $cats);
            $joined = implode(' ', array_map(fn($v) => $v->message, $result->errors()));
            $this->assertStringContainsString('access_get_show_hidden_status', $joined);
        } finally {
            $this->removeDir($dir);
        }
    }

    public function testFc05CleanElggCallNotFlagged(): void
    {
        // Fix: elgg_call(ELGG_SHOW_DISABLED_ENTITIES | ELGG_IGNORE_ACCESS, fn) — exists in 4.x.
        $dir = $this->makePluginDir([
            'classes/MyPlugin/Repo.php' => "<?php\nnamespace MyPlugin;\nclass Repo {\n    public function fetch() {\n        return elgg_call(ELGG_SHOW_DISABLED_ENTITIES | ELGG_IGNORE_ACCESS, function () {\n            return elgg_get_entities();\n        });\n    }\n}\n",
            'elgg-plugin.php' => "<?php\nreturn [];",
        ]);

        try {
            $result = $this->verifier->verify($dir, '4.x');
            foreach ($result->errors() as $e) {
                $this->assertNotSame('removed-function', $e->category, "unexpected removed-function flag: {$e->message}");
            }
            $this->assertTrue(true);
        } finally {
            $this->removeDir($dir);
        }
    }

    // --- FC-3x4x-06 — create_metadata() / update_metadata() removed ---

    public function testFc06FlagsRemovedMetadataFunctions(): void
    {
        $dir = $this->makePluginDir([
            'classes/MyPlugin/Meta.php' => "<?php\nnamespace MyPlugin;\nclass Meta {\n    public function write(\$guid) {\n        create_metadata(\$guid, 'foo', 'bar');\n        update_metadata(123, 'foo', 'baz');\n    }\n}\n",
            'elgg-plugin.php' => "<?php\nreturn [];",
        ]);

        try {
            $result = $this->verifier->verify($dir, '4.x');
            $this->assertFalse($result->passed);
            $cats = array_map(fn($v) => $v->category, $result->errors());
            $this->assertContains('removed-function', $cats);
            $joined = implode(' ', array_map(fn($v) => $v->message, $result->errors()));
            $this->assertStringContainsString('create_metadata', $joined);
            $this->assertStringContainsString('update_metadata', $joined);
        } finally {
            $this->removeDir($dir);
        }
    }

    public function testFc06CleanMetadataTableServiceNotFlagged(): void
    {
        // Fix: new \ElggMetadata + metadataTable->create()/->get()->save() — method calls.
        $dir = $this->makePluginDir([
            'classes/MyPlugin/Meta.php' => "<?php\nnamespace MyPlugin;\nclass Meta {\n    public function write(\$guid) {\n        \$md = new \\ElggMetadata();\n        _elgg_services()->metadataTable->create(\$md, false);\n    }\n}\n",
            'elgg-plugin.php' => "<?php\nreturn [];",
        ]);

        try {
            $result = $this->verifier->verify($dir, '4.x');
            foreach ($result->errors() as $e) {
                $this->assertNotSame('removed-function', $e->category, "unexpected removed-function flag: {$e->message}");
            }
            $this->assertTrue(true);
        } finally {
            $this->removeDir($dir);
        }
    }

    // --- FC-3x4x-07 — Elgg\Di\ServiceFacade trait removed ---

    public function testFc07FlagsServiceFacadeUse(): void
    {
        $dir = $this->makePluginDir([
            'classes/MyPlugin/Service.php' => "<?php\nnamespace MyPlugin;\nuse Elgg\\Di\\ServiceFacade;\nclass Service {\n    use ServiceFacade;\n    public static function name() {\n        return 'myplugin.service';\n    }\n}\n",
            'elgg-plugin.php' => "<?php\nreturn [];",
        ]);

        try {
            $result = $this->verifier->verify($dir, '4.x');
            $this->assertFalse($result->passed);
            $cats = array_map(fn($v) => $v->category, $result->errors());
            $this->assertContains('changed-class-contract', $cats);
            $joined = implode(' ', array_map(fn($v) => $v->message, $result->errors()));
            $this->assertStringContainsString('ServiceFacade', $joined);
        } finally {
            $this->removeDir($dir);
        }
    }

    public function testFc07CleanDiRegisteredServiceNotFlagged(): void
    {
        // Fix: plain service class, registered via DI\create() in elgg-services.php.
        $dir = $this->makePluginDir([
            'classes/MyPlugin/Service.php' => "<?php\nnamespace MyPlugin;\nclass Service {\n    public function run(): void {}\n}\n",
            'elgg-services.php' => "<?php\nuse function DI\\create;\nreturn [\n    'myplugin.service' => create(\\MyPlugin\\Service::class),\n];",
            'elgg-plugin.php' => "<?php\nreturn [];",
        ]);

        try {
            $result = $this->verifier->verify($dir, '4.x');
            $cats = array_map(fn($v) => $v->category, $result->violations);
            $this->assertNotContains('changed-class-contract', $cats);
        } finally {
            $this->removeDir($dir);
        }
    }

    // --- FC-3x4x-08 — Elgg\Notifications\NotificationEvent is now an interface ---

    public function testFc08FlagsNewNotificationEvent(): void
    {
        $dir = $this->makePluginDir([
            'classes/MyPlugin/Notify.php' => "<?php\nnamespace MyPlugin;\nuse Elgg\\Notifications\\NotificationEvent;\nclass Notify {\n    public function fire(\$object) {\n        return new NotificationEvent(\$object, 'create');\n    }\n}\n",
            'elgg-plugin.php' => "<?php\nreturn [];",
        ]);

        try {
            $result = $this->verifier->verify($dir, '4.x');
            $this->assertFalse($result->passed);
            $cats = array_map(fn($v) => $v->category, $result->errors());
            $this->assertContains('changed-class-contract', $cats);
            $joined = implode(' ', array_map(fn($v) => $v->message, $result->errors()));
            $this->assertStringContainsString('NotificationEvent', $joined);
        } finally {
            $this->removeDir($dir);
        }
    }

    public function testFc08CleanConcreteSubscriptionEventNotFlagged(): void
    {
        // Fix: instantiate a concrete impl — SubscriptionNotificationEvent.
        $dir = $this->makePluginDir([
            'classes/MyPlugin/Notify.php' => "<?php\nnamespace MyPlugin;\nuse Elgg\\Notifications\\SubscriptionNotificationEvent;\nclass Notify {\n    public function fire(\$object) {\n        return new SubscriptionNotificationEvent(\$object, 'create');\n    }\n}\n",
            'elgg-plugin.php' => "<?php\nreturn [];",
        ]);

        try {
            $result = $this->verifier->verify($dir, '4.x');
            $cats = array_map(fn($v) => $v->category, $result->violations);
            $this->assertNotContains('changed-class-contract', $cats);
        } finally {
            $this->removeDir($dir);
        }
    }

    // --- FC-3x4x-09 — Hook/event confusion (view/register/prepare/route are HOOKS in 4.x) ---

    public function testFc09FlagsHookNameUnderEventsKey(): void
    {
        $dir = $this->makePluginDir([
            'elgg-plugin.php' => "<?php\nreturn [\n    'events' => [\n        'view' => [\n            'all' => [\n                'MyPlugin\\Views::rewrite' => [],\n            ],\n        ],\n    ],\n];",
        ]);

        try {
            $result = $this->verifier->verify($dir, '4.x');
            $confusion = array_filter($result->violations, fn($v) => $v->category === 'hook-event-confusion');
            $this->assertNotEmpty($confusion, "Expected hook-event-confusion for 'view' under events key");
        } finally {
            $this->removeDir($dir);
        }
    }

    public function testFc09CleanHookNameUnderHooksKeyNotFlagged(): void
    {
        // Fix: register hook names under the 'hooks' key.
        $dir = $this->makePluginDir([
            'elgg-plugin.php' => "<?php\nreturn [\n    'hooks' => [\n        'view' => [\n            'all' => [\n                'MyPlugin\\Views::rewrite' => [],\n            ],\n        ],\n    ],\n    'events' => [\n        'create' => [\n            'object' => [],\n        ],\n    ],\n];",
        ]);

        try {
            $result = $this->verifier->verify($dir, '4.x');
            $confusion = array_filter($result->violations, fn($v) => $v->category === 'hook-event-confusion');
            $this->assertEmpty($confusion, "hook names under a 'hooks' key must not be flagged");
        } finally {
            $this->removeDir($dir);
        }
    }

    // --- Helpers (mirrors PostMigrationVerifierTest) ---

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
