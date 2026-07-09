<?php

declare(strict_types=1);

namespace ElggMigrate\Tests\Rules\V2ToV3;

use ElggMigrate\Rules\V2ToV3\Psr3Logging;
use PHPUnit\Framework\TestCase;

final class Psr3LoggingTest extends TestCase
{
    private Psr3Logging $rule;

    protected function setUp(): void
    {
        $this->rule = new Psr3Logging();
    }

    public function testId(): void
    {
        $this->assertSame('psr3-logging', $this->rule->getId());
    }

    public function testCanAutomate(): void
    {
        $this->assertTrue($this->rule->canAutomate());
    }

    public function testAnalyzeFindsLegacyLogging(): void
    {
        $dir = __DIR__ . '/../../fixtures/2x-to-3x/psr3-logging/input';
        $analysis = $this->rule->analyze($dir);

        $this->assertTrue($analysis->applicable);
        // 2 error_log + 5 elgg_log(string) + 4 elgg_log(Logger::*) + 2 var_dump/print_r residue = 13
        $this->assertGreaterThanOrEqual(13, count($analysis->findings));
    }

    public function testAnalyzeIgnoresPrintRCapture(): void
    {
        $analysis = $this->rule->analyze(__DIR__ . '/../../fixtures/2x-to-3x/psr3-logging/input');
        foreach ($analysis->findings as $f) {
            $this->assertStringNotContainsString("print_r(\$value, true)", $f->code);
        }
    }

    public function testAnalyzeNotApplicableOnCleanCode(): void
    {
        $dir = sys_get_temp_dir() . '/elgg-migrate-clean-' . uniqid();
        mkdir($dir, 0755, true);
        file_put_contents($dir . '/clean.php', "<?php\necho 'hi';\n");

        try {
            $analysis = $this->rule->analyze($dir);
            $this->assertFalse($analysis->applicable);
            $this->assertEmpty($analysis->findings);
        } finally {
            unlink($dir . '/clean.php');
            rmdir($dir);
        }
    }

    public function testApplyRewritesErrorLog(): void
    {
        $workDir = $this->makeWorkDir();

        try {
            $result = $this->rule->apply($workDir);
            $this->assertTrue($result->success);

            $code = file_get_contents($workDir . '/start.php');
            $stripped = $this->stripPhpComments($code);
            // error_log(...) should be gone from real code, replaced by elgg()->logger->error(...)
            $this->assertDoesNotMatchRegularExpression('/\berror_log\s*\(/', $stripped);
            $this->assertMatchesRegularExpression(
                "/elgg\\(\\)->logger->error\\('Plugin booted'\\)/",
                $code,
            );
        } finally {
            $this->removeDir($workDir);
        }
    }

    public function testApplyRewritesElggLogStringLevels(): void
    {
        $workDir = $this->makeWorkDir();

        try {
            $this->rule->apply($workDir);
            $code = file_get_contents($workDir . '/start.php');

            $this->assertStringContainsString("elgg()->logger->notice('something happened')", $code);
            $this->assertStringContainsString("elgg()->logger->warning('a warning')", $code);
            $this->assertStringContainsString("elgg()->logger->error('an error')", $code);
            $this->assertStringContainsString("elgg()->logger->debug('a debug message')", $code);
            // single-arg elgg_log defaults to notice
            $this->assertStringContainsString("elgg()->logger->notice('plain message')", $code);
        } finally {
            $this->removeDir($workDir);
        }
    }

    public function testApplyRewritesLoggerConstants(): void
    {
        $workDir = $this->makeWorkDir();

        try {
            $this->rule->apply($workDir);
            $code = file_get_contents($workDir . '/start.php');

            $this->assertStringContainsString("elgg()->logger->info('with constant info')", $code);
            $this->assertStringContainsString("elgg()->logger->warning('with constant warning')", $code);
            $this->assertStringContainsString("elgg()->logger->error('with constant error')", $code);
            $this->assertStringContainsString("elgg()->logger->debug('with constant debug')", $code);

            // No bare elgg_log() calls survive in real code (comments may still mention it)
            $stripped = $this->stripPhpComments($code);
            $this->assertDoesNotMatchRegularExpression('/\belgg_log\s*\(/', $stripped);
        } finally {
            $this->removeDir($workDir);
        }
    }

    public function testApplyWarnsOnDebugResidue(): void
    {
        $workDir = $this->makeWorkDir();

        try {
            $result = $this->rule->apply($workDir);
            $joined = implode("\n", $result->warnings);
            $this->assertStringContainsString('var_dump()', $joined);
            $this->assertStringContainsString('print_r()', $joined);

            // var_dump/print_r are not rewritten — they stay in the file
            $code = file_get_contents($workDir . '/start.php');
            $this->assertStringContainsString('var_dump($user)', $code);
            $this->assertStringContainsString('print_r($entity)', $code);
            // print_r capture-to-string is preserved
            $this->assertStringContainsString('print_r($value, true)', $code);
        } finally {
            $this->removeDir($workDir);
        }
    }

    public function testApplyProducesValidPhp(): void
    {
        $workDir = $this->makeWorkDir();

        try {
            $this->rule->apply($workDir);
            exec("php -l {$workDir}/start.php 2>&1", $output, $exitCode);
            $this->assertSame(0, $exitCode, implode("\n", $output));
        } finally {
            $this->removeDir($workDir);
        }
    }

    public function testIdempotent(): void
    {
        $workDir = $this->makeWorkDir();

        try {
            $this->rule->apply($workDir);
            $first = file_get_contents($workDir . '/start.php');

            $this->rule->apply($workDir);
            $second = file_get_contents($workDir . '/start.php');

            $this->assertSame($first, $second);
        } finally {
            $this->removeDir($workDir);
        }
    }

    private function stripPhpComments(string $code): string
    {
        $out = '';
        foreach (token_get_all($code) as $tok) {
            if (is_array($tok)) {
                if ($tok[0] === T_COMMENT || $tok[0] === T_DOC_COMMENT) {
                    continue;
                }
                $out .= $tok[1];
            } else {
                $out .= $tok;
            }
        }
        return $out;
    }

    private function makeWorkDir(): string
    {
        $src = __DIR__ . '/../../fixtures/2x-to-3x/psr3-logging/input';
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
            \RecursiveIteratorIterator::CHILD_FIRST,
        ) as $f) {
            $f->isDir() ? rmdir($f->getPathname()) : unlink($f->getPathname());
        }
        rmdir($dir);
    }
}
