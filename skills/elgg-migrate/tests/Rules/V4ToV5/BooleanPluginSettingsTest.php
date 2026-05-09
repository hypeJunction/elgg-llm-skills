<?php

declare(strict_types=1);

namespace ElggMigrate\Tests\Rules\V4ToV5;

use ElggMigrate\Rules\V4ToV5\BooleanPluginSettings;
use PHPUnit\Framework\TestCase;

final class BooleanPluginSettingsTest extends TestCase
{
    private BooleanPluginSettings $rule;

    protected function setUp(): void
    {
        $this->rule = new BooleanPluginSettings();
    }

    public function testId(): void
    {
        $this->assertSame('boolean-plugin-settings', $this->rule->getId());
    }

    public function testCanAutomate(): void
    {
        $this->assertTrue($this->rule->canAutomate());
    }

    // -------------------------------------------------------------------------
    // analyze() — detection
    // -------------------------------------------------------------------------

    public function testAnalyzeDetectsYesNoReads(): void
    {
        $dir = __DIR__ . '/../../fixtures/4x-to-5x/boolean-plugin-settings/input';
        $analysis = $this->rule->analyze($dir);

        $this->assertTrue($analysis->applicable);

        $descriptions = array_map(fn($f) => $f->description, $analysis->findings);
        $combined = implode("\n", $descriptions);

        // Comparisons with 'yes'/'no' should be flagged.
        $this->assertStringContainsString("'yes'", $combined);
        $this->assertStringContainsString("'no'", $combined);
    }

    public function testAnalyzeDetectsYesNoWrites(): void
    {
        $dir = __DIR__ . '/../../fixtures/4x-to-5x/boolean-plugin-settings/input';
        $analysis = $this->rule->analyze($dir);

        $writeFindings = array_filter(
            $analysis->findings,
            fn($f) => str_contains($f->description, 'elgg_set_plugin_setting()'),
        );

        // We expect findings for 'yes' and 'no' writes.
        $this->assertGreaterThanOrEqual(2, count($writeFindings));
    }

    public function testAnalyzeDetectsElggPluginPhpDefaults(): void
    {
        $dir = __DIR__ . '/../../fixtures/4x-to-5x/boolean-plugin-settings/input';
        $analysis = $this->rule->analyze($dir);

        $pluginPhpFindings = array_filter(
            $analysis->findings,
            fn($f) => $f->file === 'elgg-plugin.php',
        );

        // 3 settings with yes/no defaults: enable_feature, show_sidebar, debug_mode
        $this->assertGreaterThanOrEqual(3, count($pluginPhpFindings));
    }

    public function testAnalyzeNotApplicableOnCleanCode(): void
    {
        $dir = sys_get_temp_dir() . '/elgg-migrate-bool-clean-' . uniqid();
        mkdir($dir, 0755, true);
        file_put_contents($dir . '/clean.php', "<?php\nelgg_set_plugin_setting('title', 'My Plugin', 'myplugin');\n");

        try {
            $analysis = $this->rule->analyze($dir);
            $this->assertFalse($analysis->applicable);
        } finally {
            $this->removeDir($dir);
        }
    }

    public function testAnalyzeDetectsGetSettingMethodCall(): void
    {
        $dir = sys_get_temp_dir() . '/elgg-migrate-bool-method-' . uniqid();
        mkdir($dir, 0755, true);
        file_put_contents($dir . '/plugin.php', <<<'PHP'
<?php
$plugin = elgg_get_plugin_from_id('myplugin');
if ($plugin->getSetting('enabled') === 'yes') {
    // ok
}
PHP);

        try {
            $analysis = $this->rule->analyze($dir);
            $this->assertTrue($analysis->applicable);
        } finally {
            $this->removeDir($dir);
        }
    }

    // -------------------------------------------------------------------------
    // apply() — transformation
    // -------------------------------------------------------------------------

    public function testApplyRewritesWriteCallsToBoolean(): void
    {
        $workDir = $this->makeWorkDir();

        try {
            $result = $this->rule->apply($workDir);
            $this->assertTrue($result->success);

            $code = file_get_contents($workDir . '/classes/HypeMyPlugin/Actions/Save.php');
            // 'yes' and 'no' string args should be gone.
            $this->assertStringNotContainsString("'yes', 'hypemyplugin'", $code);
            $this->assertStringNotContainsString("'no', 'hypemyplugin'", $code);
            // Boolean replacements should be present.
            $this->assertStringContainsString('true', $code);
            $this->assertStringContainsString('false', $code);
        } finally {
            $this->removeDir($workDir);
        }
    }

    public function testApplyRewritesComparisonsToBoolChecks(): void
    {
        $workDir = $this->makeWorkDir();

        try {
            $this->rule->apply($workDir);
            $code = file_get_contents($workDir . '/classes/HypeMyPlugin/Actions/Save.php');

            // String comparison with 'yes' should be replaced with a bool check.
            $this->assertDoesNotMatchRegularExpression("/=== 'yes'/", $code);
            $this->assertDoesNotMatchRegularExpression("/=== 'no'/", $code);
        } finally {
            $this->removeDir($workDir);
        }
    }

