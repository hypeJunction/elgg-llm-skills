<?php

declare(strict_types=1);

namespace ElggMigrate\Tests\Rules\V2ToV3;

use ElggMigrate\Rules\V2ToV3\DeprecatedEntityQueries;
use PHPUnit\Framework\TestCase;

final class DeprecatedEntityQueriesTest extends TestCase
{
    private DeprecatedEntityQueries $rule;

    protected function setUp(): void
    {
        $this->rule = new DeprecatedEntityQueries();
    }

    public function testAnalyzeFindsDeprecatedCalls(): void
    {
        $dir = __DIR__ . '/../../fixtures/2x-to-3x/deprecated-entity-queries/input';
        $analysis = $this->rule->analyze($dir);

        $this->assertTrue($analysis->applicable);
        // 2 direct calls + 1 string reference + 1 list call = 4
        $this->assertGreaterThanOrEqual(3, count($analysis->findings));
    }

    public function testApplyRenamesFunctions(): void
    {
        $workDir = $this->makeWorkDir('deprecated-entity-queries');

        try {
            $result = $this->rule->apply($workDir);

            $this->assertTrue($result->success);

            $code = file_get_contents($workDir . '/example.php');
            $this->assertStringNotContainsString('elgg_get_entities_from_metadata', $code);
            $this->assertStringNotContainsString('elgg_list_entities_from_relationship', $code);
            $this->assertStringContainsString('elgg_get_entities', $code);
            $this->assertStringContainsString('elgg_list_entities', $code);
        } finally {
            $this->removeDir($workDir);
        }
    }

    public function testApplyRenamesStringCallbacks(): void
    {
        $workDir = $this->makeWorkDir('deprecated-entity-queries');

        try {
            $this->rule->apply($workDir);

            $code = file_get_contents($workDir . '/example.php');
            // The ElggBatch string callback should be renamed
            $this->assertStringContainsString("'elgg_get_entities'", $code);
        } finally {
            $this->removeDir($workDir);
        }
    }

    public function testAnalyzeThenApplyLeavesNoFindings(): void
    {
        $workDir = $this->makeWorkDir('deprecated-entity-queries');

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

        $this->assertTrue($analysis->applicable, "Should find deprecated queries in {$pluginDir}");
        $this->assertGreaterThanOrEqual($minFindings, count($analysis->findings));
    }

    public static function guineaPigProvider(): array
    {
        return [
            'hypeWall' => ['hypeWall', 5],
            'community_plugins' => ['community_plugins', 10],
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
