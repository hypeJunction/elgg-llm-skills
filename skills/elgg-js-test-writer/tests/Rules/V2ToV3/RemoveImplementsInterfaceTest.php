<?php

declare(strict_types=1);

namespace ElggMigrate\Tests\Rules\V2ToV3;

use ElggMigrate\Rules\V2ToV3\RemoveImplementsInterface;
use PHPUnit\Framework\TestCase;

final class RemoveImplementsInterfaceTest extends TestCase
{
    private RemoveImplementsInterface $rule;

    protected function setUp(): void
    {
        $this->rule = new RemoveImplementsInterface();
    }

    public function testAnalyzeFindsRemovedInterfaces(): void
    {
        $dir = __DIR__ . '/../../fixtures/2x-to-3x/remove-implements-interface/input';
        $analysis = $this->rule->analyze($dir);

        $this->assertTrue($analysis->applicable);
        // Pool, Exportable, Importable = 3 interfaces
        $this->assertGreaterThanOrEqual(3, count($analysis->findings));
    }

    public function testApplyStripsRemovedInterfaces(): void
    {
        $workDir = $this->makeWorkDir('remove-implements-interface');

        try {
            $result = $this->rule->apply($workDir);

            $this->assertTrue($result->success);

            $code = file_get_contents($workDir . '/cache.php');

            // Pool should be removed from implements
            $this->assertStringNotContainsString('implements Pool', $code);
            // Serializable should remain
            $this->assertStringContainsString('Serializable', $code);
            // use Elgg\Cache\Pool should be removed
            $this->assertStringNotContainsString('Elgg\\Cache\\Pool', $code);
            // Exportable should be removed
            $this->assertStringNotContainsString('Exportable', $code);
            // Importable should be removed
            $this->assertStringNotContainsString('Importable', $code);
        } finally {
            $this->removeDir($workDir);
        }
    }

    public function testIdempotent(): void
    {
        $workDir = $this->makeWorkDir('remove-implements-interface');

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
