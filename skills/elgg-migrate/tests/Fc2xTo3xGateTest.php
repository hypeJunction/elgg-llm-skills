<?php

declare(strict_types=1);

namespace ElggMigrate\Tests;

use ElggMigrate\PostMigrationVerifier;
use PHPUnit\Framework\TestCase;

/**
 * Proves the migration gates DETECT every 2.x -> 3.x failure class catalogued in
 * references/migration-failure-catalog.md. Each failure class gets:
 *   - a DIRTY fixture carrying the failure signature -> asserted FLAGGED
 *   - a CLEAN (correctly-migrated) fixture           -> asserted NOT flagged
 *
 * FC-2x3x-01  Metastrings query API removed        -> checkRemovedFunctions   (error)
 * FC-2x3x-02  3.x dropped start.php prematurely     -> check3xStartPhpExists   (warning)
 * FC-2x3x-03  3.0 search hook stopped returning []  -> checkSearchHookReturn   (warning)
 * FC-2x3x-04  Site secret scrubbed -> boot throws   -> checkSiteSecretScrub    (warning)
 *
 * All are surfaced by PostMigrationVerifier::verify(..., '3.x').
 */
final class Fc2xTo3xGateTest extends TestCase
{
    private PostMigrationVerifier $verifier;

    protected function setUp(): void
    {
        $this->verifier = new PostMigrationVerifier();
    }

    // --- FC-2x3x-01 — Metastrings query API removed ---

    public function testFc2x3x01FlagsRemovedMetastringFunctionIn3xTarget(): void
    {
        // elgg_get_metastring_id() went away with the metastrings table in 3.0 —
        // a live call fatals with "Call to undefined function" on 3.x core.
        $dir = $this->makePluginDir([
            'start.php' => "<?php\nreturn function () {};\n",
            'elgg-plugin.php' => "<?php\nreturn [];",
            'lib/sort.php' => "<?php\nfunction acme_group_sort() {\n    return elgg_get_metastring_id('name');\n}\n",
        ]);

        try {
            $result = $this->verifier->verify($dir, '3.x');
            $this->assertFalse($result->passed);
            $cats = array_map(fn($v) => $v->category, $result->errors());
            $this->assertContains('removed-function', $cats);
            $joined = implode(' ', array_map(fn($v) => $v->message, $result->errors()));
            $this->assertStringContainsString('elgg_get_metastring_id', $joined);
        } finally {
            $this->removeDir($dir);
        }
    }

    public function testFc2x3x01CleanQueryBuilderSortNotFlagged(): void
    {
        // The rewrite-not-migrate fix: sort via elgg_get_entities() QueryBuilder
        // options — no metastrings symbol, so nothing to flag.
        $dir = $this->makePluginDir([
            'start.php' => "<?php\nreturn function () {};\n",
            'elgg-plugin.php' => "<?php\nreturn [];",
            'lib/sort.php' => "<?php\nfunction acme_group_sort() {\n    return elgg_get_entities(['type' => 'group', 'sort_by' => ['property' => 'name']]);\n}\n",
        ]);

        try {
            $result = $this->verifier->verify($dir, '3.x');
            foreach ($result->violations as $v) {
                $this->assertNotSame(
                    'removed-function',
                    $v->category,
                    "unexpected removed-function flag: {$v->message}",
                );
            }
        } finally {
            $this->removeDir($dir);
        }
    }

    // --- FC-2x3x-02 — 3.x plugin dropped start.php prematurely ---

    public function testFc2x3x02FlagsMissingStartPhpIn3xTarget(): void
    {
        // elgg-plugin.php present but start.php absent is a 4.x shape; in 3.x
        // start.php must still exist (returning a closure).
        $dir = $this->makePluginDir([
            'elgg-plugin.php' => "<?php\nreturn [];",
        ]);

        try {
            $result = $this->verifier->verify($dir, '3.x');
            $cats = array_map(fn($v) => $v->category, $result->warnings());
            $this->assertContains('missing-file', $cats);
            $joined = implode(' ', array_map(fn($v) => $v->message, $result->warnings()));
            $this->assertStringContainsString('start.php', $joined);
        } finally {
            $this->removeDir($dir);
        }
    }

