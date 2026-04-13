<?php

declare(strict_types=1);

namespace ElggMigrate\Tests\Rules\V2ToV3;

use ElggMigrate\Rules\V2ToV3\RemoveVendorAutoload;
use PHPUnit\Framework\TestCase;

final class RemoveVendorAutoloadTest extends TestCase
{
    private RemoveVendorAutoload $rule;

    protected function setUp(): void
    {
        $this->rule = new RemoveVendorAutoload();
    }

    public function testId(): void
    {
        $this->assertSame('remove-vendor-autoload', $this->rule->getId());
    }

    public function testCanAutomate(): void
    {
        $this->assertTrue($this->rule->canAutomate());
    }

    public function testAnalyzeFindsRedundantAutoload(): void
    {
        $dir = __DIR__ . '/../../fixtures/2x-to-3x/remove-vendor-autoload/input';
        $analysis = $this->rule->analyze($dir);

        $this->assertTrue($analysis->applicable);
        $this->assertGreaterThanOrEqual(1, count($analysis->findings));
    }

    public function testAnalyzeNotApplicableOnCleanCode(): void
    {
        $dir = sys_get_temp_dir() . '/elgg-migrate-clean-' . uniqid();
        mkdir($dir, 0755, true);
        file_put_contents($dir . '/start.php', "<?php\n\nrequire_once __DIR__ . '/lib/functions.php';\n");

        try {
            $analysis = $this->rule->analyze($dir);
            $this->assertFalse($analysis->applicable);
        } finally {
            unlink($dir . '/start.php');
            rmdir($dir);
        }
    }

    public function testApplyRemovesRedundantAutoload(): void
    {
        $workDir = $this->makeWorkDir('remove-vendor-autoload');

        try {
            $result = $this->rule->apply($workDir);
            $this->assertTrue($result->success);
            $this->assertNotEmpty($result->changes);

            $code = file_get_contents($workDir . '/start.php');

            // Redundant autoload removed (dirname chain to vendor)
            $this->assertStringNotContainsString('vendor/autoload.php', $code);

            // Local require kept
            $this->assertStringContainsString("__DIR__ . '/lib/functions.php'", $code);

            // Other code preserved
            $this->assertStringContainsString('elgg_register_event_handler', $code);
        } finally {
            $this->removeDir($workDir);
        }
    }

    public function testApplyProducesValidPhp(): void
    {
        $workDir = $this->makeWorkDir('remove-vendor-autoload');

        try {
            $this->rule->apply($workDir);
            exec("php -l {$workDir}/start.php 2>&1", $output, $exitCode);
            $this->assertSame(0, $exitCode, implode("\n", $output));
        } finally {
            $this->removeDir($workDir);
        }
    }

    public function testIdempotent(): void
    {
        $workDir = $this->makeWorkDir('remove-vendor-autoload');

        try {
            $this->rule->apply($workDir);
            $first = file_get_contents($workDir . '/start.php');

            $this->rule->apply($workDir);
            $second = file_get_contents($workDir . '/start.php');

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
