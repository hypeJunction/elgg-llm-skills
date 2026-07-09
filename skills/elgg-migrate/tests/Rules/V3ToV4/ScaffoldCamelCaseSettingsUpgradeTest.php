<?php

declare(strict_types=1);

namespace ElggMigrate\Tests\Rules\V3ToV4;

use ElggMigrate\Rules\V3ToV4\ScaffoldCamelCaseSettingsUpgrade;
use PHPUnit\Framework\TestCase;

final class ScaffoldCamelCaseSettingsUpgradeTest extends TestCase
{
    private ScaffoldCamelCaseSettingsUpgrade $rule;

    protected function setUp(): void
    {
        $this->rule = new ScaffoldCamelCaseSettingsUpgrade();
    }

    // -------------------------------------------------------------------------
    // Metadata
    // -------------------------------------------------------------------------

    public function testId(): void
    {
        $this->assertSame('migrate-camelcase-plugin-settings', $this->rule->getId());
    }

    public function testCanAutomate(): void
    {
        $this->assertTrue($this->rule->canAutomate());
    }

    // -------------------------------------------------------------------------
    // analyze() — applicability
    // -------------------------------------------------------------------------

    public function testAnalyzeApplicableWhenPluginIdIsCamelCase(): void
    {
        $analysis = $this->rule->analyze($this->fixture('camelcase-id'));

        $this->assertTrue($analysis->applicable);
        $this->assertCount(1, $analysis->findings);
        $this->assertStringContainsString('hypeNotes', $analysis->findings[0]->description);
        $this->assertStringContainsString('hypenotes', $analysis->findings[0]->description);
    }

    public function testAnalyzeNotApplicableWhenPluginIdIsAlreadyLowercase(): void
    {
        $analysis = $this->rule->analyze($this->fixture('lowercase-id'));

        $this->assertFalse($analysis->applicable);
        $this->assertEmpty($analysis->findings);
        $this->assertStringContainsString('already lowercase', $analysis->summary);
    }

    public function testAnalyzeNotApplicableWhenUpgradeAlreadyExists(): void
    {
        $analysis = $this->rule->analyze($this->fixture('already-has-upgrade'));

        $this->assertFalse($analysis->applicable);
        $this->assertStringContainsString('already exists', $analysis->summary);
    }

    public function testAnalyzeNotApplicableWithoutPluginPhp(): void
    {
        $workDir = sys_get_temp_dir() . '/elgg-migrate-camel-' . uniqid();
        mkdir($workDir . '/hypeNotes', 0755, true);

        try {
            $analysis = $this->rule->analyze($workDir . '/hypeNotes');
            $this->assertFalse($analysis->applicable);
            $this->assertStringContainsString('elgg-plugin.php', $analysis->summary);
        } finally {
            $this->removeDir($workDir);
        }
    }

    /**
     * The plugin directory may already have been renamed to lowercase by the time
     * the rule runs, so the legacy casing has to be recovered from composer.json.
     */
    public function testAnalyzeDetectsLegacyIdFromInstallerNameNotDirectory(): void
    {
        $workDir = $this->copyFixture('camelcase-id');

        try {
            // Directory basename is a lowercase temp name — only installer-name is camelCase.
            $analysis = $this->rule->analyze($workDir);
            $this->assertTrue($analysis->applicable);
        } finally {
            $this->removeDir($workDir);
        }
    }

    // -------------------------------------------------------------------------
    // apply() — upgrade class generation
    // -------------------------------------------------------------------------

    public function testApplyCreatesUpgradeClassFile(): void
    {
        $workDir = $this->copyFixture('camelcase-id');

        try {
            $result = $this->rule->apply($workDir);

            $this->assertTrue($result->success);
            $this->assertFileExists($this->upgradePath($workDir));

            $created = array_map(fn($c) => $c->file, $result->changes);
            $this->assertContains('classes/Notes/Upgrades/MigrateCamelCasePluginSettings.php', $created);
        } finally {
            $this->removeDir($workDir);
        }
    }

    public function testApplyGeneratesValidPhp(): void
    {
        $workDir = $this->copyFixture('camelcase-id');

        try {
            $this->rule->apply($workDir);

            exec('php -l ' . escapeshellarg($this->upgradePath($workDir)) . ' 2>&1', $output, $exitCode);
            $this->assertSame(0, $exitCode, 'Generated upgrade must be valid PHP: ' . implode("\n", $output));
        } finally {
            $this->removeDir($workDir);
        }
    }

