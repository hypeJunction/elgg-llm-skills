<?php

declare(strict_types=1);

namespace ElggMigrate\Tests\Rules\V3ToV4;

use ElggMigrate\Rules\V3ToV4\CssRegistration;
use PHPUnit\Framework\TestCase;

final class CssRegistrationTest extends TestCase
{
    private CssRegistration $rule;

    protected function setUp(): void
    {
        $this->rule = new CssRegistration();
    }

    public function testAnalyzeFindsCssJsFunctions(): void
    {
        $dir = __DIR__ . '/../../fixtures/3x-to-4x/css-registration/input';
        $analysis = $this->rule->analyze($dir);

        $this->assertTrue($analysis->applicable);
        // elgg_register_css, elgg_load_css, elgg_register_js, elgg_load_js = 4
        $this->assertCount(4, $analysis->findings);
    }

    public function testAnalyzeReturnsFalseForCleanPhp(): void
    {
        $workDir = sys_get_temp_dir() . '/elgg-migrate-' . uniqid();
        mkdir($workDir, 0755, true);
        file_put_contents($workDir . '/test.php', "<?php\nelgg_load_external_file('css', 'my-styles');\n");

        try {
            $analysis = $this->rule->analyze($workDir);
            $this->assertFalse($analysis->applicable);
        } finally {
            $this->removeDir($workDir);
        }
    }

    public function testApplyRewritesAllFourAutoFixableCalls(): void
    {
        $workDir = sys_get_temp_dir() . '/elgg-migrate-' . uniqid();
        mkdir($workDir, 0755, true);
        $file = $workDir . '/code.php';
        copy(__DIR__ . '/../../fixtures/3x-to-4x/css-registration/input/code.php', $file);

        try {
            $result = $this->rule->apply($workDir);

            $this->assertTrue($result->success);
            $this->assertCount(1, $result->changes, 'One file should be modified');

            $out = file_get_contents($file);
            $this->assertStringContainsString(
                "elgg_register_external_file('css', 'my-styles', 'path/to/style.css')",
                $out,
            );
            $this->assertStringContainsString(
                "elgg_load_external_file('css', 'my-styles')",
                $out,
            );
            $this->assertStringContainsString(
                "elgg_register_external_file('js', 'my-script', 'path/to/script.js')",
                $out,
            );
            $this->assertStringContainsString(
                "elgg_load_external_file('js', 'my-script')",
                $out,
            );

            // No legacy call should remain.
            $this->assertStringNotContainsString('elgg_register_css(', $out);
            $this->assertStringNotContainsString('elgg_load_css(', $out);
            $this->assertStringNotContainsString('elgg_register_js(', $out);
            $this->assertStringNotContainsString('elgg_load_js(', $out);
        } finally {
            $this->removeDir($workDir);
        }
    }

    /**
     * elgg_get_loaded_css/js have shifted default args; the rule should warn
     * (with file:line) rather than blind-rewrite. Regression for bead zjioe.
     */
    public function testGetLoadedCssJsAreWarnOnlyNotRewritten(): void
    {
        $workDir = sys_get_temp_dir() . '/elgg-migrate-' . uniqid();
        mkdir($workDir, 0755, true);
        $file = $workDir . '/loaded.php';
        $source = "<?php\n"
                . "\$css = elgg_get_loaded_css();\n"
                . "\$js = elgg_get_loaded_js();\n";
        file_put_contents($file, $source);

        try {
            $analysis = $this->rule->analyze($workDir);
            $this->assertTrue($analysis->applicable);
            $this->assertCount(2, $analysis->findings);

            $result = $this->rule->apply($workDir);
            $this->assertEmpty($result->changes, 'Warn-only calls must not be rewritten');
            $this->assertCount(2, $result->warnings);
            $this->assertSame($source, file_get_contents($file), 'File must be untouched');

            foreach ($result->warnings as $w) {
                $this->assertStringContainsString('loaded.php:', $w);
            }
        } finally {
            $this->removeDir($workDir);
        }
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
