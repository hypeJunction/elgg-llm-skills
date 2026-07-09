<?php

declare(strict_types=1);

namespace ElggMigrate\Tests\Rules\V6ToV7;

use ElggMigrate\Rules\V6ToV7\FormActionRenames;
use PHPUnit\Framework\TestCase;

final class FormActionRenamesTest extends TestCase
{
    private FormActionRenames $rule;

    protected function setUp(): void
    {
        $this->rule = new FormActionRenames();
    }

    public function testId(): void
    {
        $this->assertSame('form-action-renames-7x', $this->rule->getId());
        $this->assertTrue($this->rule->canAutomate());
    }

    public function testRewritesShortAndFullPathLiterals(): void
    {
        $dir = $this->makeDir([
            'views/default/resources/blog.php' => "<?php\n"
                . "echo elgg_view_form('blog/save', []);\n"          // short name
                . "echo elgg_view('forms/blog/save', []);\n"          // full view path
                . "\$u = elgg_generate_action_url('bookmarks/save');\n"
                . "\$a = 'action/file/upload';\n"                      // full action path
                . "elgg_register_action('admin/site/flush_cache', __DIR__);\n",
        ]);

        try {
            $this->assertTrue($this->rule->analyze($dir)->applicable);
            $this->rule->apply($dir);
            $out = file_get_contents($dir . '/views/default/resources/blog.php');

            $this->assertStringContainsString("elgg_view_form('blog/edit', [])", $out);
            $this->assertStringContainsString("elgg_view('forms/blog/edit', [])", $out);
            $this->assertStringContainsString("elgg_generate_action_url('bookmarks/edit')", $out);
            $this->assertStringContainsString("'action/file/edit'", $out);
            $this->assertStringContainsString("elgg_register_action('admin/site/cache/clear'", $out);

            // old values gone
            $this->assertStringNotContainsString('blog/save', $out);
            $this->assertStringNotContainsString('file/upload', $out);
            $this->assertStringNotContainsString('flush_cache', $out);
        } finally {
            $this->removeDir($dir);
        }
    }

    public function testDoesNotTouchSubstringsOrUnrelated(): void
    {
        $dir = $this->makeDir([
            'classes/A.php' => "<?php\n"
                . "\$x = 'myplugin/blog/save';\n"      // substring, NOT the whole value
                . "\$y = 'blog/saved';\n"               // different value
                . "// blog/save mentioned in a comment only\n",
        ]);
        try {
            $this->assertFalse($this->rule->analyze($dir)->applicable);
            $this->assertEmpty($this->rule->apply($dir)->changes);
            $out = file_get_contents($dir . '/classes/A.php');
            $this->assertStringContainsString("'myplugin/blog/save'", $out);
            $this->assertStringContainsString("'blog/saved'", $out);
        } finally {
            $this->removeDir($dir);
        }
    }

    public function testPrefixOverlapRewritesEachRouteExactlyOnce(): void
    {
        // A file containing BOTH the short members route and its own rename
        // target (the longer, prefix-overlapping key). The naive-replace failure
        // mode would corrupt 'collection:user:user:all' while rewriting its
        // prefix 'collection:user:user'. Whole-literal, longest-first matching
        // must rewrite each exactly once.
        $dir = $this->makeDir([
            'views/default/resources/members.php' => "<?php\n"
                . "\$a = elgg_generate_url('collection:user:user');\n"        // → :all
                . "\$b = elgg_generate_url('collection:user:user:all');\n"    // already migrated → untouched
                . "\$c = elgg_generate_url('search:user:user');\n",          // → collection:user:user:search
        ]);

        try {
            $this->assertTrue($this->rule->analyze($dir)->applicable);
            $this->rule->apply($dir);
            $out = file_get_contents($dir . '/views/default/resources/members.php');

            // Each rewritten target appears exactly once.
            $this->assertSame(2, substr_count($out, "'collection:user:user:all'"));
            $this->assertSame(1, substr_count($out, "'collection:user:user:search'"));

            // The short prefix key no longer stands alone as a whole literal.
            $this->assertStringNotContainsString("'collection:user:user'", $out);
            $this->assertStringNotContainsString("'search:user:user'", $out);
        } finally {
            $this->removeDir($dir);
        }
    }

    public function testMembersRouteRewriteIsIdempotent(): void
    {
        $dir = $this->makeDir([
            'views/default/resources/members.php' => "<?php\n"
                . "\$a = elgg_generate_url('collection:user:user');\n"
                . "\$b = elgg_generate_url('collection:user:user:all');\n"
                . "\$c = elgg_generate_url('search:user:user');\n",
        ]);

        try {
            $this->rule->apply($dir);
            $first = file_get_contents($dir . '/views/default/resources/members.php');

            // Second run must be a no-op: nothing left to rewrite, file unchanged.
            $this->assertFalse($this->rule->analyze($dir)->applicable);
            $this->assertEmpty($this->rule->apply($dir)->changes);
            $second = file_get_contents($dir . '/views/default/resources/members.php');

            $this->assertSame($first, $second);
        } finally {
            $this->removeDir($dir);
        }
    }

    private function makeDir(array $files): string
    {
        $dir = sys_get_temp_dir() . '/elgg-migrate-far-' . uniqid();
        mkdir($dir, 0755, true);
        foreach ($files as $name => $content) {
            $path = $dir . '/' . $name;
            if (!is_dir(dirname($path))) {
                mkdir(dirname($path), 0755, true);
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