    public function testApplyBakesBothPluginIdsIntoTheUpgrade(): void
    {
        $workDir = $this->copyFixture('camelcase-id');

        try {
            $this->rule->apply($workDir);
            $content = file_get_contents($this->upgradePath($workDir));

            $this->assertStringContainsString("const LEGACY_PLUGIN_ID = 'hypeNotes';", $content);
            $this->assertStringContainsString("const PLUGIN_ID = 'hypenotes';", $content);
        } finally {
            $this->removeDir($workDir);
        }
    }

    public function testApplyGeneratesBatchWithFourThreeXShape(): void
    {
        $workDir = $this->copyFixture('camelcase-id');

        try {
            $this->rule->apply($workDir);
            $content = file_get_contents($this->upgradePath($workDir));

            $this->assertStringContainsString('namespace Notes\\Upgrades;', $content);
            $this->assertStringContainsString('use Elgg\\Upgrade\\Batch;', $content);
            $this->assertStringContainsString('use Elgg\\Upgrade\\Result;', $content);
            $this->assertStringContainsString('implements Batch', $content);

            foreach (['getVersion', 'shouldBeSkipped', 'needsIncrementOffset', 'countItems', 'run'] as $method) {
                $this->assertStringContainsString("function {$method}(", $content, "Batch must implement {$method}()");
            }

            $this->assertStringContainsString('public function run(Result $result, $offset): Result', $content);
        } finally {
            $this->removeDir($workDir);
        }
    }

    public function testApplyGeneratesTenDigitVersion(): void
    {
        $workDir = $this->copyFixture('camelcase-id');

        try {
            $this->rule->apply($workDir);
            $content = file_get_contents($this->upgradePath($workDir));

            $this->assertMatchesRegularExpression('/return \d{10};/', $content);
        } finally {
            $this->removeDir($workDir);
        }
    }

    /**
     * The database collation is case-insensitive, so querying for the camelCase
     * title also returns the lowercase twin. The batch must re-check in PHP or it
     * will happily copy the (empty) new entity onto itself.
     */
    public function testApplyGeneratedBatchMatchesLegacyIdCaseSensitively(): void
    {
        $workDir = $this->copyFixture('camelcase-id');

        try {
            $this->rule->apply($workDir);
            $content = file_get_contents($this->upgradePath($workDir));

            $this->assertStringContainsString('$candidate->getID() === self::LEGACY_PLUGIN_ID', $content);
        } finally {
            $this->removeDir($workDir);
        }
    }

    /**
     * getAllSettings() merges elgg-plugin.php defaults, so it can never be used to
     * decide whether a setting is already stored on the target entity.
     */
    public function testApplyGeneratedBatchChecksRawMetadataNotSettings(): void
    {
        $workDir = $this->copyFixture('camelcase-id');

        try {
            $this->rule->apply($workDir);
            $content = file_get_contents($this->upgradePath($workDir));

            $this->assertStringContainsString('$target->getAllMetadata()', $content);
            $this->assertStringNotContainsString('$target->getAllSettings()', $content);
            $this->assertStringNotContainsString('$target->getSetting(', $content);
        } finally {
            $this->removeDir($workDir);
        }
    }

    public function testApplyGeneratedBatchSkipsInternalAndArrayMetadata(): void
    {
        $workDir = $this->copyFixture('camelcase-id');

        try {
            $this->rule->apply($workDir);
            $content = file_get_contents($this->upgradePath($workDir));

            $this->assertStringContainsString("unset(\$settings['title'], \$settings['description']);", $content);
            $this->assertStringContainsString("strpos(\$name, 'elgg:internal:') === 0", $content);
            $this->assertStringContainsString('is_array($value)', $content);
        } finally {
            $this->removeDir($workDir);
        }
    }

    /**
     * Disabled (not deleted): a mis-copied setting must stay recoverable.
     */
    public function testApplyGeneratedBatchDisablesRatherThanDeletesLegacyEntity(): void
    {
        $workDir = $this->copyFixture('camelcase-id');

        try {
            $this->rule->apply($workDir);
            $content = file_get_contents($this->upgradePath($workDir));

            $this->assertStringContainsString('$legacy->disable()', $content);
            $this->assertStringNotContainsString('$legacy->delete()', $content);
            $this->assertStringContainsString('$result->getFailureCount() === 0', $content);
        } finally {
            $this->removeDir($workDir);
        }
    }

    public function testApplyGeneratedBatchReadsDisabledEntities(): void
    {
        $workDir = $this->copyFixture('camelcase-id');

        try {
            $this->rule->apply($workDir);
            $content = file_get_contents($this->upgradePath($workDir));

            $this->assertStringContainsString('ELGG_SHOW_DISABLED_ENTITIES', $content);
            $this->assertStringContainsString('ELGG_IGNORE_ACCESS', $content);
        } finally {
            $this->removeDir($workDir);
        }
    }

