<?php

declare(strict_types=1);

namespace ElggMigrate\Tests;

use ElggMigrate\PostMigrationVerifier;
use PHPUnit\Framework\TestCase;

/**
 * Proves the migration gates DETECT every 5.x -> 6.x failure class catalogued in
 * references/migration-failure-catalog.md (FC-5x6x-01 .. FC-5x6x-05).
 *
 * Each failure class gets a FLAGGED fixture (a minimal plugin dir carrying the
 * failure signature, asserted to be flagged by the relevant gate) plus a CLEAN
 * fixture (the correctly-migrated form, asserted NOT flagged). Style mirrors
 * PostMigrationVerifierTest.
 */
final class FailureCatalog5xTo6xTest extends TestCase
{
    private PostMigrationVerifier $verifier;

    protected function setUp(): void
    {
        $this->verifier = new PostMigrationVerifier();
    }

    // --- FC-5x6x-01 — \Elgg\Hook removed entirely (changed-class-contract) ---

    public function testFc5x6x01FlagsUseElggHookIn6xTarget(): void
    {
        // In 5.x \Elgg\Hook extended \Elgg\Event; in 6.x it is gone. A handler
        // importing it fatals on boot. checkChangedClassContracts must flag it.
        $dir = $this->makePluginDir([
            'classes/Acme/Handlers.php' =>
                "<?php\nnamespace Acme;\n\nuse Elgg\\Hook;\n\nclass Handlers {\n    public static function menu(Hook \$hook) {\n        return \$hook->getValue();\n    }\n}\n",
            'elgg-plugin.php' => "<?php\nreturn [];",
        ]);

        try {
            $result = $this->verifier->verify($dir, '6.x');
            $this->assertFalse($result->passed);
            $cats = array_map(fn($v) => $v->category, $result->errors());
            $this->assertContains('changed-class-contract', $cats);
            $joined = implode(' ', array_map(fn($v) => $v->message, $result->errors()));
            // Fix hint must point at the \Elgg\Event replacement.
            $this->assertStringContainsString('Elgg\\Event', $joined);
        } finally {
            $this->removeDir($dir);
        }
    }

    public function testFc5x6x01CleanElggEventNotFlaggedIn6xTarget(): void
    {
        // The correct 6.x form imports \Elgg\Event — must NOT be flagged. The
        // similarly-named Elgg\HooksRegistrationService\Hook (word-boundary guard)
        // is also present to prove the false-positive guard holds.
        $dir = $this->makePluginDir([
            'classes/Acme/Handlers.php' =>
                "<?php\nnamespace Acme;\n\nuse Elgg\\Event;\nuse Elgg\\HooksRegistrationService\\Hook;\n\nclass Handlers {\n    public static function menu(Event \$event) {\n        return \$event->getValue();\n    }\n}\n",
            'elgg-plugin.php' => "<?php\nreturn [];",
        ]);

        try {
            $result = $this->verifier->verify($dir, '6.x');
            foreach ($result->violations as $v) {
                $this->assertNotSame('changed-class-contract', $v->category, "unexpected contract flag: {$v->message}");
            }
            $this->assertTrue(true);
        } finally {
            $this->removeDir($dir);
        }
    }

    // --- FC-5x6x-02 — plugin-hook procedural functions removed (removed-function) ---

    public function testFc5x6x02FlagsRemovedPluginHookFunctionsIn6xTarget(): void
    {
        $dir = $this->makePluginDir([
            'classes/Acme/Boot.php' =>
                "<?php\nnamespace Acme;\n\nclass Boot {\n    public function run() {\n        elgg_register_plugin_hook_handler('view', 'page/default', [self::class, 'x']);\n        return elgg_trigger_plugin_hook('aliases', 'graph', null, []);\n    }\n}\n",
            'elgg-plugin.php' => "<?php\nreturn [];",
        ]);

        try {
            $result = $this->verifier->verify($dir, '6.x');
            $this->assertFalse($result->passed);
            $cats = array_map(fn($v) => $v->category, $result->errors());
            $this->assertContains('removed-function', $cats);
            $joined = implode(' ', array_map(fn($v) => $v->message, $result->errors()));
            $this->assertStringContainsString('elgg_trigger_plugin_hook', $joined);
            $this->assertStringContainsString('elgg_register_plugin_hook_handler', $joined);
        } finally {
            $this->removeDir($dir);
        }
    }

