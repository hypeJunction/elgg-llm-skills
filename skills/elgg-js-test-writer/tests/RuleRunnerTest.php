<?php

declare(strict_types=1);

namespace ElggMigrate\Tests;

use ElggMigrate\RuleRunner;
use PHPUnit\Framework\TestCase;

final class RuleRunnerTest extends TestCase
{
    private RuleRunner $runner;
    private string $manifestPath;

    protected function setUp(): void
    {
        $this->runner = new RuleRunner();
        $this->manifestPath = __DIR__ . '/../rules/2x-to-3x/manifest.json';
    }

    public function testLoadManifestReadsAndSortsByPriority(): void
    {
        $manifest = $this->runner->loadManifest($this->manifestPath);

        $this->assertSame('2.x', $manifest['from']);
        $this->assertSame('3.x', $manifest['to']);
        $this->assertNotEmpty($manifest['rules']);

        // Verify sorted by priority
        $priorities = array_column($manifest['rules'], 'priority');
        $sorted = $priorities;
        sort($sorted);
        $this->assertSame($sorted, $priorities);
    }

    public function testLoadManifestThrowsOnMissingFile(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->runner->loadManifest('/nonexistent/manifest.json');
    }

    public function testGetLlmInstructionsReturnsNonAutomatedRules(): void
    {
        $instructions = $this->runner->getLlmInstructions($this->manifestPath);

        $this->assertNotEmpty($instructions);

        // All returned items should have instructions
        foreach ($instructions as $item) {
            $this->assertArrayHasKey('id', $item);
            $this->assertArrayHasKey('name', $item);
            $this->assertArrayHasKey('instructions', $item);
            $this->assertNotEmpty($item['instructions']);
        }

        // The automated rule should NOT be in the LLM instructions
        $ids = array_column($instructions, 'id');
        $this->assertNotContains('001-page-handler-to-route', $ids);

        // Non-automated rules should be present
        $this->assertContains('002-entity-constructor', $ids);
    }

    public function testAnalyzeAllRunsAutomatedRulesOnly(): void
    {
        $fixtureDir = __DIR__ . '/fixtures/2x-to-3x/page-handler-to-route/input';
        $analyses = $this->runner->analyzeAll($this->manifestPath, $fixtureDir);

        // Should have results from all automated rules
        $this->assertGreaterThanOrEqual(1, count($analyses));
        // First rule should be UpdateManifestVersion (priority 1)
        $this->assertSame('update-manifest-version', $analyses[0]->ruleId);
    }

    public function testApplyAllTransformsFiles(): void
    {
        // Copy fixtures to temp dir
        $fixtureDir = __DIR__ . '/fixtures/2x-to-3x/page-handler-to-route/input';
        $workDir = sys_get_temp_dir() . '/elgg-migrate-runner-' . uniqid();
        $this->copyDirectory($fixtureDir, $workDir);

        try {
            $results = $this->runner->applyAll($this->manifestPath, $workDir);

            $this->assertGreaterThanOrEqual(1, count($results));
            // All results should succeed
            foreach ($results as $result) {
                $this->assertTrue($result->success);
            }

            // Verify transformation happened
            $startCode = file_get_contents($workDir . '/start.php');
            $this->assertStringContainsString('elgg_register_route', $startCode);
            $this->assertStringNotContainsString('elgg_register_page_handler', $startCode);
        } finally {
            $this->removeDirectory($workDir);
        }
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
                mkdir($target, 0755, true);
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
