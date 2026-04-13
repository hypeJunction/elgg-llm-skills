<?php

declare(strict_types=1);

namespace ElggMigrate\Tests\Rules\V2ToV3;

use ElggMigrate\Rules\V2ToV3\RemovedMethods;
use PHPUnit\Framework\TestCase;

final class RemovedMethodsTest extends TestCase
{
    private RemovedMethods $rule;

    protected function setUp(): void
    {
        $this->rule = new RemovedMethods();
    }

    public function testId(): void
    {
        $this->assertSame('removed-methods', $this->rule->getId());
    }

    public function testCanAutomate(): void
    {
        $this->assertTrue($this->rule->canAutomate());
    }

    public function testAnalyzeFindsRemovedMethods(): void
    {
        $dir = __DIR__ . '/../../fixtures/2x-to-3x/removed-methods/input';
        $analysis = $this->rule->analyze($dir);

        $this->assertTrue($analysis->applicable);
        // 4 renames + 3 removed + 3 ambiguous = 10
        $this->assertGreaterThanOrEqual(10, count($analysis->findings));
    }

    public function testAnalyzeNotApplicableOnCleanCode(): void
    {
        $dir = sys_get_temp_dir() . '/elgg-migrate-clean-' . uniqid();
        mkdir($dir, 0755, true);
        file_put_contents($dir . '/clean.php', "<?php\n\n\$x = \$obj->doSomething();\n");

        try {
            $analysis = $this->rule->analyze($dir);
            $this->assertFalse($analysis->applicable);
        } finally {
            unlink($dir . '/clean.php');
            rmdir($dir);
        }
    }

    public function testApplyRenamesUnambiguousMethods(): void
    {
        $workDir = $this->makeWorkDir('removed-methods');

        try {
            $result = $this->rule->apply($workDir);
            $this->assertTrue($result->success);
            $this->assertNotEmpty($result->changes);

            $code = file_get_contents($workDir . '/code.php');

            // Renames applied
            $this->assertStringContainsString('getDisplayName', $code);
            $this->assertStringContainsString('getPriority', $code);
            $this->assertStringContainsString('setPriority', $code);
            $this->assertStringContainsString('getTimePosted', $code);

            // Old names gone
            $this->assertStringNotContainsString('getFriendlyName', $code);
            $this->assertStringNotContainsString('getWeight', $code);
            $this->assertStringNotContainsString('setWeight', $code);
            $this->assertStringNotContainsString('getPostedTime', $code);

            // Ambiguous methods should NOT be renamed
            $this->assertStringContainsString('->size()', $code);
            $this->assertStringContainsString('->get(', $code);
            $this->assertStringContainsString('->addToSite(', $code);
        } finally {
            $this->removeDir($workDir);
        }
    }

    public function testApplyGeneratesWarnings(): void
    {
        $workDir = $this->makeWorkDir('removed-methods');

        try {
            $result = $this->rule->apply($workDir);
            // Should have warnings for removed methods and ambiguous methods
            $this->assertNotEmpty($result->warnings);
        } finally {
            $this->removeDir($workDir);
        }
    }

    public function testApplyProducesValidPhp(): void
    {
        $workDir = $this->makeWorkDir('removed-methods');

        try {
            $this->rule->apply($workDir);
            exec("php -l {$workDir}/code.php 2>&1", $output, $exitCode);
            $this->assertSame(0, $exitCode, implode("\n", $output));
        } finally {
            $this->removeDir($workDir);
        }
    }

    public function testIdempotent(): void
    {
        $workDir = $this->makeWorkDir('removed-methods');

        try {
            $this->rule->apply($workDir);
            $first = file_get_contents($workDir . '/code.php');

            $this->rule->apply($workDir);
            $second = file_get_contents($workDir . '/code.php');

            $this->assertSame($first, $second);
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
