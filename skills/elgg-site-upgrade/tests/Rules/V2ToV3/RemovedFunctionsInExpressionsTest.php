<?php

declare(strict_types=1);

namespace ElggMigrate\Tests\Rules\V2ToV3;

use ElggMigrate\Rules\V2ToV3\RemovedFunctionsInExpressions;
use PHPUnit\Framework\TestCase;

final class RemovedFunctionsInExpressionsTest extends TestCase
{
    private RemovedFunctionsInExpressions $rule;

    protected function setUp(): void
    {
        $this->rule = new RemovedFunctionsInExpressions();
    }

    public function testAnalyzeFindsRemovedFunctionsInExpressions(): void
    {
        $dir = __DIR__ . '/../../fixtures/2x-to-3x/removed-functions-in-expressions/input';
        $analysis = $this->rule->analyze($dir);

        $this->assertTrue($analysis->applicable);
        // is_memcache_available (2x: if + ternary), get_db_tables, elgg_get_metastring_id = 4
        $this->assertGreaterThanOrEqual(4, count($analysis->findings));
    }

    public function testApplyReplacesExpressionsCorrectly(): void
    {
        $workDir = $this->makeWorkDir('removed-functions-in-expressions');

        try {
            $result = $this->rule->apply($workDir);

            $this->assertTrue($result->success);

            $code = file_get_contents($workDir . '/logic.php');

            // is_memcache_available() should be gone
            $this->assertStringNotContainsString('is_memcache_available', $code);
            // get_db_tables() should be replaced with []
            $this->assertStringNotContainsString('get_db_tables', $code);
            // elgg_get_metastring_id() should be replaced with null
            $this->assertStringNotContainsString('elgg_get_metastring_id', $code);

            // The if block should be removed (condition was false)
            $this->assertStringNotContainsString('$cache = true', $code);

            // The ternary should resolve to the else branch
            $this->assertStringContainsString('filecache', $code);
        } finally {
            $this->removeDir($workDir);
        }
    }

    public function testIdempotent(): void
    {
        $workDir = $this->makeWorkDir('removed-functions-in-expressions');

        try {
            $this->rule->apply($workDir);
            $reAnalysis = $this->rule->analyze($workDir);
            $this->assertFalse($reAnalysis->applicable);
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
