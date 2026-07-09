<?php

declare(strict_types=1);

namespace ElggMigrate\Tests\Rules\V3ToV4;

use ElggMigrate\Rules\V3ToV4\DebugSurfaceCleanup;
use PHPUnit\Framework\TestCase;

final class DebugSurfaceCleanupTest extends TestCase
{
    private DebugSurfaceCleanup $rule;

    protected function setUp(): void
    {
        $this->rule = new DebugSurfaceCleanup();
    }

    // -------------------------------------------------------------------------
    // Metadata
    // -------------------------------------------------------------------------

    public function testRuleId(): void
    {
        $this->assertSame('debug-surface-cleanup', $this->rule->getId());
    }

    public function testCanAutomate(): void
    {
        $this->assertTrue($this->rule->canAutomate());
    }

    // -------------------------------------------------------------------------
    // analyze() — detects residue in the fixture
    // -------------------------------------------------------------------------

    public function testAnalyzeFindsDebugResidue(): void
    {
        $dir      = __DIR__ . '/../../fixtures/3x-to-4x/debug-surface-cleanup/input';
        $analysis = $this->rule->analyze($dir);

        $this->assertTrue($analysis->applicable);
        $this->assertNotEmpty($analysis->findings);

        $descriptions = array_map(fn($f) => $f->description, $analysis->findings);

        // elgg_dump detected
        $this->assertTrue(
            array_any($descriptions, fn($d) => str_contains($d, 'elgg_dump')),
            'Expected a finding for elgg_dump()',
        );

        // commented-out var_dump detected
        $this->assertTrue(
            array_any($descriptions, fn($d) => str_contains($d, 'Commented-out debug call')),
            'Expected a finding for commented-out debug call',
        );

        // Logger:: constants detected
        $this->assertTrue(
            array_any($descriptions, fn($d) => str_contains($d, 'Logger::ERROR')),
            'Expected a finding for Logger::ERROR',
        );

        // echo $_REQUEST detected
        $this->assertTrue(
            array_any($descriptions, fn($d) => str_contains($d, 'echo/print of $_REQUEST or $_SESSION')),
            'Expected a finding for echo of $_REQUEST',
        );
    }

    public function testAnalyzeCleanPluginNotApplicable(): void
    {
        $workDir = $this->makeTempDir();
        file_put_contents($workDir . '/clean.php', "<?php\n\$x = 1;\n");

        try {
            $analysis = $this->rule->analyze($workDir);
            $this->assertFalse($analysis->applicable);
            $this->assertEmpty($analysis->findings);
        } finally {
            $this->removeDir($workDir);
        }
    }

    // -------------------------------------------------------------------------
    // apply() — elgg_dump standalone removal
    // -------------------------------------------------------------------------

    public function testApplyRemovesStandaloneElggDump(): void
    {
        $workDir = $this->makeTempDir();
        $input   = "<?php\n\$x = 1;\nelgg_dump(\$x);\nreturn \$x;\n";
        file_put_contents($workDir . '/plugin.php', $input);

        try {
            $result = $this->rule->apply($workDir);

            $this->assertTrue($result->success);
            $this->assertCount(1, $result->changes);

            $output = file_get_contents($workDir . '/plugin.php');
            $this->assertStringNotContainsString('elgg_dump', $output);
            $this->assertStringContainsString('$x = 1', $output);
            $this->assertStringContainsString('return $x', $output);
        } finally {
            $this->removeDir($workDir);
        }
    }

    // -------------------------------------------------------------------------
    // apply() — commented-out debug lines removal
    // -------------------------------------------------------------------------

    public function testApplyStripsCommentedDebugLines(): void
    {
        $workDir = $this->makeTempDir();
        $input   = "<?php\n\$x = 1;\n// var_dump(\$x);\nreturn \$x;\n";
        file_put_contents($workDir . '/plugin.php', $input);

        try {
            $result = $this->rule->apply($workDir);

            $this->assertTrue($result->success);
            $this->assertCount(1, $result->changes);

            $output = file_get_contents($workDir . '/plugin.php');
            $this->assertStringNotContainsString('var_dump', $output);
            $this->assertStringContainsString('$x = 1', $output);
        } finally {
            $this->removeDir($workDir);
        }
    }

    public function testApplyStripsHashCommentedDebugLines(): void
    {
        $workDir = $this->makeTempDir();
        $input   = "<?php\n\$x = 1;\n# error_log(\$x);\nreturn \$x;\n";
        file_put_contents($workDir . '/plugin.php', $input);

        try {
            $result = $this->rule->apply($workDir);

            $output = file_get_contents($workDir . '/plugin.php');
            $this->assertStringNotContainsString('error_log', $output);
        } finally {
            $this->removeDir($workDir);
        }
    }

