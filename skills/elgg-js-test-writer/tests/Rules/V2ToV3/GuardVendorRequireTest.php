<?php

declare(strict_types=1);

namespace ElggMigrate\Tests\Rules\V2ToV3;

use ElggMigrate\Rules\V2ToV3\GuardVendorRequire;
use PHPUnit\Framework\TestCase;

final class GuardVendorRequireTest extends TestCase
{
    private GuardVendorRequire $rule;

    protected function setUp(): void
    {
        $this->rule = new GuardVendorRequire();
    }

    public function testAnalyzeFindsUnguardedRequire(): void
    {
        $dir = __DIR__ . '/../../fixtures/2x-to-3x/guard-vendor-require/input';
        $analysis = $this->rule->analyze($dir);

        $this->assertTrue($analysis->applicable);
        $this->assertGreaterThanOrEqual(1, count($analysis->findings));
    }

    public function testApplyWrapsInFileExistsGuard(): void
    {
        $workDir = $this->makeWorkDir('guard-vendor-require');

        try {
            $result = $this->rule->apply($workDir);

            $this->assertTrue($result->success);

            $code = file_get_contents($workDir . '/start.php');

            // Should have file_exists guard
            $this->assertStringContainsString('file_exists', $code);
            // Should still have the require_once
            $this->assertStringContainsString('require_once', $code);
            // Should still have the vendor/autoload.php path
            $this->assertStringContainsString('vendor/autoload.php', $code);
        } finally {
            $this->removeDir($workDir);
        }
    }

    public function testIdempotent(): void
    {
        $workDir = $this->makeWorkDir('guard-vendor-require');

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
