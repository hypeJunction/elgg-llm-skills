<?php

declare(strict_types=1);

namespace ElggMigrate\Tests\Rules\V3ToV4;

use ElggMigrate\Rules\V3ToV4\CallbackRenames;
use PHPUnit\Framework\TestCase;

final class CallbackRenamesTest extends TestCase
{
    private CallbackRenames $rule;

    protected function setUp(): void
    {
        $this->rule = new CallbackRenames();
    }

    public function testAnalyzeFlagsAllOldPrefixedCallbacks(): void
    {
        $dir = __DIR__ . '/../../fixtures/3x-to-4x/callback-renames/input';
        $analysis = $this->rule->analyze($dir);

        $this->assertTrue($analysis->applicable);
        // 4 calls with old prefixes: _elgg_ x2, _groups_ x1, _members_ x1
        $this->assertCount(4, $analysis->findings);
    }

    public function testAnalysisFindingDescriptionsContainCallbackName(): void
    {
        $dir = __DIR__ . '/../../fixtures/3x-to-4x/callback-renames/input';
        $analysis = $this->rule->analyze($dir);

        $callbacks = array_map(fn($f) => $f->description, $analysis->findings);

        $this->assertStringContainsString('_elgg_entity_menu_setup', $callbacks[0]);
        $this->assertStringContainsString('_elgg_filestore_move_icons', $callbacks[1]);
        $this->assertStringContainsString('_groups_owner_block_menu', $callbacks[2]);
        $this->assertStringContainsString('_members_user_hover_menu', $callbacks[3]);
    }

    public function testApplyEmitsWarningsAndNoFileChanges(): void
    {
        $dir = __DIR__ . '/../../fixtures/3x-to-4x/callback-renames/input';
        $result = $this->rule->apply($dir);

        $this->assertTrue($result->success);
        $this->assertEmpty($result->changes);
        $this->assertCount(4, $result->warnings);
    }

    public function testAnalyzeIgnoresClassBasedCallbacks(): void
    {
        $workDir = sys_get_temp_dir() . '/elgg-migrate-' . uniqid();
        mkdir($workDir, 0755, true);
        file_put_contents($workDir . '/start.php', <<<'PHP'
<?php
elgg_unregister_plugin_hook_handler('register', 'menu:entity', 'MyPlugin\Menus::setup');
PHP);

        try {
            $analysis = $this->rule->analyze($workDir);
            $this->assertFalse($analysis->applicable);
        } finally {
            $this->removeDir($workDir);
        }
    }

    public function testAnalyzeNotApplicableWhenNoUnregisterCalls(): void
    {
        $workDir = sys_get_temp_dir() . '/elgg-migrate-' . uniqid();
        mkdir($workDir, 0755, true);
        file_put_contents($workDir . '/start.php', "<?php\nelgg_register_plugin_hook_handler('foo', 'bar', '_elgg_foo');\n");

        try {
            $analysis = $this->rule->analyze($workDir);
            // Register calls should NOT be flagged — only unregister matters here
            $this->assertFalse($analysis->applicable);
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
