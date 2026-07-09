<?php

declare(strict_types=1);

namespace ElggMigrate\Tests\Rules\V3ToV4;

use ElggMigrate\Rules\V3ToV4\RemovedFunctionRenames;
use PHPUnit\Framework\TestCase;

/**
 * Exercises the '4.x' block of references/removed-function-renames.json —
 * elgg_flush_caches, a core-verified 4.x removal that was previously mis-filed
 * under the 6.x rename block and so only rewritten at 5x->6x instead of at the
 * 3x->4x step where it breaks (bd elgg-migrate-jfrc1).
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
        $this->assertSame('removed-function-renames-4x', $this->rule->getId());
    }

    public function testCanAutomate(): void
    {
        $this->assertTrue($this->rule->canAutomate());
    }

    public function testFlushCachesIsRewritten(): void
    {
        $dir = $this->makeDir([
            'classes/R.php' => "<?php\nfunction wrap() {\n    return elgg_flush_caches();\n}\n",
        ]);

        try {
            $this->assertTrue($this->rule->analyze($dir)->applicable);

            $result = $this->rule->apply($dir);
            $this->assertTrue($result->success);
            $this->assertNotEmpty($result->changes);

            $out = file_get_contents($dir . '/classes/R.php');
            $this->assertStringContainsString('elgg_clear_caches()', $out);
            $this->assertStringNotContainsString('elgg_flush_caches(', $out);
        } finally {
            $this->removeDir($dir);
        }
    }

    public function testCleanCodeMakesNoChanges(): void
    {
        $dir = $this->makeDir(['classes/C.php' => "<?php\n\$x = elgg_clear_caches();\n"]);
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
        $dir = sys_get_temp_dir() . '/elgg-migrate-rf4x-' . uniqid();
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
