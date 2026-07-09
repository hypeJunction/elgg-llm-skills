<?php

declare(strict_types=1);

namespace ElggMigrate\Tests\Rules\V3ToV4;

use ElggMigrate\Rules\V3ToV4\ElggCallIgnoreAccess;
use PHPUnit\Framework\TestCase;

final class ElggCallIgnoreAccessTest extends TestCase
{
    private ElggCallIgnoreAccess $rule;

    /** @var array<string> Temp dirs to clean up after each test */
    private array $tempDirs = [];

    protected function setUp(): void
    {
        $this->rule = new ElggCallIgnoreAccess();
        $this->tempDirs = [];
    }

    protected function tearDown(): void
    {
        foreach ($this->tempDirs as $dir) {
            $this->removeDir($dir);
        }
        $this->tempDirs = [];
    }

    // -------------------------------------------------------------------------
    // Identity / meta
    // -------------------------------------------------------------------------

    public function testGetId(): void
    {
        $this->assertSame('elgg-call-ignore-access', $this->rule->getId());
    }

    public function testCanAutomate(): void
    {
        $this->assertTrue($this->rule->canAutomate());
    }

    // -------------------------------------------------------------------------
    // analyze() — detection
    // -------------------------------------------------------------------------

    public function testAnalyzeFindsBasicPair(): void
    {
        $dir = $this->makeDir([
            'lib.php' => <<<'PHP'
                <?php
                function do_something() {
                    elgg_set_ignore_access(true);
                    $result = elgg_get_entities(['type' => 'object']);
                    elgg_set_ignore_access(false);
                }
                PHP,
        ]);

        $analysis = $this->rule->analyze($dir);

        $this->assertTrue($analysis->applicable);
        $this->assertCount(1, $analysis->findings);
        $this->assertStringContainsString('ELGG_IGNORE_ACCESS', $analysis->findings[0]->description);
        $this->assertSame(3, $analysis->findings[0]->line);
    }

    public function testAnalyzeFindsShowDisabledPair(): void
    {
        $dir = $this->makeDir([
            'lib.php' => <<<'PHP'
                <?php
                function get_all() {
                    elgg_show_disabled_entities(true);
                    $items = elgg_get_entities(['type' => 'object', 'disabled' => true]);
                    elgg_show_disabled_entities(false);
                    return $items;
                }
                PHP,
        ]);

        $analysis = $this->rule->analyze($dir);

        $this->assertTrue($analysis->applicable);
        $this->assertCount(1, $analysis->findings);
        $this->assertStringContainsString('ELGG_SHOW_DISABLED_ENTITIES', $analysis->findings[0]->description);
    }

    public function testAnalyzeFindsNoPairsWhenClean(): void
    {
        $dir = $this->makeDir([
            'lib.php' => <<<'PHP'
                <?php
                function get_all() {
                    return elgg_get_entities(['type' => 'object']);
                }
                PHP,
        ]);

        $analysis = $this->rule->analyze($dir);

        $this->assertFalse($analysis->applicable);
        $this->assertCount(0, $analysis->findings);
    }

    public function testAnalyzeFlagsPairInConditionalBranch(): void
    {
        $dir = $this->makeDir([
            'lib.php' => <<<'PHP'
                <?php
                function conditionally_ignore($flag) {
                    elgg_set_ignore_access(true);
                    if ($flag) {
                        elgg_set_ignore_access(false);
                    }
                }
                PHP,
        ]);

        $analysis = $this->rule->analyze($dir);

        $this->assertTrue($analysis->applicable);
        $this->assertCount(1, $analysis->findings);
        $this->assertStringContainsString('conditional branch', $analysis->findings[0]->description);
    }

    public function testAnalyzeFindsMultiplePairs(): void
    {
        $dir = $this->makeDir([
            'lib.php' => <<<'PHP'
                <?php
                function first() {
                    elgg_set_ignore_access(true);
                    $r = elgg_get_entities([]);
                    elgg_set_ignore_access(false);
                }
                function second() {
                    elgg_show_disabled_entities(true);
                    $r = elgg_get_entities([]);
                    elgg_show_disabled_entities(false);
                }
                PHP,
        ]);

        $analysis = $this->rule->analyze($dir);

        $this->assertTrue($analysis->applicable);
        $this->assertCount(2, $analysis->findings);
    }

