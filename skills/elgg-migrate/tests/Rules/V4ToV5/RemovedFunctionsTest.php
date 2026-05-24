<?php

declare(strict_types=1);

namespace ElggMigrate\Tests\Rules\V4ToV5;

use ElggMigrate\Rules\V4ToV5\RemovedFunctions;
use PHPUnit\Framework\TestCase;

final class RemovedFunctionsTest extends TestCase
{
    private RemovedFunctions $rule;

    protected function setUp(): void
    {
        $this->rule = new RemovedFunctions();
    }

    public function testId(): void
    {
        $this->assertSame('removed-functions-5x', $this->rule->getId());
    }

    public function testCanAutomate(): void
    {
        $this->assertTrue($this->rule->canAutomate());
    }

    public function testAnalyzeFindsAllCategories(): void
    {
        $dir = __DIR__ . '/../../fixtures/4x-to-5x/removed-functions-5x/input';
        $analysis = $this->rule->analyze($dir);

        $this->assertTrue($analysis->applicable);
        // 2 renames (current_page_url, get_default_access) + 4 warn-only
        // (forward, add_translation, elgg_register_entity_type, elgg_register_admin_menu_item)
        $this->assertCount(6, $analysis->findings);
    }

    /**
     * Regression: bd elgg-migrate-5h0u4 — add_translation() was deprecated-only
     * in 4.3 and dropped in 5.0. Must be flagged at the 4→5 boundary so that
     * plugins which slipped past the 3→4 sweep don't fatal at activation.
     */
    public function testFlagsAddTranslationAsWarn(): void
    {
        $this->assertArrayHasKey('add_translation', RemovedFunctions::MAP);
        $entry = RemovedFunctions::MAP['add_translation'];
        $this->assertSame('warn', $entry['action']);
        $this->assertStringContainsString("return [", $entry['note']);
    }

    public function testFlagsForwardAsWarn(): void
    {
        $this->assertArrayHasKey('forward', RemovedFunctions::MAP);
        $this->assertSame('warn', RemovedFunctions::MAP['forward']['action']);
    }

    public function testFlagsElggRegisterAdminMenuItemAsWarn(): void
    {
        $this->assertArrayHasKey('elgg_register_admin_menu_item', RemovedFunctions::MAP);
        $entry = RemovedFunctions::MAP['elgg_register_admin_menu_item'];
        $this->assertSame('warn', $entry['action']);
        $this->assertStringContainsString('menus.page', $entry['note']);
    }

    public function testFlagsElggRegisterEntityTypeAsWarn(): void
    {
        $this->assertArrayHasKey('elgg_register_entity_type', RemovedFunctions::MAP);
        $this->assertSame('warn', RemovedFunctions::MAP['elgg_register_entity_type']['action']);
    }

    public function testApplyRenamesAutoRenameEntriesOnly(): void
    {
        $workDir = $this->makeWorkDir();

        try {
            $result = $this->rule->apply($workDir);
            $this->assertTrue($result->success);
            $this->assertNotEmpty($result->changes);

            $code = file_get_contents($workDir . '/code.php');

            // Renames applied
            $this->assertStringContainsString('elgg_get_current_url', $code);
            $this->assertStringContainsString('elgg_get_default_access', $code);
            $this->assertDoesNotMatchRegularExpression('/\bcurrent_page_url\s*\(/', $code);
            $this->assertDoesNotMatchRegularExpression('/\bget_default_access\s*\(/', $code);

            // Warn-only calls left in place (refactor required, but a no-op is safer
            // than a destructive rewrite — the warning surfaces them to the developer).
            $this->assertMatchesRegularExpression('/\bforward\s*\(/', $code);
            $this->assertMatchesRegularExpression('/\badd_translation\s*\(/', $code);
            $this->assertMatchesRegularExpression('/\belgg_register_entity_type\s*\(/', $code);
            $this->assertMatchesRegularExpression('/\belgg_register_admin_menu_item\s*\(/', $code);
        } finally {
            $this->removeDir($workDir);
        }
    }

    public function testApplyProducesWarningsForRemovedHardEntries(): void
    {
        $workDir = $this->makeWorkDir();

        try {
            $result = $this->rule->apply($workDir);

            $joined = implode("\n", $result->warnings);
            $this->assertStringContainsString('add_translation', $joined);
            $this->assertStringContainsString('forward', $joined);
            $this->assertStringContainsString('elgg_register_entity_type', $joined);
            $this->assertStringContainsString('elgg_register_admin_menu_item', $joined);
        } finally {
            $this->removeDir($workDir);
        }
    }

    public function testApplyProducesValidPhp(): void
    {
        $workDir = $this->makeWorkDir();

        try {
            $this->rule->apply($workDir);
            exec("php -l {$workDir}/code.php 2>&1", $output, $exitCode);
            $this->assertSame(0, $exitCode, implode("\n", $output));
        } finally {
            $this->removeDir($workDir);
        }
    }

    public function testIdempotentForRenames(): void
    {
        $workDir = $this->makeWorkDir();

        try {
            $this->rule->apply($workDir);
            $codeAfterFirst = file_get_contents($workDir . '/code.php');

            $this->rule->apply($workDir);
            $codeAfterSecond = file_get_contents($workDir . '/code.php');

            $this->assertSame($codeAfterFirst, $codeAfterSecond);
        } finally {
            $this->removeDir($workDir);
        }
    }

    private function makeWorkDir(): string
    {
        $src = __DIR__ . '/../../fixtures/4x-to-5x/removed-functions-5x/input';
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
