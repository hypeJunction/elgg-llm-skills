<?php

declare(strict_types=1);

namespace ElggMigrate\Tests\Rules\V3ToV4;

use ElggMigrate\Rules\V3ToV4\GenerateElggPluginPhp;
use PHPUnit\Framework\TestCase;

final class GenerateElggPluginPhpTest extends TestCase
{
    private GenerateElggPluginPhp $rule;

    protected function setUp(): void
    {
        $this->rule = new GenerateElggPluginPhp();
    }

    public function testAnalyzeFindsRegistrations(): void
    {
        $dir = __DIR__ . '/../../fixtures/3x-to-4x/generate-elgg-plugin-php/input';
        $analysis = $this->rule->analyze($dir);

        $this->assertTrue($analysis->applicable);
        $this->assertGreaterThanOrEqual(3, count($analysis->findings));
    }

    public function testApplyGeneratesElggPluginPhp(): void
    {
        $workDir = $this->makeWorkDir();

        try {
            $result = $this->rule->apply($workDir);

            $this->assertTrue($result->success);

            $file = $workDir . '/elgg-plugin.php';
            $this->assertFileExists($file);

            $content = file_get_contents($file);

            // Should have valid PHP
            $output = [];
            exec("php -l " . escapeshellarg($file) . " 2>&1", $output, $exitCode);
            $this->assertSame(0, $exitCode, "Generated file has syntax errors: " . implode("\n", $output));

            // Should contain actions
            $this->assertStringContainsString("'myplugin/save'", $content);
            $this->assertStringContainsString("'myplugin/delete'", $content);
            $this->assertStringContainsString("'admin'", $content);

            // Should contain routes
            $this->assertStringContainsString("'myplugin:view'", $content);
            $this->assertStringContainsString('/myplugin/view/{guid}', $content);

            // Should contain entities from activate.php
            $this->assertStringContainsString("'myplugin_item'", $content);

            // Should contain hooks with proper grouping
            $this->assertStringContainsString("'register'", $content);
            $this->assertStringContainsString("'menu:entity'", $content);
            $this->assertStringContainsString("'menu:river'", $content);

            // Should contain events (excluding init,system)
            $this->assertStringContainsString("'create'", $content);
        } finally {
            $this->removeDir($workDir);
        }
    }

    public function testApplySkipsIfElggPluginPhpExists(): void
    {
        $workDir = $this->makeWorkDir();
        file_put_contents($workDir . '/elgg-plugin.php', '<?php return [];');

        try {
            $result = $this->rule->apply($workDir);
            $this->assertTrue($result->success);
            $this->assertEmpty($result->changes);
        } finally {
            $this->removeDir($workDir);
        }
    }

    public function testApplyIsIdempotent(): void
    {
        $workDir = $this->makeWorkDir();

        try {
            $this->rule->apply($workDir);
            // Second run should skip (file exists now)
            $analysis = $this->rule->analyze($workDir);
            $this->assertFalse($analysis->applicable);
        } finally {
            $this->removeDir($workDir);
        }
    }

    /**
     * @dataProvider guineaPigProvider
     */
    public function testAnalyzeRealPlugin(string $pluginDir): void
    {
        $path = __DIR__ . '/../../../tmp/' . $pluginDir;
        if (!is_dir($path)) {
            $this->markTestSkipped("Not cloned: tmp/{$pluginDir}");
        }

        $analysis = $this->rule->analyze($path);
        $this->assertTrue($analysis->applicable, "Should find registrations in {$pluginDir}");
    }

    public static function guineaPigProvider(): array
    {
        return [
            'hypeWall' => ['hypeWall'],
            'hypeInteractions' => ['hypeInteractions'],
            'hypeDropzone' => ['hypeDropzone'],
        ];
    }

    private function makeWorkDir(): string
    {
        $src = __DIR__ . '/../../fixtures/3x-to-4x/generate-elgg-plugin-php/input';
        $dst = sys_get_temp_dir() . '/elgg-migrate-' . uniqid();
        mkdir($dst, 0755, true);
        foreach (new \DirectoryIterator($src) as $f) {
            if ($f->isDot() || $f->isDir()) continue;
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