    // -------------------------------------------------------------------------
    // apply() — elgg-plugin.php registration
    // -------------------------------------------------------------------------

    public function testApplyRegistersUpgradeInPluginPhp(): void
    {
        $workDir = $this->copyFixture('camelcase-id');

        try {
            $result = $this->rule->apply($workDir);

            $modified = array_map(fn($c) => $c->file, $result->changes);
            $this->assertContains('elgg-plugin.php', $modified);

            $content = file_get_contents($workDir . '/elgg-plugin.php');
            $this->assertStringContainsString("'upgrades'", $content);
            $this->assertStringContainsString('MigrateCamelCasePluginSettings::class', $content);
        } finally {
            $this->removeDir($workDir);
        }
    }

    public function testApplyPluginPhpRemainsValidAndPreservesExistingKeys(): void
    {
        $workDir = $this->copyFixture('camelcase-id');

        try {
            $this->rule->apply($workDir);

            $pluginPhpPath = $workDir . '/elgg-plugin.php';
            exec('php -l ' . escapeshellarg($pluginPhpPath) . ' 2>&1', $output, $exitCode);
            $this->assertSame(0, $exitCode, 'elgg-plugin.php must stay valid: ' . implode("\n", $output));

            $config = require $pluginPhpPath;
            $this->assertSame(10, $config['settings']['items_per_page']);
            $this->assertArrayHasKey('actions', $config);
            $this->assertSame(
                ['Notes\\Upgrades\\MigrateCamelCasePluginSettings'],
                $config['upgrades'],
            );
        } finally {
            $this->removeDir($workDir);
        }
    }

    public function testApplyAppendsToAnExistingUpgradesArray(): void
    {
        $workDir = $this->copyFixture('camelcase-id');

        try {
            file_put_contents($workDir . '/elgg-plugin.php', <<<'PHP'
            <?php

            return [
                'upgrades' => [
                    \Notes\Upgrades\SomethingElse::class,
                ],
            ];
            PHP);

            $this->rule->apply($workDir);

            $content = file_get_contents($workDir . '/elgg-plugin.php');
            $this->assertStringContainsString('SomethingElse::class', $content, 'Existing upgrade must survive');
            $this->assertStringContainsString('MigrateCamelCasePluginSettings::class', $content);

            exec('php -l ' . escapeshellarg($workDir . '/elgg-plugin.php') . ' 2>&1', $output, $exitCode);
            $this->assertSame(0, $exitCode, implode("\n", $output));
        } finally {
            $this->removeDir($workDir);
        }
    }

    // -------------------------------------------------------------------------
    // apply() — no-op cases
    // -------------------------------------------------------------------------

    public function testApplyNoOpWhenPluginIdIsAlreadyLowercase(): void
    {
        $result = $this->rule->apply($this->fixture('lowercase-id'));

        $this->assertTrue($result->success);
        $this->assertEmpty($result->changes);
        $this->assertNotEmpty($result->warnings);
    }

    public function testApplySkipsWhenUpgradeAlreadyExists(): void
    {
        $workDir = $this->copyFixture('already-has-upgrade');

        try {
            $result = $this->rule->apply($workDir);

            $this->assertTrue($result->success);
            $this->assertEmpty($result->changes);
            $this->assertStringContainsString('already exists', implode(' ', $result->warnings));
        } finally {
            $this->removeDir($workDir);
        }
    }

    public function testApplyIsIdempotent(): void
    {
        $workDir = $this->copyFixture('camelcase-id');

        try {
            $this->assertTrue($this->rule->apply($workDir)->success);

            $second = $this->rule->apply($workDir);
            $this->assertTrue($second->success);
            $this->assertEmpty($second->changes, 'Second apply should produce no changes');
        } finally {
            $this->removeDir($workDir);
        }
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    private function fixture(string $name): string
    {
        return __DIR__ . '/../../fixtures/3x-to-4x/scaffold-camelcase-settings-upgrade/' . $name;
    }

    private function upgradePath(string $workDir): string
    {
        return $workDir . '/classes/Notes/Upgrades/MigrateCamelCasePluginSettings.php';
    }

    private function copyFixture(string $name): string
    {
        $dst = sys_get_temp_dir() . '/elgg-migrate-camel-' . uniqid();
        $this->copyDir($this->fixture($name), $dst);

        return $dst;
    }

    private function copyDir(string $src, string $dst): void
    {
        mkdir($dst, 0755, true);

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($src, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::SELF_FIRST,
        );

        foreach ($iterator as $item) {
            /** @var \SplFileInfo $item */
            $target = $dst . '/' . $iterator->getSubPathname();
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

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );

        foreach ($iterator as $item) {
            /** @var \SplFileInfo $item */
            $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
        }

        rmdir($dir);
    }
}
