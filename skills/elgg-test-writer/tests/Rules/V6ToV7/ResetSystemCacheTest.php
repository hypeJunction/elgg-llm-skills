<?php

declare(strict_types=1);

namespace ElggMigrate\Tests\Rules\V6ToV7;

use ElggMigrate\Rules\V6ToV7\ResetSystemCache;
use PHPUnit\Framework\TestCase;

final class ResetSystemCacheTest extends TestCase
{
    private ResetSystemCache $rule;

    protected function setUp(): void
    {
        $this->rule = new ResetSystemCache();
    }

    public function testId(): void
    {
        $this->assertSame('reset-system-cache-7x', $this->rule->getId());
    }

    public function testCanAutomate(): void
    {
        $this->assertTrue($this->rule->canAutomate());
    }

    public function testAnalyzeFlagsCall(): void
    {
        $dir = $this->makeDir([
            'start.php' => "<?php\nfunction bust() { elgg_reset_system_cache(); }\n",
        ]);
        try {
            $analysis = $this->rule->analyze($dir);
            $this->assertTrue($analysis->applicable);
            $this->assertCount(1, $analysis->findings);
        } finally {
            $this->removeDir($dir);
        }
    }

    public function testApplyReplacesCallWithSystemCacheClear(): void
    {
        $dir = $this->makeDir([
            'lib/cache.php' => "<?php\nfunction bust() { elgg_reset_system_cache(); }\n",
        ]);
        try {
            $result = $this->rule->apply($dir);
            $this->assertTrue($result->success);
            $out = file_get_contents($dir . '/lib/cache.php');
            $this->assertStringContainsString('_elgg_services()->systemCache->clear()', $out);
            $this->assertStringNotContainsString('elgg_reset_system_cache', $out);
        } finally {
            $this->removeDir($dir);
        }
    }

    public function testNoCallIsNotApplicableAndUnchanged(): void
    {
        $dir = $this->makeDir([
            'lib/other.php' => "<?php\nfunction noop() { return elgg_get_config('foo'); }\n",
        ]);
        try {
            $this->assertFalse($this->rule->analyze($dir)->applicable);
            $before = file_get_contents($dir . '/lib/other.php');
            $result = $this->rule->apply($dir);
            $this->assertTrue($result->success);
            $this->assertCount(0, $result->changes);
            $this->assertSame($before, file_get_contents($dir . '/lib/other.php'));
        } finally {
            $this->removeDir($dir);
        }
    }

    /**
     * @param array<string,string> $files
     */
    private function makeDir(array $files): string
    {
        $dir = sys_get_temp_dir() . '/elgg-migrate-rsc7-' . uniqid();
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
