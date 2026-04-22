<?php

declare(strict_types=1);

namespace ElggMigrate\Tests\Rules\V3ToV4;

use ElggMigrate\Rules\V3ToV4\ElggInstanceof;
use PHPUnit\Framework\TestCase;

final class ElggInstanceofTest extends TestCase
{
    private ElggInstanceof $rule;
    private string $inputFixture;
    private string $expectedFixture;

    protected function setUp(): void
    {
        $this->rule = new ElggInstanceof();
        $this->inputFixture = __DIR__ . '/../../fixtures/3x-to-4x/elgg-instanceof/input';
        $this->expectedFixture = __DIR__ . '/../../fixtures/3x-to-4x/elgg-instanceof/expected';
    }

    public function testAnalyzeFindsThreeUsages(): void
    {
        $analysis = $this->rule->analyze($this->inputFixture);

        $this->assertTrue($analysis->applicable);
        $this->assertCount(1, $analysis->findings, 'One finding per file (counts all occurrences within)');

        $this->assertStringContainsString('3', $analysis->findings[0]->description, 'Should report 3 occurrences');
    }

    public function testApplyProducesExpectedOutput(): void
    {
        $dir = $this->tempDir();
        copy($this->inputFixture . '/code.php', $dir . '/code.php');

        try {
            $result = $this->rule->apply($dir);

            $this->assertTrue($result->success);
            $this->assertCount(1, $result->changes);

            $actual   = file_get_contents($dir . '/code.php');
            $expected = file_get_contents($this->expectedFixture . '/code.php');

            $this->assertSame($expected, $actual, 'Fixed PHP should match expected fixture');
        } finally {
            $this->removeDir($dir);
        }
    }

    public function testCleanFilesAreNotModified(): void
    {
        $dir = $this->tempDir();
        $original = "<?php\n\$result = \$entity instanceof \\ElggObject;\n";
        file_put_contents($dir . '/clean.php', $original);

        try {
            $result = $this->rule->apply($dir);
            $this->assertEmpty($result->changes);
            $this->assertSame($original, file_get_contents($dir . '/clean.php'));
        } finally {
            $this->removeDir($dir);
        }
    }

    // -------------------------------------------------------------------------

    private function tempDir(): string
    {
        $dir = sys_get_temp_dir() . '/elgg-migrate-instanceof-' . uniqid();
        mkdir($dir, 0755, true);
        return $dir;
    }

    private function removeDir(string $dir): void
    {
        if (!is_dir($dir)) return;
        foreach (new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        ) as $f) {
            $f->isDir() ? rmdir($f->getPathname()) : unlink($f->getPathname());
        }
        rmdir($dir);
    }
}