    public function testFc2x3x02CleanPluginWithStartPhpNotFlagged(): void
    {
        // Both files present — the correct 3.x layout. No missing-file warning.
        $dir = $this->makePluginDir([
            'start.php' => "<?php\nreturn function () {};\n",
            'elgg-plugin.php' => "<?php\nreturn [];",
        ]);

        try {
            $result = $this->verifier->verify($dir, '3.x');
            $cats = array_map(fn($v) => $v->category, $result->violations);
            $this->assertNotContains('missing-file', $cats);
        } finally {
            $this->removeDir($dir);
        }
    }

    // --- FC-2x3x-03 — 3.0 search hook stopped returning ['entities'] ---

    public function testFc2x3x03FlagsSearchHookEntitiesReturnIn3xTarget(): void
    {
        // A 'search' handler that returns ['entities' => ...] without funnelling
        // through elgg_search()/elgg_list_entities(..., 'elgg_search') is the
        // latent null-TypeError pattern introduced by the 3.0 search rewrite.
        $dir = $this->makePluginDir([
            'start.php' => "<?php\nreturn function () {};\n",
            'elgg-plugin.php' => "<?php\nreturn [];",
            'lib/search.php' => "<?php\n// registered on the 'search' hook\nfunction acme_search_hook(\$hook, \$type, \$value, \$params) {\n    \$entities = [];\n    return ['entities' => \$entities, 'count' => 0];\n}\n",
        ]);

        try {
            $result = $this->verifier->verify($dir, '3.x');
            $cats = array_map(fn($v) => $v->category, $result->violations);
            $this->assertContains('search-hook-return', $cats);
        } finally {
            $this->removeDir($dir);
        }
    }

    public function testFc2x3x03CleanSearchHookUsingElggSearchNotFlagged(): void
    {
        // The migrated handler routes through elgg_search() — the safe 3.x form.
        $dir = $this->makePluginDir([
            'start.php' => "<?php\nreturn function () {};\n",
            'elgg-plugin.php' => "<?php\nreturn [];",
            'lib/search.php' => "<?php\n// registered on the 'search' hook\nfunction acme_search_hook(\$event) {\n    return elgg_search(\$event->getParams());\n}\n",
        ]);

        try {
            $result = $this->verifier->verify($dir, '3.x');
            $cats = array_map(fn($v) => $v->category, $result->violations);
            $this->assertNotContains('search-hook-return', $cats);
        } finally {
            $this->removeDir($dir);
        }
    }

    // --- FC-2x3x-04 — Site secret scrubbed -> 3.x+ BootService hard-throws ---

    public function testFc2x3x04FlagsSiteSecretScrubIn3xTarget(): void
    {
        // Emptying/deleting the datalists __site_secret__ row breaks 3.x+ boot;
        // 2.x regenerated it lazily but 3.x+ BootService throws.
        $dir = $this->makePluginDir([
            'start.php' => "<?php\nreturn function () {};\n",
            'elgg-plugin.php' => "<?php\nreturn [];",
            'install/anonymize.sql' => "DELETE FROM elgg_datalists WHERE name = '__site_secret__';\n",
        ]);

        try {
            $result = $this->verifier->verify($dir, '3.x');
            $cats = array_map(fn($v) => $v->category, $result->violations);
            $this->assertContains('site-secret-scrub', $cats);
        } finally {
            $this->removeDir($dir);
        }
    }

    public function testFc2x3x04CleanReseededSecretNotFlagged(): void
    {
        // Re-seeding a fresh secret (an INSERT of a non-empty value) is the fix —
        // it neither deletes nor blanks the row, so it must NOT be flagged.
        $dir = $this->makePluginDir([
            'start.php' => "<?php\nreturn function () {};\n",
            'elgg-plugin.php' => "<?php\nreturn [];",
            'install/anonymize.sql' => "INSERT INTO elgg_datalists (name, value) VALUES ('__site_secret__', 'z0123456789abcdefghijklmnopqrst');\n",
        ]);

        try {
            $result = $this->verifier->verify($dir, '3.x');
            $cats = array_map(fn($v) => $v->category, $result->violations);
            $this->assertNotContains('site-secret-scrub', $cats);
        } finally {
            $this->removeDir($dir);
        }
    }

    // --- Helpers (mirror PostMigrationVerifierTest) ---

    private function makePluginDir(array $files): string
    {
        $dir = sys_get_temp_dir() . '/elgg-migrate-fc2x3x-' . uniqid();
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
