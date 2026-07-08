<?php

declare(strict_types=1);

namespace ElggMigrate\Tests;

use ElggMigrate\PostMigrationVerifier;
use PHPUnit\Framework\TestCase;

/**
 * Covers the two 6.x -> 7.x failure classes that run through the DATA-DRIVEN
 * verifier paths (checkRemovedFunctions / removed-functions.json), which
 * FailureCatalogGateTest deliberately omits (it exercises only the dedicated
 * FC-tagged check methods). Every other FC-6x7x-* class is proven there.
 *
 *   FC-6x7x-02 — elgg_new_entity() removed / ElggObject abstract
 *   FC-6x7x-04 — 7.x global function removals (+ the below-7.x boundary)
 *
 * The 6.x-only removed-function set is cumulative, so every FLAGGED case
 * targets '7.x'; the boundary case targets '6.x'.
 */
final class PostMigrationVerifier6xTo7xTest extends TestCase
{
    private PostMigrationVerifier $verifier;

    protected function setUp(): void
    {
        $this->verifier = new PostMigrationVerifier();
    }

    // --- FC-6x7x-02: elgg_new_entity() removed / ElggObject abstract (removed-function) ---

    public function testFC6x7x02CatchesElggNewEntity(): void
    {
        $dir = $this->makePluginDir([
            'lib/factory.php' => "<?php\n\$obj = elgg_new_entity('object', 'my_subtype');\n",
            'elgg-plugin.php' => "<?php\nreturn [];",
        ]);

        try {
            $result = $this->verifier->verify($dir, '7.x');
            $this->assertFalse($result->passed);
            $this->assertHasCategory($result->violations, 'removed-function');
            $this->assertMessagesContain($result->violations, 'elgg_new_entity');
        } finally {
            $this->removeDir($dir);
        }
    }

    public function testFC6x7x02CleanUndefinedObjectNotFlagged(): void
    {
        $dir = $this->makePluginDir([
            'lib/factory.php' => "<?php\n\$obj = new \\ElggUndefinedObject();\n\$obj->subtype = 'my_subtype';\n",
            'elgg-plugin.php' => "<?php\nreturn [];",
        ]);

        try {
            $result = $this->verifier->verify($dir, '7.x');
            $this->assertNotHasCategory($result->violations, 'removed-function');
        } finally {
            $this->removeDir($dir);
        }
    }

    // --- FC-6x7x-04: 7.x global function removals (removed-function) ---

    public function testFC6x7x04CatchesRemovedGlobals(): void
    {
        $dir = $this->makePluginDir([
            'classes/Acme/Admin.php' => "<?php\nnamespace Acme;\nclass Admin {\n    public function run(\$guid) {\n        if (elgg_is_admin_user(\$guid)) {\n            elgg_reset_system_cache();\n        }\n        return elgg_get_entities_from_relationship(['relationship' => 'friend']);\n    }\n}\n",
            'elgg-plugin.php' => "<?php\nreturn [];",
        ]);

        try {
            $result = $this->verifier->verify($dir, '7.x');
            $this->assertFalse($result->passed);
            $this->assertHasCategory($result->violations, 'removed-function');
            $joined = $this->messages($result->violations);
            $this->assertStringContainsString('elgg_is_admin_user', $joined);
            $this->assertStringContainsString('elgg_reset_system_cache', $joined);
            $this->assertStringContainsString('elgg_get_entities_from_relationship', $joined);
        } finally {
            $this->removeDir($dir);
        }
    }

    public function testFC6x7x04ReplacementsNotFlagged(): void
    {
        $dir = $this->makePluginDir([
            'classes/Acme/Admin.php' => "<?php\nnamespace Acme;\nclass Admin {\n    public function run(\$guid) {\n        \$user = elgg_get_entity(\$guid);\n        if (\$user instanceof \\ElggUser && \$user->isAdmin()) {\n            _elgg_services()->systemCache->clear();\n        }\n        return elgg_get_entities(['relationship' => 'friend']);\n    }\n}\n",
            'elgg-plugin.php' => "<?php\nreturn [];",
        ]);

        try {
            $result = $this->verifier->verify($dir, '7.x');
            $this->assertNotHasCategory($result->violations, 'removed-function');
        } finally {
            $this->removeDir($dir);
        }
    }

    public function testFC6x7x04RemovedGlobalsAllowedBelow7x(): void
    {
        // Boundary: elgg_reset_system_cache() is a verified 7.x removal — it still
        // exists at 6.x, so it must NOT flag at a 6.x target. (elgg_is_admin_user
        // is NOT a valid boundary probe here: core-verified removed at 4.x, so it
        // is already flagged at a 6.x target via the cumulative set.)
        $dir = $this->makePluginDir([
            'classes/Acme/Admin.php' => "<?php\nnamespace Acme;\nclass Admin {\n    public function run() {\n        return elgg_reset_system_cache();\n    }\n}\n",
            'elgg-plugin.php' => "<?php\nreturn [];",
        ]);

        try {
            $result = $this->verifier->verify($dir, '6.x');
            $joined = $this->messages($result->violations);
            $this->assertStringNotContainsString('elgg_reset_system_cache', $joined);
        } finally {
            $this->removeDir($dir);
        }
    }

    // --- Helpers ---

    /** @param array<object> $violations */
    private function assertHasCategory(array $violations, string $category): void
    {
        $cats = array_map(fn($v) => $v->category, $violations);
        $this->assertContains(
            $category,
            $cats,
            "Expected a '{$category}' violation. Got: " . implode(', ', $cats),
        );
    }

    /** @param array<object> $violations */
    private function assertNotHasCategory(array $violations, string $category): void
    {
        $cats = array_map(fn($v) => $v->category, $violations);
        $this->assertNotContains(
            $category,
            $cats,
            "Did not expect a '{$category}' violation on the clean fixture.",
        );
    }

    /** @param array<object> $violations */
    private function messages(array $violations): string
    {
        return implode(' ', array_map(fn($v) => $v->message, $violations));
    }

    /** @param array<object> $violations */
    private function assertMessagesContain(array $violations, string $needle): void
    {
        $this->assertStringContainsString($needle, $this->messages($violations));
    }

    /** @param array<string,string> $files */
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
