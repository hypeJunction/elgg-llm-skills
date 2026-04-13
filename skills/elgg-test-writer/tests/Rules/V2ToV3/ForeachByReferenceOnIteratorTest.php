<?php

declare(strict_types=1);

namespace ElggMigrate\Tests\Rules\V2ToV3;

use ElggMigrate\Rules\V2ToV3\ForeachByReferenceOnIterator;
use PHPUnit\Framework\TestCase;

final class ForeachByReferenceOnIteratorTest extends TestCase
{
    private ForeachByReferenceOnIterator $rule;

    protected function setUp(): void
    {
        $this->rule = new ForeachByReferenceOnIterator();
    }

    public function testAnalyzeDetectsByReferenceOnReturn(): void
    {
        $dir = __DIR__ . '/../../fixtures/2x-to-3x/foreach-by-reference/input';
        $analysis = $this->rule->analyze($dir);

        $this->assertTrue($analysis->applicable);
        $this->assertCount(1, $analysis->findings, 'Should find exactly 1 foreach-by-reference on $return');
        $this->assertStringContainsString('menu_hook.php', $analysis->findings[0]->file);
    }

    public function testAnalyzeIgnoresNonHookVars(): void
    {
        $dir = __DIR__ . '/../../fixtures/2x-to-3x/foreach-by-reference/input';
        $analysis = $this->rule->analyze($dir);

        // The no_reference.php file has foreach by ref on $data (not a hook var)
        // and a normal foreach on $return (no ref) — neither should be flagged
        foreach ($analysis->findings as $finding) {
            $this->assertStringNotContainsString('no_reference.php', $finding->file);
        }
    }

    public function testApplyRewritesForeach(): void
    {
        $workDir = $this->makeWorkDir('foreach-by-reference');

        try {
            $result = $this->rule->apply($workDir);

            $this->assertTrue($result->success);
            $this->assertNotEmpty($result->changes, 'Should have modified menu_hook.php');

            $code = file_get_contents($workDir . '/menu_hook.php');

            // Should NOT contain foreach by reference
            $this->assertStringNotContainsString('as &$item', $code);

            // Should contain iterator_to_array conversion
            $this->assertStringContainsString('iterator_to_array', $code);
            $this->assertStringContainsString('Traversable', $code);

            // Should still be valid PHP
            $output = null;
            $retval = null;
            exec("php -l " . escapeshellarg($workDir . '/menu_hook.php') . " 2>&1", $output, $retval);
            $this->assertEquals(0, $retval, 'Transformed code should be valid PHP: ' . implode("\n", $output));
        } finally {
            $this->removeDir($workDir);
        }
    }

    public function testApplyDoesNotModifyCleanFiles(): void
    {
        $workDir = $this->makeWorkDir('foreach-by-reference');

        try {
            $beforeCode = file_get_contents($workDir . '/no_reference.php');
            $this->rule->apply($workDir);
            $afterCode = file_get_contents($workDir . '/no_reference.php');

            $this->assertEquals($beforeCode, $afterCode, 'no_reference.php should not be modified');
        } finally {
            $this->removeDir($workDir);
        }
    }

    public function testIdempotent(): void
    {
        $workDir = $this->makeWorkDir('foreach-by-reference');

        try {
            $this->rule->apply($workDir);
            $reAnalysis = $this->rule->analyze($workDir);
            $this->assertFalse($reAnalysis->applicable, 'Should not find issues after applying fix');
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
