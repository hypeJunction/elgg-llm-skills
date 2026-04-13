<?php

declare(strict_types=1);

namespace ElggMigrate\Tests\Rules\V2ToV3;

use ElggMigrate\Rules\V2ToV3\PageHandlerToRoute;
use PHPUnit\Framework\TestCase;

final class PageHandlerToRouteTest extends TestCase
{
    private string $fixtureDir;
    private string $workDir;
    private PageHandlerToRoute $rule;

    protected function setUp(): void
    {
        $this->fixtureDir = __DIR__ . '/../../fixtures/2x-to-3x/page-handler-to-route/input';
        $this->workDir = sys_get_temp_dir() . '/elgg-migrate-test-' . uniqid();
        $this->rule = new PageHandlerToRoute();

        // Copy fixtures to a temp working directory so we can test apply() without mutating fixtures
        $this->copyDirectory($this->fixtureDir, $this->workDir);
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->workDir);
    }

    public function testGetId(): void
    {
        $this->assertSame('page-handler-to-route', $this->rule->getId());
    }

    public function testCanAutomate(): void
    {
        $this->assertTrue($this->rule->canAutomate());
    }

    public function testAnalyzeFindsPageHandlerCalls(): void
    {
        $analysis = $this->rule->analyze($this->workDir);

        $this->assertTrue($analysis->applicable);
        $this->assertSame('page-handler-to-route', $analysis->ruleId);

        // Should find 3 calls total: 1 in start.php, 2 in multiple.php
        $this->assertCount(3, $analysis->findings);

        // Check that the handler names are captured
        $descriptions = array_map(fn($f) => $f->description, $analysis->findings);
        $joined = implode(' ', $descriptions);
        $this->assertStringContainsString('myplugin', $joined);
        $this->assertStringContainsString('forum', $joined);
        $this->assertStringContainsString('forumtopic', $joined);
    }

    public function testAnalyzeReturnsNotApplicableForCleanCode(): void
    {
        // Create a temp dir with only the clean file
        $cleanDir = sys_get_temp_dir() . '/elgg-migrate-clean-' . uniqid();
        mkdir($cleanDir, 0755, true);
        copy($this->fixtureDir . '/other.php', $cleanDir . '/other.php');

        $analysis = $this->rule->analyze($cleanDir);

        $this->assertFalse($analysis->applicable);
        $this->assertEmpty($analysis->findings);

        $this->removeDirectory($cleanDir);
    }

    public function testApplyTransformsPageHandlers(): void
    {
        $result = $this->rule->apply($this->workDir);

        $this->assertTrue($result->success);
        $this->assertSame('page-handler-to-route', $result->ruleId);

        // Should have modified start.php and multiple.php (not other.php)
        $changedFiles = array_map(fn($c) => $c->file, $result->changes);
        $this->assertContains('start.php', $changedFiles);
        $this->assertContains('multiple.php', $changedFiles);
        $this->assertNotContains('other.php', $changedFiles);
    }

    public function testApplyReplacesWithRouteRegistration(): void
    {
        $this->rule->apply($this->workDir);

        // Check start.php was transformed
        $startCode = file_get_contents($this->workDir . '/start.php');
        $this->assertStringContainsString('elgg_register_route', $startCode);
        $this->assertStringNotContainsString('elgg_register_page_handler', $startCode);

        // Should preserve the route name 'myplugin'
        $this->assertStringContainsString("'myplugin'", $startCode);

        // Should include path with segments
        $this->assertStringContainsString('/myplugin/{segments}', $startCode);

        // Should include resource view reference
        $this->assertStringContainsString("'resource'", $startCode);

        // Should NOT touch other registrations (actions, hooks)
        $this->assertStringContainsString('elgg_register_action', $startCode);
        $this->assertStringContainsString('elgg_register_plugin_hook_handler', $startCode);
    }

    public function testApplyTransformsMultipleHandlersInOneFile(): void
    {
        $this->rule->apply($this->workDir);

        $multiCode = file_get_contents($this->workDir . '/multiple.php');
        $this->assertStringNotContainsString('elgg_register_page_handler', $multiCode);

        // Should have both routes
        $this->assertStringContainsString('/forum/{segments}', $multiCode);
        $this->assertStringContainsString('/forumtopic/{segments}', $multiCode);
    }

    public function testApplyDoesNotTouchCleanFiles(): void
    {
        $originalOther = file_get_contents($this->workDir . '/other.php');
        $this->rule->apply($this->workDir);
        $afterOther = file_get_contents($this->workDir . '/other.php');

        $this->assertSame($originalOther, $afterOther);
    }

    public function testApplyGeneratesWarningsAboutCallbackMigration(): void
    {
        $result = $this->rule->apply($this->workDir);

        // Should warn that callbacks need manual migration to resource views
        $this->assertNotEmpty($result->warnings);

        $warningText = implode(' ', $result->warnings);
        $this->assertStringContainsString('resource view', $warningText);
    }

    public function testAnalyzeThenApplyIsConsistent(): void
    {
        $analysis = $this->rule->analyze($this->workDir);
        $this->assertTrue($analysis->applicable);

        $result = $this->rule->apply($this->workDir);
        $this->assertTrue($result->success);

        // After apply, re-analyze should find nothing
        $reAnalysis = $this->rule->analyze($this->workDir);
        $this->assertFalse($reAnalysis->applicable);
        $this->assertEmpty($reAnalysis->findings);
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
