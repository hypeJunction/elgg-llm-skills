<?php

declare(strict_types=1);

namespace ElggMigrate\Tests\Rules\V2ToV3;

use ElggMigrate\Rules\V2ToV3\UpdateManifestVersion;
use PHPUnit\Framework\TestCase;

final class UpdateManifestVersionTest extends TestCase
{
    private UpdateManifestVersion $rule;

    protected function setUp(): void
    {
        $this->rule = new UpdateManifestVersion();
    }

    public function testAnalyzeFindsOutdatedVersions(): void
    {
        $dir = __DIR__ . '/../../fixtures/2x-to-3x/update-manifest-version/input';
        $analysis = $this->rule->analyze($dir);

        $this->assertTrue($analysis->applicable);
        $this->assertCount(2, $analysis->findings); // manifest.xml + composer.json
    }

    public function testApplyUpdatesManifestXml(): void
    {
        $workDir = $this->makeWorkDir();

        try {
            $result = $this->rule->apply($workDir);

            $this->assertTrue($result->success);

            $manifest = file_get_contents($workDir . '/manifest.xml');
            $this->assertStringContainsString('<version>3.0</version>', $manifest);
            $this->assertStringNotContainsString('<version>2.3</version>', $manifest);
        } finally {
            $this->removeDir($workDir);
        }
    }

    public function testApplyUpdatesComposerJson(): void
    {
        $workDir = $this->makeWorkDir();

        try {
            $this->rule->apply($workDir);

            $composer = json_decode(file_get_contents($workDir . '/composer.json'), true);
            $this->assertEquals('^3.3', $composer['require']['elgg/elgg']);
        } finally {
            $this->removeDir($workDir);
        }
    }

    public function testApplyIsIdempotent(): void
    {
        $workDir = $this->makeWorkDir();

        try {
            $this->rule->apply($workDir);

            // Second run should find nothing to change
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
            $this->markTestSkipped("Guinea pig not cloned: tmp/{$pluginDir}");
        }

        $analysis = $this->rule->analyze($path);
        $this->assertTrue($analysis->applicable, "Should find outdated version in {$pluginDir}");
    }

    public static function guineaPigProvider(): array
    {
        return [
            'hypeWall' => ['hypeWall'],
            'elgg-forum' => ['elgg-forum'],
            'hypeDropzone' => ['hypeDropzone'],
            'hypeInteractions' => ['hypeInteractions'],
        ];
    }

    private function makeWorkDir(): string
    {
        $src = __DIR__ . '/../../fixtures/2x-to-3x/update-manifest-version/input';
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
