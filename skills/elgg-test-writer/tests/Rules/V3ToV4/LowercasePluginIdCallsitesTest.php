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
