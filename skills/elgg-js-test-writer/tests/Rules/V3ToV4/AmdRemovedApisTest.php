<?php

declare(strict_types=1);

namespace ElggMigrate\Tests\Rules\V3ToV4;

use ElggMigrate\Rules\V3ToV4\AmdRemovedApis;
use PHPUnit\Framework\TestCase;

final class AmdRemovedApisTest extends TestCase
{
    private AmdRemovedApis $rule;
    private string $inputFixture;
    private string $expectedFixture;

    protected function setUp(): void
    {
        $this->rule = new AmdRemovedApis();
        $this->inputFixture = __DIR__ . '/../../fixtures/3x-to-4x/amd-removed-apis/input';
        $this->expectedFixture = __DIR__ . '/../../fixtures/3x-to-4x/amd-removed-apis/expected';
    }

    public function testAnalyzeDetectsAllThreePatterns(): void
    {
        $analysis = $this->rule->analyze($this->inputFixture);

        $this->assertTrue($analysis->applicable);

        $descriptions = array_map(fn($f) => $f->description, $analysis->findings);
        $this->assertCount(3, $analysis->findings, 'Expected 3 findings: elgg/init, elgg.echo, elgg.provide');

        $this->assertTrue(
            (bool) array_filter($descriptions, fn($d) => str_contains($d, 'elgg/init')),
            'Should flag require(elgg/init)',
        );
        $this->assertTrue(
            (bool) array_filter($descriptions, fn($d) => str_contains($d, 'elgg.echo')),
            'Should flag elgg.echo()',
        );
        $this->assertTrue(
            (bool) array_filter($descriptions, fn($d) => str_contains($d, 'elgg.provide')),
            'Should flag elgg.provide()',
        );
    }

    public function testAnalyzeReturnsFalseWhenClean(): void
    {
        $dir = $this->tempDir();
        mkdir($dir . '/views', 0755, true);
        file_put_contents($dir . '/views/clean.js', "define('foo', function(require) {\n\tvar $ = require('jquery');\n\treturn {};\n});\n");

        try {
            $analysis = $this->rule->analyze($dir);
            $this->assertFalse($analysis->applicable);
        } finally {
            $this->removeDir($dir);
        }
    }

    public function testApplyFixesAllPatterns(): void
    {
        $dir = $this->tempDir();
        $jsDir = $dir . '/views/default/myplugin';
        mkdir($jsDir, 0755, true);

        $inputFile = $this->inputFixture . '/views/default/myplugin/widget.js';
        $outputFile = $jsDir . '/widget.js';
        copy($inputFile, $outputFile);

        try {
            $result = $this->rule->apply($dir);

            $this->assertTrue($result->success);
            $this->assertCount(1, $result->changes);

            $actual = file_get_contents($outputFile);
            $expected = file_get_contents($this->expectedFixture . '/views/default/myplugin/widget.js');

            $this->assertSame($expected, $actual, 'Fixed JS should match expected fixture');
        } finally {
            $this->removeDir($dir);
        }
    }

    public function testApplyDoesNotModifyCleanFiles(): void
    {
        $dir = $this->tempDir();
        mkdir($dir . '/views', 0755, true);
        $original = "define('foo', function(require) {\n\tvar i18n = require('elgg/i18n');\n\treturn {};\n});\n";
        file_put_contents($dir . '/views/clean.js', $original);

        try {
            $result = $this->rule->apply($dir);
            $this->assertEmpty($result->changes);
            $this->assertSame($original, file_get_contents($dir . '/views/clean.js'));
        } finally {
            $this->removeDir($dir);
        }
    }

    public function testApplySkipsNodeModulesAndVendor(): void
    {
        $dir = $this->tempDir();
        foreach (['node_modules', 'vendor'] as $skip) {
            mkdir($dir . '/' . $skip, 0755, true);
            file_put_contents($dir . '/' . $skip . '/lib.js', "require('elgg/init');");
        }

        try {
            $result = $this->rule->apply($dir);
            $this->assertEmpty($result->changes, 'Should skip node_modules and vendor directories');
        } finally {
            $this->removeDir($dir);
        }
    }

    // -------------------------------------------------------------------------

    private function tempDir(): string
    {
        $dir = sys_get_temp_dir() . '/elgg-migrate-amd-' . uniqid();
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