    public function testApplyUpdatesElggPluginPhpDefaults(): void
    {
        $workDir = $this->makeWorkDir();

        try {
            $this->rule->apply($workDir);
            $code = file_get_contents($workDir . '/elgg-plugin.php');

            // 'yes'/'no' default values should be gone.
            $this->assertStringNotContainsString("'value' => 'yes'", $code);
            $this->assertStringNotContainsString("'value' => 'no'", $code);
            // Non-boolean settings should be untouched.
            $this->assertStringContainsString("'value' => 'My Plugin'", $code);
        } finally {
            $this->removeDir($workDir);
        }
    }

    public function testApplyScaffoldsUpgradeClass(): void
    {
        $workDir = $this->makeWorkDir();

        try {
            $result = $this->rule->apply($workDir);
            $this->assertTrue($result->success);

            $createdChanges = array_filter($result->changes, fn($c) => $c->type === 'created');
            $this->assertNotEmpty($createdChanges, 'Expected a created FileChange for the scaffold');

            // The scaffold file should exist.
            // PSR-4: HypeMyPlugin\ → classes/, so HypeMyPlugin\Upgrades maps to classes/Upgrades/
            $scaffoldFile = $workDir . '/classes/Upgrades/MigrateSwitchSettings.php';
            $this->assertFileExists($scaffoldFile);

            $scaffold = file_get_contents($scaffoldFile);
            $this->assertStringContainsString('extends SystemUpgrade', $scaffold);
            $this->assertStringContainsString('MigrateSwitchSettings', $scaffold);
            $this->assertStringContainsString("'enable_feature'", $scaffold);
            $this->assertStringContainsString("'show_sidebar'", $scaffold);
            $this->assertStringContainsString("'debug_mode'", $scaffold);
        } finally {
            $this->removeDir($workDir);
        }
    }

    public function testApplyScaffoldIsValidPhp(): void
    {
        $workDir = $this->makeWorkDir();

        try {
            $this->rule->apply($workDir);
            // PSR-4: HypeMyPlugin\ → classes/, so HypeMyPlugin\Upgrades maps to classes/Upgrades/
            $scaffoldFile = $workDir . '/classes/Upgrades/MigrateSwitchSettings.php';
            if (is_file($scaffoldFile)) {
                exec("php -l {$scaffoldFile} 2>&1", $output, $exitCode);
                $this->assertSame(0, $exitCode, implode("\n", $output));
            }
        } finally {
            $this->removeDir($workDir);
        }
    }

    public function testApplyDoesNotOverwriteExistingScaffold(): void
    {
        $workDir = $this->makeWorkDir();

        try {
            $this->rule->apply($workDir);
            // PSR-4: HypeMyPlugin\ → classes/, so HypeMyPlugin\Upgrades maps to classes/Upgrades/
            $scaffoldFile = $workDir . '/classes/Upgrades/MigrateSwitchSettings.php';

            $original = file_get_contents($scaffoldFile);
            file_put_contents($scaffoldFile, '<?php // custom');

            $this->rule->apply($workDir);
            $this->assertSame('<?php // custom', file_get_contents($scaffoldFile));
        } finally {
            $this->removeDir($workDir);
        }
    }

    public function testApplyOnCleanDirSucceedsWithNoChanges(): void
    {
        $dir = sys_get_temp_dir() . '/elgg-migrate-bool-noop-' . uniqid();
        mkdir($dir, 0755, true);
        file_put_contents($dir . '/clean.php', "<?php\n// nothing here\n");

        try {
            $result = $this->rule->apply($dir);
            $this->assertTrue($result->success);
            $this->assertEmpty($result->changes);
        } finally {
            $this->removeDir($dir);
        }
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    /**
     * Copy the fixture input directory to a temp location for mutation tests.
     */
    private function makeWorkDir(): string
    {
        $src = __DIR__ . '/../../fixtures/4x-to-5x/boolean-plugin-settings/input';
        $dst = sys_get_temp_dir() . '/elgg-migrate-bool-' . uniqid();

        $this->copyDir($src, $dst);

        return $dst;
    }

    private function copyDir(string $src, string $dst): void
    {
        if (!is_dir($dst)) {
            mkdir($dst, 0755, true);
        }
        $iter = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($src, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::SELF_FIRST,
        );
        foreach ($iter as $item) {
            $target = $dst . '/' . $iter->getSubPathname();
            if ($item->isDir()) {
                mkdir($target, 0755, true);
            } else {
                copy($item->getPathname(), $target);
            }
        }
    }

    private function removeDir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        $iter = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($iter as $f) {
            $f->isDir() ? rmdir($f->getPathname()) : unlink($f->getPathname());
        }
        rmdir($dir);
    }
}
