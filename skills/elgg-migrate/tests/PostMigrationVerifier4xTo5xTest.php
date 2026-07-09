<?php

declare(strict_types=1);

namespace ElggMigrate\Tests;

use ElggMigrate\PostMigrationVerifier;
use PHPUnit\Framework\TestCase;

/**
 * Proves the PostMigrationVerifier gate DETECTS every gated 4.x → 5.x failure
 * class catalogued in references/migration-failure-catalog.md.
 *
 * Each failure class gets a FLAGGED fixture (contains the failure signature —
 * the gate must fail) and a CLEAN fixture (the correct migrated form — the gate
 * must NOT flag it). Style mirrors PostMigrationVerifierTest.
 *
 * Covered:
 *   FC-4x5x-01  add_translation() removed          -> removed-function @ 5.x
 *   FC-4x5x-02  5.x global function removals        -> removed-function @ 5.x
 *   FC-4x5x-03  elgg_trigger_event_results leak     -> future-version-api @ 4.x
 *
 * (FC-4x5x-04..08 ARE statically gated — checkRelocatedSymbols /
 * check5xServiceRemovals / check5xMenuJsApi / check5xSubtypeAssignment /
 * check5xTestMocking — and are exercised by FailureCatalogGateTest and the
 * V4ToV5/AmdRemovedApis rule test, so they are covered there, not here. See the
 * "Gate coverage audit" table at the foot of the failure catalog.)
 */
final class PostMigrationVerifier4xTo5xTest extends TestCase
{
    private PostMigrationVerifier $verifier;

    protected function setUp(): void
    {
        $this->verifier = new PostMigrationVerifier();
    }

    // --- FC-4x5x-01 — add_translation() removed in 5.x -----------------------

    public function testFlagsAddTranslationAt5xTarget(): void
    {
        // 5.x's Translator include-loads the language file and expects the
        // returned array directly; the add_translation() wrapper was removed.
        $dir = $this->makePluginDir([
            'languages/en.php' => "<?php\n\$english = [\n    'myplugin:label' => 'Label',\n];\nadd_translation('en', \$english);\n",
            'elgg-plugin.php' => "<?php\nreturn [];",
        ]);

        try {
            $result = $this->verifier->verify($dir, '5.x');
            $this->assertFalse($result->passed);

            $errors = $result->errors();
            $cats = array_map(fn($v) => $v->category, $errors);
            $this->assertContains('removed-function', $cats);

            $joined = implode(' ', array_map(fn($v) => $v->message, $errors));
            $this->assertStringContainsString('add_translation', $joined);
        } finally {
            $this->removeDir($dir);
        }
    }

    public function testCleanLanguageFileNotFlaggedAt5xTarget(): void
    {
        // The correct 5.x form: return the array directly, no wrapper call.
        $dir = $this->makePluginDir([
            'languages/en.php' => "<?php\nreturn [\n    'myplugin:label' => 'Label',\n];\n",
            'elgg-plugin.php' => "<?php\nreturn [];",
        ]);

        try {
            $result = $this->verifier->verify($dir, '5.x');
            foreach ($result->errors() as $e) {
                $this->assertStringNotContainsString('add_translation', $e->message);
            }
            $this->assertTrue(true);
        } finally {
            $this->removeDir($dir);
        }
    }

    // --- FC-4x5x-02 — 5.x global function removals ---------------------------

    public function testFlags5xGlobalRemovalsAt5xTarget(): void
    {
        // get_current_language / get_default_access / check_entity_relationship
        // were all removed in 5.x — each fatals with "Call to undefined function".
        $dir = $this->makePluginDir([
            'classes/MyPlugin/Access.php' =>
                "<?php\nnamespace MyPlugin;\nclass Access {\n"
                . "    public function lang() {\n        return get_current_language();\n    }\n"
                . "    public function access() {\n        return get_default_access();\n    }\n"
                . "    public function rel(\$a, \$b) {\n        return check_entity_relationship(\$a, 'friend', \$b);\n    }\n}\n",
            'elgg-plugin.php' => "<?php\nreturn [];",
        ]);

        try {
            $result = $this->verifier->verify($dir, '5.x');
            $this->assertFalse($result->passed);

            $errors = $result->errors();
            $cats = array_map(fn($v) => $v->category, $errors);
            $this->assertContains('removed-function', $cats);

            $joined = implode(' ', array_map(fn($v) => $v->message, $errors));
            $this->assertStringContainsString('get_current_language', $joined);
            $this->assertStringContainsString('get_default_access', $joined);
            $this->assertStringContainsString('check_entity_relationship', $joined);
        } finally {
            $this->removeDir($dir);
        }
    }

