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
        // validate_email_address, validate_password, forward, elgg_set_plugin_setting, elgg_get_filter_tabs = 5
        $this->assertCount(5, $analysis->findings);
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
            $this->assertCount(5, $result->warnings);
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
