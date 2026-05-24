<?php

declare(strict_types=1);

namespace ElggMigrate\Tests\Rules\V3ToV4;

use ElggMigrate\Rules\V3ToV4\RemovedFunctions;
use PHPUnit\Framework\TestCase;

final class RemovedFunctionsTest extends TestCase
{
    private RemovedFunctions $rule;

    protected function setUp(): void
    {
        $this->rule = new RemovedFunctions();
    }

    public function testAnalyzeFindsRemovedFunctions(): void
    {
        $dir = __DIR__ . '/../../fixtures/3x-to-4x/removed-functions-4x/input';
        $analysis = $this->rule->analyze($dir);

        $this->assertTrue($analysis->applicable);
        // Fixture exercises 8 flagged calls:
        //   removed:    validate_email_address, validate_password, elgg_set_plugin_setting,
        //               elgg_get_filter_tabs, elgg_register_admin_menu_item (5)
        //   deprecated: forward, elgg_register_entity_type, add_translation (3)
        $this->assertCount(8, $analysis->findings);
    }

    public function testAnalyzeDoesNotFlagNonRemovedFunctions(): void
    {
        $workDir = sys_get_temp_dir() . '/elgg-migrate-' . uniqid();
        mkdir($workDir, 0755, true);
        file_put_contents($workDir . '/test.php', "<?php\nelgg_echo('hello');\nelgg_get_config('key');\n");

        try {
            $analysis = $this->rule->analyze($workDir);
            $this->assertFalse($analysis->applicable);
        } finally {
            $this->removeDir($workDir);
        }
    }

    public function testApplyProducesWarningsButNoChanges(): void
    {
        $workDir = sys_get_temp_dir() . '/elgg-migrate-' . uniqid();
        mkdir($workDir, 0755, true);
        copy(__DIR__ . '/../../fixtures/3x-to-4x/removed-functions-4x/input/code.php', $workDir . '/code.php');

        try {
            $result = $this->rule->apply($workDir);

            $this->assertTrue($result->success);
            $this->assertEmpty($result->changes, 'Warn-only rule should not modify files');
            $this->assertCount(8, $result->warnings);
        } finally {
            $this->removeDir($workDir);
        }
    }

    /**
     * Regression: bd elgg-migrate-4pye6 — elgg_register_admin_menu_item() was missing
     * from the MAP entirely, so the 3→4 migration left the call in place and
     * activation faulted in csv_process. Must be flagged as 'removed' (no deprecation shim).
     */
    public function testFlagsElggRegisterAdminMenuItemAsRemoved(): void
    {
        $this->assertArrayHasKey('elgg_register_admin_menu_item', RemovedFunctions::MAP);
        $entry = RemovedFunctions::MAP['elgg_register_admin_menu_item'];
        $this->assertSame('removed', $entry['status']);
        $this->assertStringContainsString('menus.page', $entry['note']);
    }

    /**
     * Regression: bd elgg-migrate-4pye6 — forward() ships in deprecated-4.0.php and
     * still works through 4.x. Flagging it as 'removed in 4.0' was a false positive
     * that blocked triage when chasing real activation fatals.
     */
    public function testForwardIsFlaggedAsDeprecatedNotRemoved(): void
    {
        $this->assertArrayHasKey('forward', RemovedFunctions::MAP);
        $this->assertSame('deprecated', RemovedFunctions::MAP['forward']['status']);
    }

    /**
     * Regression: bd elgg-migrate-5h0u4 — add_translation() is deprecated in 4.3
     * (still ships via deprecated-4.3.php) but REMOVED in 5.x. Must be flagged in
     * the 3→4 sweep so plugins rewrite languages/<lang>.php before they hit 5.0.
     */
    public function testFlagsAddTranslationAsDeprecated(): void
    {
        $this->assertArrayHasKey('add_translation', RemovedFunctions::MAP);
        $entry = RemovedFunctions::MAP['add_translation'];
        $this->assertSame('deprecated', $entry['status']);
        $this->assertStringContainsString('return [', $entry['note']);
        $this->assertStringContainsString('REMOVED in 5.x', $entry['note']);
    }

    /**
     * elgg_register_entity_type() ships in deprecated-4.1.php — same false-positive
     * class as forward(). Should be 'deprecated', not 'removed'.
     */
    public function testElggRegisterEntityTypeIsFlaggedAsDeprecated(): void
    {
        $this->assertArrayHasKey('elgg_register_entity_type', RemovedFunctions::MAP);
        $this->assertSame('deprecated', RemovedFunctions::MAP['elgg_register_entity_type']['status']);
    }

    public function testFindingDescriptionsDistinguishRemovedFromDeprecated(): void
    {
        $dir = __DIR__ . '/../../fixtures/3x-to-4x/removed-functions-4x/input';
        $analysis = $this->rule->analyze($dir);

        $byFunc = [];
        foreach ($analysis->findings as $f) {
            preg_match('/^(\w+)\(\)/', $f->description, $m);
            $byFunc[$m[1] ?? ''] = $f->description;
        }

        $this->assertStringContainsString('removed in 4.0', $byFunc['validate_email_address'] ?? '');
        $this->assertStringContainsString('removed in 4.0', $byFunc['elgg_register_admin_menu_item'] ?? '');
        $this->assertStringContainsString('deprecated in 4.x', $byFunc['forward'] ?? '');
        $this->assertStringContainsString('deprecated in 4.x', $byFunc['add_translation'] ?? '');
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
