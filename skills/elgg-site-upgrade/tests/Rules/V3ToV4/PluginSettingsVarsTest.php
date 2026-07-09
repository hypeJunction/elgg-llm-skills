<?php

declare(strict_types=1);

namespace ElggMigrate\Tests\Rules\V3ToV4;

use ElggMigrate\Rules\V3ToV4\PluginSettingsVars;
use PHPUnit\Framework\TestCase;

final class PluginSettingsVarsTest extends TestCase
{
    private PluginSettingsVars $rule;

    protected function setUp(): void
    {
        $this->rule = new PluginSettingsVars();
    }

    public function testAnalyzeFindsPattern(): void
    {
        $dir = __DIR__ . '/../../fixtures/3x-to-4x/plugin-settings-vars/input';
        $analysis = $this->rule->analyze($dir);

        $this->assertTrue($analysis->applicable);
        $this->assertCount(1, $analysis->findings);
    }

    public function testApplyRewritesFileCorrectly(): void
    {
        $workDir = sys_get_temp_dir() . '/elgg-migrate-' . uniqid();
        $nestedDir = $workDir . '/views/default/myplugin';
        mkdir($nestedDir, 0755, true);

        $inputFile = $workDir . '/views/default/myplugin/settings.php';
        copy(
            __DIR__ . '/../../fixtures/3x-to-4x/plugin-settings-vars/input/views/default/myplugin/settings.php',
            $inputFile,
        );

        try {
            $result = $this->rule->apply($workDir);

            $this->assertTrue($result->success);
            $this->assertCount(1, $result->changes);

            $expected = file_get_contents(
                __DIR__ . '/../../fixtures/3x-to-4x/plugin-settings-vars/expected/views/default/myplugin/settings.php',
            );
            $actual = file_get_contents($inputFile);

            $this->assertSame($expected, $actual);
        } finally {
            $this->removeDir($workDir);
        }
    }

    public function testApplyDoesNotModifyCleanFiles(): void
    {
        $workDir = sys_get_temp_dir() . '/elgg-migrate-' . uniqid();
        mkdir($workDir, 0755, true);
        $cleanContent = "<?php\n\$entity = \$vars['entity'];\necho \$entity->getSetting('x');\n";
        file_put_contents($workDir . '/settings.php', $cleanContent);

        try {
            $result = $this->rule->apply($workDir);

            $this->assertTrue($result->success);
            $this->assertEmpty($result->changes);
            $this->assertSame($cleanContent, file_get_contents($workDir . '/settings.php'));
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
