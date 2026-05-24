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

        // bind, unbind, isArray, parseJSON, delegate — the $el.bind shares the
        // same .bind finding bucket; Function.prototype.bind must NOT add one.
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

    /**
     * Regression: Function.prototype.bind (e.g. `this.fn.bind(this)`) must NOT
     * be rewritten to `.on()` — that's a runtime breakage caught on csv_process
     * and bodyology tour during 3→4 migration. See bead zjioe.
     */
    public function testFunctionPrototypeBindIsNotRewrittenOrFlagged(): void
    {
        $dir = $this->tempDir();
        mkdir($dir . '/views/default/myplugin', 0755, true);
        $js = $dir . '/views/default/myplugin/poller.js';
        $source = "define('myplugin/poller', function(require) {\n"
                . "    function Poller() {}\n"
                . "    Poller.prototype.start = function() {\n"
                . "        window.setTimeout(this.getLine.bind(this), 2000);\n"
                . "        someArr.map(fn.bind(ctx));\n"
                . "    };\n"
                . "    return Poller;\n"
                . "});\n";
        file_put_contents($js, $source);

        try {
            $analysis = $this->rule->analyze($dir);
            $this->assertFalse(
                $analysis->applicable,
                'Function.prototype.bind() must not surface as a jQuery .bind() finding',
            );

            $result = $this->rule->apply($dir);
            $this->assertTrue($result->success);
            $this->assertEmpty($result->changes, 'No file should be rewritten');

            $this->assertSame(
                $source,
                file_get_contents($js),
                'Function.prototype.bind() must remain byte-for-byte unchanged',
            );
        } finally {
            $this->removeDir($dir);
        }
    }

    /**
     * Regression: jQuery .bind() on a $-prefixed alias (`$el.bind('click', ...)`)
     * is the conventional plugin pattern and MUST be rewritten to .on().
     */
    public function testDollarPrefixedAliasBindIsRewritten(): void
    {
        $dir = $this->tempDir();
        mkdir($dir . '/views/default/myplugin', 0755, true);
        $js = $dir . '/views/default/myplugin/alias.js';
        file_put_contents(
            $js,
            "define('myplugin/alias', function(require) {\n"
            . "    var \$ = require('jquery');\n"
            . "    var \$el = \$('.foo');\n"
            . "    \$el.bind('click', function() {});\n"
            . "    \$el.unbind('focus');\n"
            . "});\n",
        );

        try {
            $result = $this->rule->apply($dir);
            $this->assertTrue($result->success);
            $this->assertCount(1, $result->changes);

            $out = file_get_contents($js);
            $this->assertStringContainsString("\$el.on('click'", $out);
            $this->assertStringContainsString("\$el.off('focus'", $out);
            $this->assertStringNotContainsString('.bind(', $out);
            $this->assertStringNotContainsString('.unbind(', $out);
        } finally {
            $this->removeDir($dir);
        }
    }

    /**
     * Regression: files under vendors/ (e.g. bundled joyride, hopscotch,
     * WideImage) must NOT be rewritten in place. Surfaced on bodyology tour
     * 3→4 — tour agent had to revert vendors/joyride and vendors/hopscotch.
     */
    public function testVendorsDirectoryIsExcluded(): void
    {
        $dir = $this->tempDir();
        mkdir($dir . '/vendors/joyride', 0755, true);
        $vendorJs = $dir . '/vendors/joyride/joyride.js';
        $source = "// Upstream joyride bundle\n"
                . "(function(\$) {\n"
                . "    \$('.tour').bind('click', function() {});\n"
                . "    \$('.tour').unbind('focus');\n"
                . "    \$.parseJSON('{}');\n"
                . "})(jQuery);\n";
        file_put_contents($vendorJs, $source);

        try {
            $analysis = $this->rule->analyze($dir);
            $this->assertFalse(
                $analysis->applicable,
                'vendors/ tree must be invisible to the scanner',
            );

            $result = $this->rule->apply($dir);
            $this->assertEmpty($result->changes);
            $this->assertSame(
                $source,
                file_get_contents($vendorJs),
                'vendors/ files must remain byte-for-byte unchanged',
            );
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
