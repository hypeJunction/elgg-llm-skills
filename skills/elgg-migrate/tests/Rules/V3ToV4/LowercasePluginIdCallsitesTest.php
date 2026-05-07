<?php

declare(strict_types=1);

namespace ElggMigrate\Tests\Rules\V3ToV4;

use ElggMigrate\Rules\V3ToV4\LowercasePluginIdCallsites;
use PHPUnit\Framework\TestCase;

final class LowercasePluginIdCallsitesTest extends TestCase
{
    private LowercasePluginIdCallsites $rule;

    protected function setUp(): void
    {
        $this->rule = new LowercasePluginIdCallsites();
    }

    public function testAnalyzeFindsUppercasePluginIds(): void
    {
        $dir = __DIR__ . '/../../fixtures/3x-to-4x/lowercase-plugin-id/input';
        $analysis = $this->rule->analyze($dir);

        $this->assertTrue($analysis->applicable);
        // hypeDirectory×3, HypeDirectory×1, hypeSeo×1 = 5 findings
        $this->assertCount(5, $analysis->findings);
    }

    public function testAnalyzeDoesNotFlagLowercaseIds(): void
    {
        $workDir = sys_get_temp_dir() . '/elgg-migrate-' . uniqid();
        mkdir($workDir, 0755, true);
        file_put_contents($workDir . '/clean.php', "<?php\nelgg_get_plugin_from_id('hypedirectory');\nelgg_get_plugin_setting('k', 'hypedirectory');\n");

        try {
            $analysis = $this->rule->analyze($workDir);
            $this->assertFalse($analysis->applicable);
        } finally {
            $this->removeDir($workDir);
        }
    }

    public function testAnalyzeDoesNotFlagDynamicArgs(): void
    {
        $workDir = sys_get_temp_dir() . '/elgg-migrate-' . uniqid();
        mkdir($workDir, 0755, true);
        file_put_contents($workDir . '/dynamic.php', "<?php\nelgg_get_plugin_from_id(\$plugin_id);\nelgg_get_plugin_setting('k', \$plugin_id);\n");

        try {
            $analysis = $this->rule->analyze($workDir);
            $this->assertFalse($analysis->applicable);
        } finally {
            $this->removeDir($workDir);
        }
    }

    public function testApplyLowercasesPluginIds(): void
    {
        $workDir = sys_get_temp_dir() . '/elgg-migrate-' . uniqid();
        mkdir($workDir, 0755, true);
        file_put_contents($workDir . '/plugin.php', "<?php\n\$p = elgg_get_plugin_from_id('hypeDirectory');\n\$s = elgg_get_plugin_setting('key', 'hypeDirectory');\n\$u = elgg_get_plugin_user_setting('key', 0, 'hypeDirectory');\n");

        try {
            $result = $this->rule->apply($workDir);

            $this->assertTrue($result->success);
            $this->assertCount(1, $result->changes);

            $output = file_get_contents($workDir . '/plugin.php');
            $this->assertStringContainsString("'hypedirectory'", $output);
            $this->assertStringNotContainsString("'hypeDirectory'", $output);
        } finally {
            $this->removeDir($workDir);
        }
    }

    public function testApplyPreservesUntouchedFormatting(): void
    {
        // Regression test for elgg-migrate-682r: prettyPrintFile() collapsed
        // bespoke formatting (blank lines, comments, multi-line arrays).
        // The rule must only touch the bytes corresponding to the changed
        // string literal — every other byte must round-trip identically.

        $workDir = sys_get_temp_dir() . '/elgg-migrate-' . uniqid();
        mkdir($workDir, 0755, true);

        $original = <<<'PHP'
            <?php

            /**
             * Plugin font configuration.
             *
             * Long header docblock with multiple lines, deliberate blank
             * lines, and a short summary that should round-trip exactly.
             */

            namespace HypeJunction\Theme;


            class Fonts
            {
                public function getDefaultFamilies(): array
                {
                    // Read from plugin settings — the ID must be lowercased.
                    $families = elgg_get_plugin_setting(
                        'font_families',
                        'hypeTheme',
                        ['Inter', 'Roboto', 'Helvetica']
                    );

                    return (array) $families;
                }
            }

            PHP;

        $file = $workDir . '/Fonts.php';
        file_put_contents($file, $original);

        try {
            $result = $this->rule->apply($workDir);
            $this->assertTrue($result->success);
            $this->assertCount(1, $result->changes);

            $modified = file_get_contents($file);

            // The targeted change happened.
            $this->assertStringContainsString("'hypetheme'", $modified);
            $this->assertStringNotContainsString("'hypeTheme'", $modified);

            // Everything else round-tripped byte-for-byte. Replacing only
            // the changed token in the original yields exactly the modified
            // file — no whitespace, comments, or array layout drift.
            $expected = str_replace("'hypeTheme'", "'hypetheme'", $original);
            $this->assertSame($expected, $modified);
        } finally {
            $this->removeDir($workDir);
        }
    }

    public function testApplyDoesNotModifyAlreadyLowercaseFiles(): void
    {
        $workDir = sys_get_temp_dir() . '/elgg-migrate-' . uniqid();
        mkdir($workDir, 0755, true);
        $original = "<?php\nelgg_get_plugin_from_id('hypedirectory');\n";
        file_put_contents($workDir . '/clean.php', $original);

        try {
            $result = $this->rule->apply($workDir);

            $this->assertTrue($result->success);
            $this->assertEmpty($result->changes);
            $this->assertSame($original, file_get_contents($workDir . '/clean.php'));
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
