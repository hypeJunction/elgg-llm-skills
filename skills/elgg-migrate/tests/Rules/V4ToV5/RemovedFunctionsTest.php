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

    public function testAnalyzeDetectsRemovedCalls(): void
    {
        $dir = $this->makeDir(<<<'PHP'
<?php
$url = current_page_url();
$access = get_default_access();
PHP);

        try {
            $analysis = $this->rule->analyze($dir);
            $this->assertTrue($analysis->applicable);

            $combined = implode("\n", array_map(fn($f) => $f->description, $analysis->findings));
            $this->assertStringContainsString('current_page_url', $combined);
            $this->assertStringContainsString('elgg_get_current_url', $combined);
            $this->assertStringContainsString('get_default_access', $combined);
            $this->assertStringContainsString('elgg_get_default_access', $combined);
        } finally {
            $this->removeDir($dir);
        }
    }

    public function testAnalyzeNotApplicableOnCleanCode(): void
    {
        $dir = $this->makeDir("<?php\n\$url = elgg_get_current_url();\n");

        try {
            $analysis = $this->rule->analyze($dir);
            $this->assertFalse($analysis->applicable);
        } finally {
            $this->removeDir($dir);
        }
    }

    public function testApplyRenamesRemovedFunctionCalls(): void
    {
        $dir = $this->makeDir(<<<'PHP'
<?php
$url = current_page_url();
$access = get_default_access();
PHP);

        try {
            $result = $this->rule->apply($dir);
            $this->assertTrue($result->success);
            $this->assertNotEmpty($result->changes);

            $code = file_get_contents($dir . '/target.php');
            $this->assertStringContainsString('elgg_get_current_url()', $code);
            $this->assertStringContainsString('elgg_get_default_access()', $code);
            // Old names must not survive as standalone (non-suffix) function calls.
            $this->assertDoesNotMatchRegularExpression('/(?<![\w])current_page_url\(/', $code);
            $this->assertDoesNotMatchRegularExpression('/(?<![\w])get_default_access\(/', $code);
        } finally {
            $this->removeDir($dir);
        }
    }

    public function testApplyPreservesArgumentsAndSurroundingCode(): void
    {
        $dir = $this->makeDir(<<<'PHP'
<?php
function foo() {
    return current_page_url();
}
PHP);

        try {
            $this->rule->apply($dir);
            $code = file_get_contents($dir . '/target.php');

            $this->assertStringContainsString('function foo()', $code);
            $this->assertStringContainsString('return elgg_get_current_url();', $code);
        } finally {
            $this->removeDir($dir);
        }
    }

    public function testApplyDoesNotRenameMethodCallsOrDefinitions(): void
    {
        // A method named current_page_url() must NOT be rewritten — only free function calls.
        $dir = $this->makeDir(<<<'PHP'
<?php
class Router {
    public function current_page_url() {
        return $this->current_page_url();
    }
}
PHP);

        try {
            $this->rule->apply($dir);
            $code = file_get_contents($dir . '/target.php');

            $this->assertStringContainsString('public function current_page_url()', $code);
            $this->assertStringContainsString('$this->current_page_url()', $code);
            $this->assertStringNotContainsString('elgg_get_current_url', $code);
        } finally {
            $this->removeDir($dir);
        }
    }

    public function testApplyOnCleanCodeMakesNoChanges(): void
    {
        $dir = $this->makeDir("<?php\necho 'nothing to do';\n");

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
        $dir = sys_get_temp_dir() . '/elgg-migrate-removedfn-' . uniqid();
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