    // -------------------------------------------------------------------------
    // apply() — basic transformation
    // -------------------------------------------------------------------------

    public function testApplyTransformsBasicPair(): void
    {
        $dir = $this->makeDir([
            'lib.php' => <<<'PHP'
                <?php
                function do_something() {
                    elgg_set_ignore_access(true);
                    $result = elgg_get_entities(['type' => 'object']);
                    elgg_set_ignore_access(false);
                    return $result;
                }
                PHP,
        ]);

        $result = $this->rule->apply($dir);

        $this->assertTrue($result->success);
        $this->assertCount(1, $result->changes);

        $output = file_get_contents($dir . '/lib.php');
        $this->assertStringContainsString('elgg_call', $output);
        $this->assertStringContainsString('ELGG_IGNORE_ACCESS', $output);
        $this->assertStringNotContainsString('elgg_set_ignore_access(true)', $output);
        $this->assertStringNotContainsString('elgg_set_ignore_access(false)', $output);
    }

    public function testApplyTransformsShowDisabledPair(): void
    {
        $dir = $this->makeDir([
            'lib.php' => <<<'PHP'
                <?php
                function get_all() {
                    elgg_show_disabled_entities(true);
                    $items = elgg_get_entities(['type' => 'object', 'disabled' => true]);
                    elgg_show_disabled_entities(false);
                }
                PHP,
        ]);

        $result = $this->rule->apply($dir);

        $this->assertTrue($result->success);
        $this->assertCount(1, $result->changes);

        $output = file_get_contents($dir . '/lib.php');
        $this->assertStringContainsString('elgg_call', $output);
        $this->assertStringContainsString('ELGG_SHOW_DISABLED_ENTITIES', $output);
        $this->assertStringNotContainsString('elgg_show_disabled_entities(true)', $output);
        $this->assertStringNotContainsString('elgg_show_disabled_entities(false)', $output);
    }

    public function testApplyInjectsUseClauseForOuterVariables(): void
    {
        $dir = $this->makeDir([
            'lib.php' => <<<'PHP'
                <?php
                function search(string $query, int $limit) {
                    $options = ['type' => 'object', 'metadata_value' => $query, 'limit' => $limit];
                    elgg_set_ignore_access(true);
                    $result = elgg_get_entities($options);
                    elgg_set_ignore_access(false);
                    return $result;
                }
                PHP,
        ]);

        $result = $this->rule->apply($dir);

        $this->assertTrue($result->success);
        $this->assertCount(1, $result->changes);

        $output = file_get_contents($dir . '/lib.php');
        // $options is defined before the true-call and used inside — must appear in use()
        $this->assertStringContainsString('use', $output);
        $this->assertStringContainsString('$options', $output);
    }

    public function testApplyDoesNotTransformPairInConditionalBranch(): void
    {
        $original = <<<'PHP'
            <?php
            function conditionally_ignore($flag) {
                elgg_set_ignore_access(true);
                if ($flag) {
                    elgg_set_ignore_access(false);
                }
            }
            PHP;

        $dir = $this->makeDir(['lib.php' => $original]);

        $result = $this->rule->apply($dir);

        $this->assertTrue($result->success);
        // No file changes — pair was skipped
        $this->assertCount(0, $result->changes);
        // But a warning was emitted
        $this->assertNotEmpty($result->warnings);
        $this->assertStringContainsString('conditional branch', $result->warnings[0]);

        // File content must be unchanged
        $output = file_get_contents($dir . '/lib.php');
        $this->assertStringContainsString('elgg_set_ignore_access(true)', $output);
        $this->assertStringContainsString('elgg_set_ignore_access(false)', $output);
    }

    public function testApplyHandlesEmptyInnerBlock(): void
    {
        $dir = $this->makeDir([
            'lib.php' => <<<'PHP'
                <?php
                function empty_block() {
                    elgg_set_ignore_access(true);
                    elgg_set_ignore_access(false);
                }
                PHP,
        ]);

        $result = $this->rule->apply($dir);

        $this->assertTrue($result->success);
        $this->assertCount(1, $result->changes);

        $output = file_get_contents($dir . '/lib.php');
        $this->assertStringContainsString('elgg_call', $output);
        $this->assertStringContainsString('ELGG_IGNORE_ACCESS', $output);
    }

