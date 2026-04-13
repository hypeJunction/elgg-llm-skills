<?php

declare(strict_types=1);

namespace ElggMigrate\Tests\Rules\V3ToV4;

use ElggMigrate\Rules\V3ToV4\HookEventRenames;
use PHPUnit\Framework\TestCase;

final class HookEventRenamesTest extends TestCase
{
    private HookEventRenames $rule;

    protected function setUp(): void
    {
        $this->rule = new HookEventRenames();
    }

    public function testAnalyzeFindsDeprecatedHooksAndEvents(): void
    {
        $dir = __DIR__ . '/../../fixtures/3x-to-4x/hook-event-renames/input';
        $analysis = $this->rule->analyze($dir);

        $this->assertTrue($analysis->applicable);
        // profile:fields group, profile:fields user, usersettings plugin, created river, creating river = 5
        $this->assertCount(5, $analysis->findings);
    }

    public function testAnalyzeDoesNotFlagNonDeprecated(): void
    {
        $workDir = sys_get_temp_dir() . '/elgg-migrate-' . uniqid();
        mkdir($workDir, 0755, true);
        file_put_contents($workDir . '/test.php', "<?php\nelgg_register_event_handler('create', 'object', 'handler');\n");

        try {
            $analysis = $this->rule->analyze($workDir);
            $this->assertFalse($analysis->applicable);
        } finally {
            $this->removeDir($workDir);
        }
    }

    public function testApplyProducesWarnings(): void
    {
        $workDir = sys_get_temp_dir() . '/elgg-migrate-' . uniqid();
        mkdir($workDir, 0755, true);
        copy(__DIR__ . '/../../fixtures/3x-to-4x/hook-event-renames/input/start.php', $workDir . '/start.php');

        try {
            $result = $this->rule->apply($workDir);
            $this->assertTrue($result->success);
            $this->assertNotEmpty($result->warnings);
        } finally {
            $this->removeDir($workDir);
        }
    }

    /**
     * @dataProvider guineaPigProvider
     */
    public function testAnalyzeRealPlugin(string $pluginDir): void
    {
        $path = __DIR__ . '/../../../tmp/' . $pluginDir;
        if (!is_dir($path)) {
            $this->markTestSkipped("Not cloned: tmp/{$pluginDir}");
        }

        $analysis = $this->rule->analyze($path);
        $this->assertSame($this->rule->getId(), $analysis->ruleId);
    }

    public static function guineaPigProvider(): array
    {
        return [
            'Elgg3-hypeWall' => ['Elgg3-hypeWall'],
            'Elgg3-hypeInteractions' => ['Elgg3-hypeInteractions'],
            'Elgg3-hypeDropzone' => ['Elgg3-hypeDropzone'],
        ];
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
