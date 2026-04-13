<?php

declare(strict_types=1);

namespace ElggMigrate\Tests\Rules\V3ToV4;

use ElggMigrate\Rules\V3ToV4\UpdateManifestVersion;
use PHPUnit\Framework\TestCase;

final class UpdateManifestVersionTest extends TestCase
{
    private UpdateManifestVersion $rule;

    protected function setUp(): void
    {
        $this->rule = new UpdateManifestVersion();
    }

    public function testAnalyzeFindsOldVersion(): void
    {
        $dir = __DIR__ . '/../../fixtures/3x-to-4x/update-manifest-version/input';
        $analysis = $this->rule->analyze($dir);

        $this->assertTrue($analysis->applicable);
        $this->assertGreaterThanOrEqual(1, count($analysis->findings));
    }

    public function testApplyUpdatesManifestAndComposer(): void
    {
        $workDir = $this->makeWorkDir();

        try {
            $result = $this->rule->apply($workDir);

            $this->assertTrue($result->success);
            $this->assertNotEmpty($result->changes);

            // manifest.xml updated
            $manifest = file_get_contents($workDir . '/manifest.xml');
            $this->assertStringContainsString('<version>4.0</version>', $manifest);
            $this->assertStringNotContainsString('<version>3.0</version>', $manifest);

            // composer.json updated
            $json = json_decode(file_get_contents($workDir . '/composer.json'), true);
            $this->assertStringContainsString('4', $json['require']['elgg/elgg']);
        } finally {
            $this->removeDir($workDir);
        }
    }

    public function testApplyIsIdempotent(): void
    {
        $workDir = $this->makeWorkDir();

        try {
            $this->rule->apply($workDir);
            $analysis = $this->rule->analyze($workDir);
            $this->assertFalse($analysis->applicable);
        } finally {
            $this->removeDir($workDir);
        }
    }

    public function testAnalyzeNotApplicableWhenAlready4x(): void
    {
        $workDir = sys_get_temp_dir() . '/elgg-migrate-' . uniqid();
        mkdir($workDir, 0755, true);
        file_put_contents($workDir . '/manifest.xml', '<?xml version="1.0"?><plugin_manifest><requires><type>elgg_release</type><version>4.0</version></requires></plugin_manifest>');

        try {
            $analysis = $this->rule->analyze($workDir);
            $this->assertFalse($analysis->applicable);
        } finally {
            $this->removeDir($workDir);
        }
    }

    private function makeWorkDir(): string
    {
        $src = __DIR__ . '/../../fixtures/3x-to-4x/update-manifest-version/input';
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
