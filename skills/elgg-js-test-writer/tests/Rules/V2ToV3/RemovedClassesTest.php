<?php

declare(strict_types=1);

namespace ElggMigrate\Tests\Rules\V2ToV3;

use ElggMigrate\Rules\V2ToV3\RemovedClasses;
use PHPUnit\Framework\TestCase;

final class RemovedClassesTest extends TestCase
{
    private RemovedClasses $rule;

    protected function setUp(): void
    {
        $this->rule = new RemovedClasses();
    }

    public function testId(): void
    {
        $this->assertSame('removed-classes', $this->rule->getId());
    }

    public function testCanAutomate(): void
    {
        $this->assertTrue($this->rule->canAutomate());
    }

    public function testAnalyzeFindsRemovedClasses(): void
    {
        $dir = __DIR__ . '/../../fixtures/2x-to-3x/removed-classes/input';
        $analysis = $this->rule->analyze($dir);

        $this->assertTrue($analysis->applicable);
        // FilePluginFile appears 4 times (new, instanceof, type hint, and the reference in function)
        // ElggDiscussionReply 1 time, ElggMemcache 1 time, ElggFileCache 1 time
        $this->assertGreaterThanOrEqual(5, count($analysis->findings));
    }

    public function testAnalyzeNotApplicableOnCleanCode(): void
    {
        $dir = sys_get_temp_dir() . '/elgg-migrate-clean-' . uniqid();
        mkdir($dir, 0755, true);
        file_put_contents($dir . '/clean.php', "<?php\n\n\$entity = new ElggObject();\n");

        try {
            $analysis = $this->rule->analyze($dir);
            $this->assertFalse($analysis->applicable);
        } finally {
            unlink($dir . '/clean.php');
            rmdir($dir);
        }
    }

    public function testApplyReplacesClassNames(): void
    {
        $workDir = $this->makeWorkDir('removed-classes');

        try {
            $result = $this->rule->apply($workDir);
            $this->assertTrue($result->success);
            $this->assertNotEmpty($result->changes);

            $code = file_get_contents($workDir . '/code.php');

            // Renames applied
            $this->assertStringContainsString('new ElggFile', $code);
            $this->assertStringContainsString('new ElggComment', $code);
            $this->assertStringContainsString('instanceof ElggFile', $code);

            // Old names gone for renamed classes
            $this->assertStringNotContainsString('FilePluginFile', $code);
            $this->assertStringNotContainsString('ElggDiscussionReply', $code);

            // Removed classes (no replacement) should still be present (only warned)
            $this->assertStringContainsString('ElggMemcache', $code);
            $this->assertStringContainsString('ElggFileCache', $code);
        } finally {
            $this->removeDir($workDir);
        }
    }

    public function testApplyGeneratesWarningsForRemovedClasses(): void
    {
        $workDir = $this->makeWorkDir('removed-classes');

        try {
            $result = $this->rule->apply($workDir);
            $this->assertNotEmpty($result->warnings);

            $warningText = implode("\n", $result->warnings);
            $this->assertStringContainsString('ElggMemcache', $warningText);
            $this->assertStringContainsString('ElggFileCache', $warningText);
        } finally {
            $this->removeDir($workDir);
        }
    }

    public function testApplyProducesValidPhp(): void
    {
        $workDir = $this->makeWorkDir('removed-classes');

        try {
            $this->rule->apply($workDir);
            exec("php -l {$workDir}/code.php 2>&1", $output, $exitCode);
            $this->assertSame(0, $exitCode, implode("\n", $output));
        } finally {
            $this->removeDir($workDir);
        }
    }

    public function testIdempotent(): void
    {
        $workDir = $this->makeWorkDir('removed-classes');

        try {
            $this->rule->apply($workDir);
            $first = file_get_contents($workDir . '/code.php');

            $this->rule->apply($workDir);
            $second = file_get_contents($workDir . '/code.php');

            $this->assertSame($first, $second);
        } finally {
            $this->removeDir($workDir);
        }
    }

    private function makeWorkDir(string $fixture): string
    {
        $src = __DIR__ . "/../../fixtures/2x-to-3x/{$fixture}/input";
        $dst = sys_get_temp_dir() . '/elgg-migrate-' . uniqid();
        mkdir($dst, 0755, true);

        foreach (new \DirectoryIterator($src) as $f) {
            if ($f->isDot()) continue;
            copy($f->getPathname(), $dst . '/' . $f->getFilename());
        }

        return $dst;
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