    // -------------------------------------------------------------------------
    // apply() — Logger:: constant replacement
    // -------------------------------------------------------------------------

    public function testApplyReplacesLoggerConstants(): void
    {
        $workDir = $this->makeTempDir();
        $input   = "<?php\n\$a = Logger::ERROR;\n\$b = Logger::WARNING;\n\$c = Logger::INFO;\n\$d = Logger::NOTICE;\n\$e = Logger::DEBUG;\n";
        file_put_contents($workDir . '/plugin.php', $input);

        try {
            $result = $this->rule->apply($workDir);

            $this->assertTrue($result->success);
            $this->assertCount(1, $result->changes);

            $output = file_get_contents($workDir . '/plugin.php');
            $this->assertStringNotContainsString('Logger::', $output);
            $this->assertStringContainsString("'error'", $output);
            $this->assertStringContainsString("'warning'", $output);
            $this->assertStringContainsString("'info'", $output);
            $this->assertStringContainsString("'notice'", $output);
            $this->assertStringContainsString("'debug'", $output);
        } finally {
            $this->removeDir($workDir);
        }
    }

    // -------------------------------------------------------------------------
    // apply() — echo $_REQUEST is NOT auto-removed; adds warning
    // -------------------------------------------------------------------------

    public function testApplyWarnsOnEchoRequestButDoesNotRemove(): void
    {
        $workDir = $this->makeTempDir();
        $input   = "<?php\necho \$_REQUEST['foo'];\n";
        file_put_contents($workDir . '/plugin.php', $input);

        try {
            $result = $this->rule->apply($workDir);

            $this->assertTrue($result->success);
            // File should NOT be modified (no autofix for dangerous echo)
            $this->assertEmpty($result->changes);

            $output = file_get_contents($workDir . '/plugin.php');
            $this->assertStringContainsString('$_REQUEST', $output, 'echo $_REQUEST must not be auto-removed');

            // But a warning should be emitted
            $warningTexts = implode(' ', $result->warnings);
            $this->assertStringContainsString('_REQUEST', $warningTexts);
        } finally {
            $this->removeDir($workDir);
        }
    }

    public function testApplyWarnsOnEchoSessionButDoesNotRemove(): void
    {
        $workDir = $this->makeTempDir();
        $input   = "<?php\necho \$_SESSION['key'];\n";
        file_put_contents($workDir . '/plugin.php', $input);

        try {
            $result = $this->rule->apply($workDir);

            $output = file_get_contents($workDir . '/plugin.php');
            $this->assertStringContainsString('$_SESSION', $output, 'echo $_SESSION must not be auto-removed');

            $warningTexts = implode(' ', $result->warnings);
            $this->assertStringContainsString('_SESSION', $warningTexts);
        } finally {
            $this->removeDir($workDir);
        }
    }

    // -------------------------------------------------------------------------
    // apply() — clean file is untouched
    // -------------------------------------------------------------------------

    public function testCleanFileNotModified(): void
    {
        $workDir = $this->makeTempDir();
        $input   = "<?php\n\$x = 1;\nreturn \$x;\n";
        file_put_contents($workDir . '/clean.php', $input);

        try {
            $result = $this->rule->apply($workDir);

            $this->assertTrue($result->success);
            $this->assertEmpty($result->changes);
            $this->assertSame($input, file_get_contents($workDir . '/clean.php'));
        } finally {
            $this->removeDir($workDir);
        }
    }

    // -------------------------------------------------------------------------
    // apply() — vendor directory is skipped
    // -------------------------------------------------------------------------

    public function testApplySkipsVendorDirectory(): void
    {
        $workDir = $this->makeTempDir();
        mkdir($workDir . '/vendor', 0755, true);
        $vendorInput = "<?php\nelgg_dump('should be ignored');\n";
        file_put_contents($workDir . '/vendor/lib.php', $vendorInput);
        file_put_contents($workDir . '/plugin.php', "<?php\n\$x = 1;\n");

        try {
            $result = $this->rule->apply($workDir);

            $this->assertEmpty($result->changes);
            // vendor file must be untouched
            $this->assertSame($vendorInput, file_get_contents($workDir . '/vendor/lib.php'));
        } finally {
            $this->removeDir($workDir);
        }
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    private function makeTempDir(): string
    {
        $dir = sys_get_temp_dir() . '/elgg-migrate-debug-' . uniqid();
        mkdir($dir, 0755, true);
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
