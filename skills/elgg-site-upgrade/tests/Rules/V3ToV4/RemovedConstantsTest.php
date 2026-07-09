<?php

declare(strict_types=1);

namespace ElggMigrate\Tests\Rules\V3ToV4;

use ElggMigrate\Rules\V3ToV4\RemovedConstants;
use PHPUnit\Framework\TestCase;

final class RemovedConstantsTest extends TestCase
{
    private RemovedConstants $rule;

    protected function setUp(): void
    {
        $this->rule = new RemovedConstants();
    }

    public function testAnalyzeFindsRemovedConstants(): void
    {
        $dir = __DIR__ . '/../../fixtures/3x-to-4x/removed-constants/input';
        $analysis = $this->rule->analyze($dir);

        $this->assertTrue($analysis->applicable);
        // 2 auto-replaceable + 4 warn-only = 6
        $this->assertCount(6, $analysis->findings);
    }

    public function testApplyReplacesRelationshipLimitAndWarnsAboutOthers(): void
    {
        $inputDir    = __DIR__ . '/../../fixtures/3x-to-4x/removed-constants/input';
        $expectedDir = __DIR__ . '/../../fixtures/3x-to-4x/removed-constants/expected';

        $workDir = sys_get_temp_dir() . '/elgg-migrate-' . uniqid();
        mkdir($workDir, 0755, true);
        copy($inputDir . '/code.php', $workDir . '/code.php');

        try {
            $result = $this->rule->apply($workDir);

            $this->assertTrue($result->success);
            $this->assertCount(1, $result->changes);
            $this->assertCount(4, $result->warnings);

            $actual   = file_get_contents($workDir . '/code.php');
            $expected = file_get_contents($expectedDir . '/code.php');
            $this->assertSame($expected, $actual);
        } finally {
            $this->removeDir($workDir);
        }
    }

    public function testCleanFileIsNotModified(): void
    {
        $workDir = sys_get_temp_dir() . '/elgg-migrate-' . uniqid();
        mkdir($workDir, 0755, true);
        $content = "<?php\n\$x = 1;\n";
        file_put_contents($workDir . '/clean.php', $content);

        try {
            $analysis = $this->rule->analyze($workDir);
            $this->assertFalse($analysis->applicable);

            $result = $this->rule->apply($workDir);
            $this->assertTrue($result->success);
            $this->assertEmpty($result->changes);
            $this->assertEmpty($result->warnings);
            $this->assertSame($content, file_get_contents($workDir . '/clean.php'));
        } finally {
            $this->removeDir($workDir);
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