    public function testApplyPreservesUnaffectedFiles(): void
    {
        $clean = "<?php\nfunction noop() { return 1; }\n";
        $dir = $this->makeDir([
            'clean.php' => $clean,
            'dirty.php' => <<<'PHP'
                <?php
                function needs_work() {
                    elgg_set_ignore_access(true);
                    $r = elgg_get_entities([]);
                    elgg_set_ignore_access(false);
                }
                PHP,
        ]);

        $result = $this->rule->apply($dir);

        $this->assertTrue($result->success);
        $this->assertCount(1, $result->changes);
        $this->assertSame('dirty.php', $result->changes[0]->file);

        // clean.php must be byte-for-byte identical
        $this->assertSame($clean, file_get_contents($dir . '/clean.php'));
    }

    public function testApplyDoesNothingWhenNoPairsExist(): void
    {
        $original = "<?php\nfunction noop() { return 1; }\n";
        $dir = $this->makeDir(['lib.php' => $original]);

        $result = $this->rule->apply($dir);

        $this->assertTrue($result->success);
        $this->assertCount(0, $result->changes);
        $this->assertSame($original, file_get_contents($dir . '/lib.php'));
    }

    public function testApplyHandlesPairWithFunctionParameters(): void
    {
        $dir = $this->makeDir([
            'lib.php' => <<<'PHP'
                <?php
                function fetch(int $owner_guid, string $type) {
                    elgg_set_ignore_access(true);
                    $entities = elgg_get_entities(['owner_guid' => $owner_guid, 'type' => $type]);
                    elgg_set_ignore_access(false);
                    return $entities;
                }
                PHP,
        ]);

        $result = $this->rule->apply($dir);

        $this->assertTrue($result->success);
        $this->assertCount(1, $result->changes);

        $output = file_get_contents($dir . '/lib.php');
        $this->assertStringContainsString('elgg_call', $output);
        // Parameters used in the closure body should be captured via use()
        $this->assertStringContainsString('use', $output);
    }

    // -------------------------------------------------------------------------
    // Edge cases
    // -------------------------------------------------------------------------

    public function testAnalyzeIgnoresVendorDirectory(): void
    {
        $dir = $this->makeDir([]);
        mkdir($dir . '/vendor/some-package', 0755, true);
        file_put_contents($dir . '/vendor/some-package/lib.php', <<<'PHP'
            <?php
            function vendor_fn() {
                elgg_set_ignore_access(true);
                $r = elgg_get_entities([]);
                elgg_set_ignore_access(false);
            }
            PHP);

        $analysis = $this->rule->analyze($dir);

        $this->assertFalse($analysis->applicable);
    }

    public function testApplyIgnoresVendorDirectory(): void
    {
        $dir = $this->makeDir([]);
        $vendorFile = $dir . '/vendor/some-package/lib.php';
        mkdir(dirname($vendorFile), 0755, true);
        $original = <<<'PHP'
            <?php
            function vendor_fn() {
                elgg_set_ignore_access(true);
                $r = elgg_get_entities([]);
                elgg_set_ignore_access(false);
            }
            PHP;
        file_put_contents($vendorFile, $original);

        $result = $this->rule->apply($dir);

        $this->assertTrue($result->success);
        $this->assertCount(0, $result->changes);
        $this->assertSame($original, file_get_contents($vendorFile));
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    /**
     * Create a temporary directory with the given files, return the dir path.
     *
     * @param array<string, string> $files filename => content
     */
    private function makeDir(array $files): string
    {
        $dir = sys_get_temp_dir() . '/elgg-migrate-' . uniqid();
        mkdir($dir, 0755, true);

        foreach ($files as $name => $content) {
            $path = $dir . '/' . $name;
            $subDir = dirname($path);
            if (!is_dir($subDir)) {
                mkdir($subDir, 0755, true);
            }
            file_put_contents($path, $content);
        }

        $this->tempDirs[] = $dir;

        return $dir;
    }

    private function removeDir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        foreach (new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        ) as $f) {
            $f->isDir() ? rmdir($f->getPathname()) : unlink($f->getPathname());
        }
        rmdir($dir);
    }
}