    public function testElggPrefixedReplacementsNotFlaggedAt5xTarget(): void
    {
        // The correct 5.x replacements share a substring with the removed names
        // but must NOT be flagged (the lookbehind excludes the elgg_-prefixed
        // and service-method forms).
        $dir = $this->makePluginDir([
            'classes/MyPlugin/Access.php' =>
                "<?php\nnamespace MyPlugin;\nclass Access {\n"
                . "    public function lang() {\n        return elgg_get_current_language();\n    }\n"
                . "    public function access() {\n        return elgg_get_config('default_access') ?? ACCESS_PUBLIC;\n    }\n"
                . "    public function rel(\$a, \$b) {\n        return elgg_get_relationships(['guid_one' => \$a, 'relationship' => 'friend', 'guid_two' => \$b, 'limit' => 1])[0] ?? null;\n    }\n}\n",
            'elgg-plugin.php' => "<?php\nreturn [];",
        ]);

        try {
            $result = $this->verifier->verify($dir, '5.x');
            foreach ($result->errors() as $e) {
                $this->assertNotSame(
                    'removed-function',
                    $e->category,
                    "unexpected removed-function flag on the correct replacement: {$e->message}",
                );
            }
            $this->assertTrue(true);
        } finally {
            $this->removeDir($dir);
        }
    }

    // --- FC-4x5x-03 — 5.x event API leaks onto a 4.x branch -----------------

    public function testFlagsEventResultsLeakOnto4xTarget(): void
    {
        // elgg_trigger_event_results() is a 5.x-only name; sweeping it onto a 4.x
        // branch is an over-migration (checkFunctions, future-version-api).
        $dir = $this->makePluginDir([
            'classes/MyPlugin/Events.php' =>
                "<?php\nnamespace MyPlugin;\nclass Events {\n    public static function run() {\n        return elgg_trigger_event_results('config', 'plugin', null, []);\n    }\n}\n",
            'elgg-plugin.php' => "<?php\nreturn ['hooks' => []];",
        ]);

        try {
            $result = $this->verifier->verify($dir, '4.x');
            $this->assertFalse($result->passed);

            $errors = $result->errors();
            $cats = array_map(fn($v) => $v->category, $errors);
            $this->assertContains('future-version-api', $cats);

            $joined = implode(' ', array_map(fn($v) => $v->message, $errors));
            $this->assertStringContainsString('elgg_trigger_event_results', $joined);
        } finally {
            $this->removeDir($dir);
        }
    }

    public function testEventResultsValidAt5xTarget(): void
    {
        // At the 5.x target the same call is the correct API — must NOT be
        // flagged as future-version-api or removed-function.
        $dir = $this->makePluginDir([
            'classes/MyPlugin/Events.php' =>
                "<?php\nnamespace MyPlugin;\nclass Events {\n    public static function run() {\n        return elgg_trigger_event_results('config', 'plugin', null, []);\n    }\n}\n",
            'elgg-plugin.php' => "<?php\nreturn ['hooks' => []];",
        ]);

        try {
            $result = $this->verifier->verify($dir, '5.x');
            foreach ($result->violations as $v) {
                $this->assertStringNotContainsString('elgg_trigger_event_results', $v->message);
            }
            $this->assertTrue(true);
        } finally {
            $this->removeDir($dir);
        }
    }

    // --- Helpers (mirrors PostMigrationVerifierTest) -------------------------

    private function makePluginDir(array $files): string
    {
        $dir = sys_get_temp_dir() . '/elgg-migrate-4x5x-' . uniqid();
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
