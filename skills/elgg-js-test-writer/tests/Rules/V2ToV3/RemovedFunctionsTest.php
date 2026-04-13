<?php

declare(strict_types=1);

namespace ElggMigrate\Tests\Rules\V2ToV3;

use ElggMigrate\Rules\V2ToV3\RemovedFunctions;
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
        $this->assertSame('removed-functions', $this->rule->getId());
    }

    public function testCanAutomate(): void
    {
        $this->assertTrue($this->rule->canAutomate());
    }

    public function testAnalyzeFindsRemovedFunctions(): void
    {
        $dir = __DIR__ . '/../../fixtures/2x-to-3x/removed-functions/input';
        $analysis = $this->rule->analyze($dir);

        $this->assertTrue($analysis->applicable);
        // rename: datalist_get, datalist_set, get_subtype_class, elgg_group_gatekeeper, get_entity_dates (5)
        // remove: create_metadata_from_array, metadata_array_to_values, detect_extender_valuetype,
        //         elgg_get_metastring_id, is_memcache_available (5)
        // warn: can_write_to_container, run_function_once, system_messages (3)
        // expression context: is_memcache_available, _elgg_get_memcache (2)
        // search: search_highlight_words (1)
        $this->assertGreaterThanOrEqual(15, count($analysis->findings));
    }

    public function testAnalyzeNotApplicableOnCleanCode(): void
    {
        $dir = sys_get_temp_dir() . '/elgg-migrate-clean-' . uniqid();
        mkdir($dir, 0755, true);
        file_put_contents($dir . '/clean.php', "<?php\n\necho 'hello';\n");

        try {
            $analysis = $this->rule->analyze($dir);
            $this->assertFalse($analysis->applicable);
            $this->assertEmpty($analysis->findings);
        } finally {
            unlink($dir . '/clean.php');
            rmdir($dir);
        }
    }

    public function testApplyRenamesFunctions(): void
    {
        $workDir = $this->makeWorkDir('removed-functions');

        try {
            $result = $this->rule->apply($workDir);
            $this->assertTrue($result->success);
            $this->assertNotEmpty($result->changes);

            $code = file_get_contents($workDir . '/start.php');

            // Renames should be applied
            $this->assertStringContainsString('elgg_get_config', $code);
            $this->assertStringContainsString('elgg_save_config', $code);
            $this->assertStringContainsString('elgg_get_entity_class', $code);
            $this->assertStringContainsString('elgg_entity_gatekeeper', $code);
            $this->assertStringContainsString('elgg_get_entity_dates', $code);

            // Old function calls should not appear (check as calls, not substrings — comments may remain)
            $this->assertDoesNotMatchRegularExpression('/\bdatalist_get\s*\(/', $code);
            $this->assertDoesNotMatchRegularExpression('/\bdatalist_set\s*\(/', $code);
            $this->assertDoesNotMatchRegularExpression('/\bget_subtype_class\s*\(/', $code);

            // Removed standalone statements should be gone
            $this->assertDoesNotMatchRegularExpression('/\bcreate_metadata_from_array\s*\(/', $code);
            $this->assertDoesNotMatchRegularExpression('/\bmetadata_array_to_values\s*\(/', $code);
            $this->assertDoesNotMatchRegularExpression('/\bdetect_extender_valuetype\s*\(/', $code);

            // Warn-only calls should still be present
            $this->assertStringContainsString('can_write_to_container', $code);
            $this->assertStringContainsString('run_function_once', $code);
            $this->assertStringContainsString('system_messages', $code);

            // Removed standalone search call should be gone
            $this->assertStringNotContainsString('search_highlight_words', $code);
        } finally {
            $this->removeDir($workDir);
        }
    }

    public function testApplyProducesValidPhp(): void
    {
        $workDir = $this->makeWorkDir('removed-functions');

        try {
            $this->rule->apply($workDir);
            exec("php -l {$workDir}/start.php 2>&1", $output, $exitCode);
            $this->assertSame(0, $exitCode, implode("\n", $output));
        } finally {
            $this->removeDir($workDir);
        }
    }

    public function testApplyGeneratesWarnings(): void
    {
        $workDir = $this->makeWorkDir('removed-functions');

        try {
            $result = $this->rule->apply($workDir);
            $this->assertNotEmpty($result->warnings);
        } finally {
            $this->removeDir($workDir);
        }
    }

    public function testIdempotentForRenames(): void
    {
        $workDir = $this->makeWorkDir('removed-functions');

        try {
            $this->rule->apply($workDir);
            $codeAfterFirst = file_get_contents($workDir . '/start.php');

            $this->rule->apply($workDir);
            $codeAfterSecond = file_get_contents($workDir . '/start.php');

            $this->assertSame($codeAfterFirst, $codeAfterSecond);
        } finally {
            $this->removeDir($workDir);
        }
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
