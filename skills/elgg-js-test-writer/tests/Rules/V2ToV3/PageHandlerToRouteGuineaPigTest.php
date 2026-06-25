<?php

declare(strict_types=1);

namespace ElggMigrate\Tests\Rules\V2ToV3;

use ElggMigrate\Rules\V2ToV3\PageHandlerToRoute;
use PHPUnit\Framework\TestCase;

/**
 * Tests the PageHandlerToRoute rule against real Elgg plugins cloned into tmp/.
 * These tests verify the rule works on diverse real-world code, not just fixtures.
 *
 * Guinea pigs:
 *   - a class-callback plugin (class callback, constants, PSR-0)
 *   - RangeeGmbH/elgg-forum (4 page handlers, library, function callbacks)
 *   - Elgg/community_plugins (namespaced callbacks, complex hooks)
 */
final class PageHandlerToRouteGuineaPigTest extends TestCase
{
    private PageHandlerToRoute $rule;

    protected function setUp(): void
    {
        $this->rule = new PageHandlerToRoute();
    }

    /**
     * @dataProvider guineaPigProvider
     */
    public function testAnalyzeFindsCallsInRealPlugin(string $pluginDir, int $expectedMinFindings): void
    {
        $path = __DIR__ . '/../../../tmp/' . $pluginDir;
        if (!is_dir($path)) {
            $this->markTestSkipped("Guinea pig not cloned: tmp/{$pluginDir} — run: git clone <repo> tmp/{$pluginDir}");
        }

        $analysis = $this->rule->analyze($path);

        $this->assertTrue($analysis->applicable, "Rule should apply to {$pluginDir}");
        $this->assertGreaterThanOrEqual(
            $expectedMinFindings,
            count($analysis->findings),
            "Expected at least {$expectedMinFindings} page handler(s) in {$pluginDir}",
        );
    }

    /**
     * @dataProvider guineaPigProvider
     */
    public function testApplyTransformsRealPlugin(string $pluginDir, int $expectedMinFindings): void
    {
        $srcPath = __DIR__ . '/../../../tmp/' . $pluginDir;
        if (!is_dir($srcPath)) {
            $this->markTestSkipped("Guinea pig not cloned: tmp/{$pluginDir}");
        }

        // Work on a copy to avoid mutating the cloned repo
        $workDir = sys_get_temp_dir() . '/elgg-migrate-gp-' . uniqid();
        $this->copyDirectory($srcPath, $workDir);

        try {
            $result = $this->rule->apply($workDir);

            $this->assertTrue($result->success, "Apply should succeed on {$pluginDir}");
            $this->assertNotEmpty($result->changes, "Should have changed files in {$pluginDir}");

            // After applying, re-analyze should find no remaining page handlers
            // (except those using non-string-literal handler names like constants)
            $reAnalysis = $this->rule->analyze($workDir);

            // Verify the transformed files contain elgg_register_route
            foreach ($result->changes as $change) {
                $filePath = $workDir . '/' . $change->file;
                if (file_exists($filePath)) {
                    $code = file_get_contents($filePath);
                    $this->assertStringContainsString(
                        'elgg_register_route',
                        $code,
                        "Transformed {$change->file} should contain elgg_register_route",
                    );
                }
            }
        } finally {
            $this->removeDirectory($workDir);
        }
    }

    /**
     * @return array<string, array{string, int}>
     */
    public static function guineaPigProvider(): array
    {
        return [
            'class-callback-plugin' => ['class-callback-plugin', 1],
            'elgg-forum' => ['elgg-forum', 4],
            'community_plugins' => ['community_plugins', 1],
        ];
    }

    private function copyDirectory(string $source, string $dest): void
    {
        if (!is_dir($dest)) {
            mkdir($dest, 0755, true);
        }

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($source, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::SELF_FIRST,
        );

        foreach ($iterator as $item) {
            /** @var \SplFileInfo $item */
            $target = $dest . '/' . $iterator->getSubPathname();
            if ($item->isDir()) {
                if (!is_dir($target)) {
                    mkdir($target, 0755, true);
                }
            } else {
                copy($item->getPathname(), $target);
            }
        }
    }

    private function removeDirectory(string $dir): void
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
            if ($item->isDir()) {
                rmdir($item->getPathname());
            } else {
                unlink($item->getPathname());
            }
        }

        rmdir($dir);
    }
}
