<?php

declare(strict_types=1);

namespace ElggMigrate\Tests\Rules\V2ToV3;

use ElggMigrate\Rules\V2ToV3\ConfigGlobalRemoval;
use PHPUnit\Framework\TestCase;

final class ConfigGlobalRemovalTest extends TestCase
{
    private ConfigGlobalRemoval $rule;

    protected function setUp(): void
    {
        $this->rule = new ConfigGlobalRemoval();
    }

    public function testAnalyzeFindsLegacyAccess(): void
    {
        $dir = __DIR__ . '/../../fixtures/2x-to-3x/config-global-removal/input';
        $analysis = $this->rule->analyze($dir);

        $this->assertTrue($analysis->applicable);
        // global $CONFIG + $CONFIG->dbprefix + $vars['url'] + $vars['user'] = 4
        $this->assertGreaterThanOrEqual(4, count($analysis->findings));
    }

    public function testApplyReplacesConfigAccess(): void
    {
        $workDir = $this->makeWorkDir('config-global-removal');

        try {
            $result = $this->rule->apply($workDir);

            $this->assertTrue($result->success);

            $code = file_get_contents($workDir . '/view.php');

            // global $CONFIG should be removed
            $this->assertStringNotContainsString('global $CONFIG', $code);
            // $CONFIG->dbprefix should become elgg_get_config('dbprefix')
            $this->assertStringContainsString("elgg_get_config('dbprefix')", $code);
            // $vars['url'] should become elgg_get_site_url()
            $this->assertStringContainsString('elgg_get_site_url()', $code);
            // $vars['user'] should become elgg_get_logged_in_user_entity()
            $this->assertStringContainsString('elgg_get_logged_in_user_entity()', $code);
            // $vars['title'] should NOT be touched (it's a valid view var)
            $this->assertStringContainsString("\$vars['title']", $code);
        } finally {
            $this->removeDir($workDir);
        }
    }

    public function testAnalyzeThenApplyLeavesNoFindings(): void
    {
        $workDir = $this->makeWorkDir('config-global-removal');

        try {
            $this->rule->apply($workDir);
            $reAnalysis = $this->rule->analyze($workDir);
            $this->assertFalse($reAnalysis->applicable);
        } finally {
            $this->removeDir($workDir);
        }
    }

    /**
     * @dataProvider guineaPigProvider
     */
    public function testAnalyzeRealPlugin(string $pluginDir, int $minFindings): void
    {
        $path = __DIR__ . '/../../../tmp/' . $pluginDir;
        if (!is_dir($path)) {
            $this->markTestSkipped("Guinea pig not cloned: tmp/{$pluginDir}");
        }

        $analysis = $this->rule->analyze($path);

        if ($minFindings > 0) {
            $this->assertTrue($analysis->applicable);
            $this->assertGreaterThanOrEqual($minFindings, count($analysis->findings));
        }
    }

    public static function guineaPigProvider(): array
    {
        return [
            'community_plugins' => ['community_plugins', 5], // multiple global $CONFIG in views
        ];
    }

    private function makeWorkDir(string $fixture): string
    {
        $src = __DIR__ . "/../../fixtures/2x-to-3x/{$fixture}/input";
        $dst = sys_get_temp_dir() . '/elgg-migrate-' . uniqid();
        mkdir($dst, 0755, true);

        foreach (new \DirectoryIterator($src) as $f) {
            if ($f->isDot()) continue;
            copy($f->getPathname(), $dst . '/' . $f->getFilename());
        }

        return $dst;
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
