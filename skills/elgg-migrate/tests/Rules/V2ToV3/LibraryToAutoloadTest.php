<?php

declare(strict_types=1);

namespace ElggMigrate\Tests\Rules\V2ToV3;

use ElggMigrate\Rules\V2ToV3\LibraryToAutoload;
use PHPUnit\Framework\TestCase;

final class LibraryToAutoloadTest extends TestCase
{
    private LibraryToAutoload $rule;

    protected function setUp(): void
    {
        $this->rule = new LibraryToAutoload();
    }

    public function testAnalyzeFindsLibraryCalls(): void
    {
        $dir = __DIR__ . '/../../fixtures/2x-to-3x/library-to-autoload/input';
        $analysis = $this->rule->analyze($dir);

        $this->assertTrue($analysis->applicable);
        $this->assertCount(2, $analysis->findings); // register + load
    }

    public function testApplyRemovesRegisterAndReplacesLoad(): void
    {
        $workDir = $this->makeWorkDir('library-to-autoload');

        try {
            $result = $this->rule->apply($workDir);

            $this->assertTrue($result->success);
            $this->assertNotEmpty($result->changes);

            $code = file_get_contents($workDir . '/start.php');
            $this->assertStringNotContainsString('elgg_register_library', $code);
            $this->assertStringNotContainsString('elgg_load_library', $code);
            $this->assertStringContainsString('require_once', $code);

            // Should still have unrelated registrations
            $this->assertStringContainsString('elgg_register_action', $code);
        } finally {
            $this->removeDir($workDir);
        }
    }

    public function testAnalyzeThenApplyLeavesNoFindings(): void
    {
        $workDir = $this->makeWorkDir('library-to-autoload');

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
        } else {
            $this->assertFalse($analysis->applicable);
        }
    }

    public static function guineaPigProvider(): array
    {
        return [
            'elgg-forum' => ['elgg-forum', 2],           // register + load
            'community_plugins' => ['community_plugins', 2], // register + load
            'hypeWall' => ['hypeWall', 0],                // no library calls
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
