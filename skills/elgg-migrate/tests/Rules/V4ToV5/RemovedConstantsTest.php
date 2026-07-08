<?php

declare(strict_types=1);

namespace ElggMigrate\Tests\Rules\V4ToV5;

use ElggMigrate\Rules\V4ToV5\RemovedConstants;
use PHPUnit\Framework\TestCase;

final class RemovedConstantsTest extends TestCase
{
    private RemovedConstants $rule;

    protected function setUp(): void
    {
        $this->rule = new RemovedConstants();
    }

    public function testId(): void
    {
        $this->assertSame('removed-constants-5x', $this->rule->getId());
    }

    public function testCanAutomate(): void
    {
        $this->assertTrue($this->rule->canAutomate());
    }

    public function testAnalyzeDetectsRefererConstant(): void
    {
        $dir = $this->makeDir("<?php\n\$dest = elgg_redirect_response(REFERER);\n");

        try {
            $analysis = $this->rule->analyze($dir);
            $this->assertTrue($analysis->applicable);

            $combined = implode("\n", array_map(fn($f) => $f->description, $analysis->findings));
            $this->assertStringContainsString('REFERER', $combined);
            $this->assertStringContainsString('REFERRER', $combined);
        } finally {
            $this->removeDir($dir);
        }
    }

    public function testAnalyzeNotApplicableOnCleanCode(): void
    {
        $dir = $this->makeDir("<?php\n\$dest = elgg_redirect_response(REFERRER);\n");

        try {
            $analysis = $this->rule->analyze($dir);
            $this->assertFalse($analysis->applicable);
        } finally {
            $this->removeDir($dir);
        }
    }

    public function testApplyRenamesConstant(): void
    {
        $dir = $this->makeDir("<?php\nforward(REFERER);\n");

        try {
            $result = $this->rule->apply($dir);
            $this->assertTrue($result->success);
            $this->assertNotEmpty($result->changes);

            $code = file_get_contents($dir . '/target.php');
            $this->assertStringContainsString('forward(REFERRER)', $code);
            $this->assertStringNotContainsString('REFERER)', $code);
        } finally {
            $this->removeDir($dir);
        }
    }

    public function testApplyDoesNotTouchSubstringsOrStrings(): void
    {
        // REFERER inside a longer identifier or a string literal must be left alone
        // (the word-boundary guard excludes quote/word neighbours).
        $dir = $this->makeDir(<<<'PHP'
<?php
$httpReferer = $server['HTTP_REFERER'];
function getRefererHeader() {}
PHP);

        try {
            $result = $this->rule->apply($dir);
            $code = file_get_contents($dir . '/target.php');

            $this->assertStringContainsString("HTTP_REFERER", $code);
            $this->assertStringContainsString('getRefererHeader', $code);
            $this->assertStringNotContainsString('REFERRER', $code);
            $this->assertEmpty($result->changes);
        } finally {
            $this->removeDir($dir);
        }
    }

    public function testApplyOnCleanCodeMakesNoChanges(): void
    {
        $dir = $this->makeDir("<?php\necho 'ok';\n");

        try {
            $result = $this->rule->apply($dir);
            $this->assertTrue($result->success);
            $this->assertEmpty($result->changes);
        } finally {
            $this->removeDir($dir);
        }
    }

    private function makeDir(string $code): string
    {
        $dir = sys_get_temp_dir() . '/elgg-migrate-removedconst-' . uniqid();
        mkdir($dir, 0755, true);
        file_put_contents($dir . '/target.php', $code);

        return $dir;
    }

    private function removeDir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        $iter = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($iter as $f) {
            $f->isDir() ? rmdir($f->getPathname()) : unlink($f->getPathname());
        }
        rmdir($dir);
    }
}
