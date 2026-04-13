<?php

declare(strict_types=1);

namespace ElggMigrate\Tests\Rules\V3ToV4;

use ElggMigrate\Rules\V3ToV4\CanWriteToContainer;
use PHPUnit\Framework\TestCase;

final class CanWriteToContainerTest extends TestCase
{
    private CanWriteToContainer $rule;

    protected function setUp(): void
    {
        $this->rule = new CanWriteToContainer();
    }

    public function testAnalyzeFindsUnderSpecifiedCalls(): void
    {
        $dir = __DIR__ . '/../../fixtures/3x-to-4x/can-write-to-container/input';
        $analysis = $this->rule->analyze($dir);

        $this->assertTrue($analysis->applicable);
        // 0-arg call and 1-arg call should be found; 3-arg call should NOT
        $this->assertCount(2, $analysis->findings);
    }

    public function testAnalyzeIgnoresCorrectCalls(): void
    {
        $workDir = sys_get_temp_dir() . '/elgg-migrate-' . uniqid();
        mkdir($workDir . '/actions', 0755, true);
        file_put_contents($workDir . '/actions/test.php', "<?php\n\$container->canWriteToContainer(0, 'object', 'blog');\n");

        try {
            $analysis = $this->rule->analyze($workDir);
            $this->assertFalse($analysis->applicable);
        } finally {
            $this->removeDir($workDir);
        }
    }

    public function testApplyProducesWarningsButNoChanges(): void
    {
        $workDir = $this->makeWorkDir();

        try {
            $result = $this->rule->apply($workDir);

            $this->assertTrue($result->success);
            $this->assertEmpty($result->changes, 'Warn-only rule should not modify files');
            $this->assertCount(2, $result->warnings);

            // Original file should be unchanged
            $original = file_get_contents(__DIR__ . '/../../fixtures/3x-to-4x/can-write-to-container/input/actions/save.php');
            $current = file_get_contents($workDir . '/actions/save.php');
            $this->assertSame($original, $current);
        } finally {
            $this->removeDir($workDir);
        }
    }

    public function testAnalyzeNotApplicableWhenNoCalls(): void
    {
        $workDir = sys_get_temp_dir() . '/elgg-migrate-' . uniqid();
        mkdir($workDir, 0755, true);
        file_put_contents($workDir . '/test.php', "<?php\n\$entity->save();\n");

        try {
            $analysis = $this->rule->analyze($workDir);
            $this->assertFalse($analysis->applicable);
        } finally {
            $this->removeDir($workDir);
        }
    }

    private function makeWorkDir(): string
    {
        $src = __DIR__ . '/../../fixtures/3x-to-4x/can-write-to-container/input';
        $dst = sys_get_temp_dir() . '/elgg-migrate-' . uniqid();
        $this->copyDir($src, $dst);
        return $dst;
    }

    private function copyDir(string $src, string $dst): void
    {
        mkdir($dst, 0755, true);
        foreach (new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($src, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::SELF_FIRST
        ) as $item) {
            $target = $dst . '/' . substr($item->getPathname(), strlen($src) + 1);
            if ($item->isDir()) {
                mkdir($target, 0755, true);
            } else {
                copy($item->getPathname(), $target);
            }
        }
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