    public function testFc5x6x02CleanEventApiNotFlaggedIn6xTarget(): void
    {
        // The correct replacements (elgg_register_event_handler / _results) plus
        // the exact replacement for register_error must NOT trip removed-function.
        $dir = $this->makePluginDir([
            'classes/Acme/Boot.php' =>
                "<?php\nnamespace Acme;\n\nclass Boot {\n    public function run() {\n        elgg_register_event_handler('view', 'page/default', [self::class, 'x']);\n        elgg_register_error_message('nope');\n        return elgg_trigger_event_results('aliases', 'graph', [], null);\n    }\n}\n",
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

    // --- FC-5x6x-03 — Seed gained abstract getType()/getCountOptions() in 6.1 ---

    public function testFc5x6x03FlagsSeedMissingAbstractMethodsIn6xTarget(): void
    {
        $dir = $this->makePluginDir([
            'classes/Acme/Seeder.php' =>
                "<?php\nnamespace Acme;\n\nuse Elgg\\Database\\Seeds\\Seed;\n\nclass Seeder extends Seed {\n    public function seed() {}\n    public function unseed() {}\n}\n",
            'elgg-plugin.php' => "<?php\nreturn [];",
        ]);

        try {
            $result = $this->verifier->verify($dir, '6.x');
            $this->assertFalse($result->passed);
            $cats = array_map(fn($v) => $v->category, $result->errors());
            $this->assertContains('seed-abstract-methods', $cats);
            $joined = implode(' ', array_map(fn($v) => $v->message, $result->errors()));
            $this->assertStringContainsString('getType()', $joined);
            $this->assertStringContainsString('getCountOptions()', $joined);
        } finally {
            $this->removeDir($dir);
        }
    }

    public function testFc5x6x03CleanSeedWithBothMethodsNotFlaggedIn6xTarget(): void
    {
        $dir = $this->makePluginDir([
            'classes/Acme/Seeder.php' =>
                "<?php\nnamespace Acme;\n\nuse Elgg\\Database\\Seeds\\Seed;\n\nclass Seeder extends Seed {\n    public static function getType(): string { return 'object'; }\n    public function getCountOptions(): array { return []; }\n    public function seed() {}\n    public function unseed() {}\n}\n",
            'elgg-plugin.php' => "<?php\nreturn [];",
        ]);

        try {
            $result = $this->verifier->verify($dir, '6.x');
            $cats = array_map(fn($v) => $v->category, $result->violations);
            $this->assertNotContains('seed-abstract-methods', $cats);
        } finally {
            $this->removeDir($dir);
        }
    }

    // --- FC-5x6x-04 — Elgg\Upgrade\Batch became an abstract class ---

    public function testFc5x6x04FlagsImplementsBatchIn6xTarget(): void
    {
        $dir = $this->makePluginDir([
            'classes/Acme/Upgrades/EncodeAsJson.php' =>
                "<?php\nnamespace Acme\\Upgrades;\n\nuse Elgg\\Upgrade\\Batch;\n\nclass EncodeAsJson implements Batch {\n}\n",
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

    public function testFc5x6x04CleanExtendsAsynchronousUpgradeNotFlaggedIn6xTarget(): void
    {
        $dir = $this->makePluginDir([
            'classes/Acme/Upgrades/EncodeAsJson.php' =>
                "<?php\nnamespace Acme\\Upgrades;\n\nuse Elgg\\Upgrade\\AsynchronousUpgrade;\n\nclass EncodeAsJson extends AsynchronousUpgrade {\n}\n",
            'elgg-plugin.php' => "<?php\nreturn [];",
        ]);

        try {
            $result = $this->verifier->verify($dir, '6.x');
            foreach ($result->violations as $v) {
                $this->assertNotSame('changed-class-contract', $v->category, "unexpected contract flag: {$v->message}");
            }
            $this->assertTrue(true);
        } finally {
            $this->removeDir($dir);
        }
    }

    // --- FC-5x6x-05 — AMD -> ESM ---

    public function testFc5x6x05FlagsRemovedAmdFunctionsIn6xTarget(): void
    {
        // elgg_load_js / elgg_require_js / elgg_define_js were removed in 6.x.
        $dir = $this->makePluginDir([
            'views/default/acme/init.php' =>
                "<?php\nelgg_define_js('acme/widget', ['src' => 'mod/acme/js/widget.js']);\nelgg_require_js('acme/widget');\n",
            'elgg-plugin.php' => "<?php\nreturn [];",
        ]);

        try {
            $result = $this->verifier->verify($dir, '6.x');
            $this->assertFalse($result->passed);
            $cats = array_map(fn($v) => $v->category, $result->errors());
            $this->assertContains('removed-function', $cats);
            $joined = implode(' ', array_map(fn($v) => $v->message, $result->errors()));
            $this->assertStringContainsString('elgg_require_js', $joined);
            $this->assertStringContainsString('elgg_define_js', $joined);
        } finally {
            $this->removeDir($dir);
        }
    }

    public function testFc5x6x05FlagsFutureEsmApiLeakingOnto5xTarget(): void
    {
        // The inverse chain-contamination case: an AMD->ESM sweep dragged the 6.x
        // ESM registrars (elgg_import_esm/elgg_register_esm) down onto a 5.x branch.
        $dir = $this->makePluginDir([
            'views/default/acme/init.php' =>
                "<?php\nelgg_import_esm('acme/widget');\n",
            'elgg-plugin.php' => "<?php\nreturn [];",
        ]);

        try {
            $result = $this->verifier->verify($dir, '5.x');
            $this->assertFalse($result->passed);
            $cats = array_map(fn($v) => $v->category, $result->errors());
            $this->assertContains('future-version-api', $cats);
            $joined = implode(' ', array_map(fn($v) => $v->message, $result->errors()));
            $this->assertStringContainsString('elgg_import_esm', $joined);
        } finally {
            $this->removeDir($dir);
        }
    }

    public function testFc5x6x05CleanEsmRegistrationNotFlaggedIn6xTarget(): void
    {
        // ESM registration is the correct 6.x form — must NOT be flagged there,
        // neither as removed-function nor as future-version-api.
        $dir = $this->makePluginDir([
            'views/default/acme/init.php' =>
                "<?php\nelgg_register_esm('acme/widget');\nelgg_import_esm('acme/widget');\n",
            'elgg-plugin.php' => "<?php\nreturn [];",
        ]);

        try {
            $result = $this->verifier->verify($dir, '6.x');
            foreach ($result->violations as $v) {
                $this->assertNotSame('future-version-api', $v->category, "unexpected future-api flag: {$v->message}");
                if ($v->category === 'removed-function') {
                    $this->assertStringNotContainsString('elgg_register_esm', $v->message);
                    $this->assertStringNotContainsString('elgg_import_esm', $v->message);
                }
            }
            $this->assertTrue(true);
        } finally {
            $this->removeDir($dir);
        }
    }

    // --- Helpers (mirror PostMigrationVerifierTest) ---

    private function makePluginDir(array $files): string
    {
        $dir = sys_get_temp_dir() . '/elgg-migrate-fc5x6x-' . uniqid();
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
