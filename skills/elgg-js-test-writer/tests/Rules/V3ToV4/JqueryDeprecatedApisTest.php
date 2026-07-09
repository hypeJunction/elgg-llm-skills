<?php

declare(strict_types=1);

namespace ElggMigrate\Tests\Rules\V3ToV4;

use ElggMigrate\Rules\V3ToV4\JqueryDeprecatedApis;
use PHPUnit\Framework\TestCase;

final class JqueryDeprecatedApisTest extends TestCase
{
    private JqueryDeprecatedApis $rule;
    private string $inputFixture;
    private string $expectedFixture;

    protected function setUp(): void
    {
        $this->rule = new JqueryDeprecatedApis();
        $this->inputFixture = __DIR__ . '/../../fixtures/3x-to-4x/jquery-deprecated-apis/input';
        $this->expectedFixture = __DIR__ . '/../../fixtures/3x-to-4x/jquery-deprecated-apis/expected';
    }

    public function testAnalyzeFindsAllDeprecatedPatterns(): void
    {
        $analysis = $this->rule->analyze($this->inputFixture);

        $this->assertTrue($analysis->applicable);

        $this->assertCount(5, $analysis->findings, 'Expected 5 findings: bind, unbind, isArray, parseJSON, delegate');

        $descriptions = array_map(fn($f) => $f->description, $analysis->findings);

        $this->assertTrue(
            (bool) array_filter($descriptions, fn($d) => str_contains($d, '.bind()')),
            'Should flag .bind()',
        );
        $this->assertTrue(
            (bool) array_filter($descriptions, fn($d) => str_contains($d, '.unbind()')),
            'Should flag .unbind()',
        );
        $this->assertTrue(
            (bool) array_filter($descriptions, fn($d) => str_contains($d, '$.isArray()')),
            'Should flag $.isArray()',
        );
        $this->assertTrue(
            (bool) array_filter($descriptions, fn($d) => str_contains($d, '$.parseJSON()')),
            'Should flag $.parseJSON()',
        );
        $this->assertTrue(
            (bool) array_filter($descriptions, fn($d) => str_contains($d, '.delegate()')),
            'Should flag .delegate()',
        );
    }

    public function testApplyFixesAutoFixablePatternsAndLeavesDelegate(): void
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
            $this->assertCount(1, $result->changes, 'One file should be modified');
            $this->assertCount(1, $result->warnings, 'One warning for .delegate()');

            $this->assertStringContainsString('.delegate(', $result->warnings[0]);

            $actual = file_get_contents($outputFile);
            $expected = file_get_contents($this->expectedFixture . '/views/default/myplugin/widget.js');

            $this->assertSame($expected, $actual, 'Fixed JS should match expected fixture');
        } finally {
            $this->removeDir($dir);
        }
    }

    public function testFixedOutputMatchesExpectedFixture(): void
    {
        $dir = $this->tempDir();
        $jsDir = $dir . '/views/default/myplugin';
        mkdir($jsDir, 0755, true);

        copy($this->inputFixture . '/views/default/myplugin/widget.js', $jsDir . '/widget.js');

        try {
            $this->rule->apply($dir);

            $actual   = file_get_contents($jsDir . '/widget.js');
            $expected = file_get_contents($this->expectedFixture . '/views/default/myplugin/widget.js');

            $this->assertSame($expected, $actual);
        } finally {
            $this->removeDir($dir);
        }
    }

    public function testCleanFilesProduceNoFindings(): void
    {
        $dir = $this->tempDir();
        mkdir($dir . '/views', 0755, true);
        file_put_contents($dir . '/views/clean.js', "define('foo', function(require) {\n\tvar $ = require('jquery');\n\treturn {};\n});\n");

        try {
            $analysis = $this->rule->analyze($dir);
            $this->assertFalse($analysis->applicable);
            $this->assertEmpty($analysis->findings);
        } finally {
            $this->removeDir($dir);
        }
    }

    // -------------------------------------------------------------------------

    private function tempDir(): string
    {
        $dir = sys_get_temp_dir() . '/elgg-migrate-jquery-' . uniqid();
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
