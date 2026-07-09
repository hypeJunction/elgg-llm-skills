<?php

declare(strict_types=1);

namespace ElggMigrate\Tests\Rules\V3ToV4;

use ElggMigrate\Rules\V3ToV4\DatabaseClassRename;
use PHPUnit\Framework\TestCase;

final class DatabaseClassRenameTest extends TestCase
{
    private DatabaseClassRename $rule;

    protected function setUp(): void
    {
        $this->rule = new DatabaseClassRename();
    }

    public function testAnalyzeDetectsPattern(): void
    {
        $dir = __DIR__ . '/../../fixtures/3x-to-4x/database-class-rename/input';
        $analysis = $this->rule->analyze($dir);

        $this->assertTrue($analysis->applicable);
        $this->assertNotEmpty($analysis->findings);
    }

    public function testApplyProducesExpectedOutput(): void
    {
        $inputDir = __DIR__ . '/../../fixtures/3x-to-4x/database-class-rename/input';
        $expectedDir = __DIR__ . '/../../fixtures/3x-to-4x/database-class-rename/expected';

        $workDir = sys_get_temp_dir() . '/elgg-migrate-' . uniqid();
        mkdir($workDir, 0755, true);
        copy($inputDir . '/MyClass.php', $workDir . '/MyClass.php');

        try {
            $result = $this->rule->apply($workDir);

            $this->assertTrue($result->success);
            $this->assertCount(1, $result->changes);

            $actual = file_get_contents($workDir . '/MyClass.php');
            $expected = file_get_contents($expectedDir . '/MyClass.php');
            $this->assertSame($expected, $actual);
        } finally {
            $this->removeDir($workDir);
        }
    }

    public function testSubNamespaceIsNotRenamed(): void
    {
        $workDir = sys_get_temp_dir() . '/elgg-migrate-' . uniqid();
        mkdir($workDir, 0755, true);
        $content = "<?php\nuse Elgg\\Database\\QueryBuilder;\nuse Elgg\\Database\\Select;\n";
        file_put_contents($workDir . '/sub.php', $content);

        try {
            $analysis = $this->rule->analyze($workDir);
            $this->assertFalse($analysis->applicable);

            $result = $this->rule->apply($workDir);
            $this->assertTrue($result->success);
            $this->assertEmpty($result->changes);
            $this->assertSame($content, file_get_contents($workDir . '/sub.php'));
        } finally {
            $this->removeDir($workDir);
        }
    }

    public function testCleanFilesAreNotModified(): void
    {
        $workDir = sys_get_temp_dir() . '/elgg-migrate-' . uniqid();
        mkdir($workDir, 0755, true);
        $content = "<?php\nuse Elgg\\Application\\Database;\n";
        file_put_contents($workDir . '/already_migrated.php', $content);

        try {
            $analysis = $this->rule->analyze($workDir);
            $this->assertFalse($analysis->applicable);

            $result = $this->rule->apply($workDir);
            $this->assertTrue($result->success);
            $this->assertEmpty($result->changes);
            $this->assertSame($content, file_get_contents($workDir . '/already_migrated.php'));
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
