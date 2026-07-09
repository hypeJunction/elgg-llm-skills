<?php

declare(strict_types=1);

namespace ElggMigrate\Tests\Rules\V2ToV3;

use ElggMigrate\Rules\V2ToV3\RemovedFunctionRenames;
use PHPUnit\Framework\TestCase;

/**
 * Exercises the '3.x' block of references/removed-function-renames.json — the
 * 1:1 global functions removed in Elgg 3.0 that were previously mis-filed under
 * the 6.x/7.x rename blocks and so only rewritten at 5x->6x / 6x->7x instead of
 * at the 2x->3x step where they actually break (bd elgg-migrate-jfrc1).
 */
final class RemovedFunctionRenamesTest extends TestCase
{
    private RemovedFunctionRenames $rule;

    protected function setUp(): void
    {
        $this->rule = new RemovedFunctionRenames();
    }

    public function testId(): void
    {
        $this->assertSame('removed-function-renames-3x', $this->rule->getId());
    }

    public function testCanAutomate(): void
    {
        $this->assertTrue($this->rule->canAutomate());
    }

    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function renameProvider(): array
    {
        return [
            'elgg_redirect'    => ['elgg_redirect', 'elgg_redirect_response'],
            'rmdir helper'     => ['_elgg_rmdir', 'elgg_delete_directory'],
            'html decode'      => ['_elgg_html_decode', 'html_entity_decode'],
            'logged in user'   => ['elgg_get_logged_in_user', 'elgg_get_logged_in_user_entity'],
        ];
    }

    /**
     * @dataProvider renameProvider
     */
    public function testEachRenameMapEntryIsRewritten(string $old, string $new): void
    {
        $dir = $this->makeDir([
            'classes/R.php' => "<?php\nfunction wrap() {\n    return {$old}('arg');\n}\n",
        ]);

        try {
            $this->assertTrue($this->rule->analyze($dir)->applicable, "{$old}() should be flagged");

            $result = $this->rule->apply($dir);
            $this->assertTrue($result->success);
            $this->assertNotEmpty($result->changes, "{$old}() should produce a change");

            $out = file_get_contents($dir . '/classes/R.php');
            $this->assertStringContainsString("{$new}('arg')", $out, "{$old}() should be renamed to {$new}()");
            $this->assertStringNotContainsString("{$old}(", $out, "{$old}() should no longer appear");
        } finally {
            $this->removeDir($dir);
        }
    }

    public function testGuidVariantIsNotTouched(): void
    {
        // elgg_get_logged_in_user_guid is a different (surviving) function.
        $dir = $this->makeDir([
            'classes/G.php' => "<?php\nfunction g() { return elgg_get_logged_in_user_guid(); }\n",
        ]);
        try {
            $this->assertFalse($this->rule->analyze($dir)->applicable);
            $this->rule->apply($dir);
            $out = file_get_contents($dir . '/classes/G.php');
            $this->assertStringContainsString('elgg_get_logged_in_user_guid()', $out);
            $this->assertStringNotContainsString('elgg_get_logged_in_user_entity', $out);
        } finally {
            $this->removeDir($dir);
        }
    }

    public function testCleanCodeMakesNoChanges(): void
    {
        $dir = $this->makeDir(['classes/C.php' => "<?php\necho 'nothing to do';\n"]);
        try {
            $result = $this->rule->apply($dir);
            $this->assertTrue($result->success);
            $this->assertEmpty($result->changes);
        } finally {
            $this->removeDir($dir);
        }
    }

    /**
     * @param array<string, string> $files
     */
    private function makeDir(array $files): string
    {
        $dir = sys_get_temp_dir() . '/elgg-migrate-rf3x-' . uniqid();
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
        if (!is_dir($dir)) {
            return;
        }
        foreach (new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        ) as $f) {
            $f->isDir() ? rmdir($f->getPathname()) : unlink($f->getPathname());
        }
        rmdir($dir);
    }
}
