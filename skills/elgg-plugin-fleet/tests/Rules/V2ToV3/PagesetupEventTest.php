<?php

declare(strict_types=1);

namespace ElggMigrate\Tests\Rules\V2ToV3;

use ElggMigrate\Rules\V2ToV3\PagesetupEvent;
use PHPUnit\Framework\TestCase;

final class PagesetupEventTest extends TestCase
{
    private PagesetupEvent $rule;

    protected function setUp(): void
    {
        $this->rule = new PagesetupEvent();
    }

    public function testId(): void
    {
        $this->assertSame('pagesetup-event', $this->rule->getId());
    }

    public function testCanAutomate(): void
    {
        $this->assertTrue($this->rule->canAutomate());
    }

    public function testAnalyzeFindsPagesetupRegistrations(): void
    {
        $dir = __DIR__ . '/../../fixtures/2x-to-3x/pagesetup-event/input';
        $analysis = $this->rule->analyze($dir);

        $this->assertTrue($analysis->applicable);
        $this->assertCount(2, $analysis->findings);
    }

    public function testAnalyzeIgnoresNonPagesetupEvents(): void
    {
        $dir = sys_get_temp_dir() . '/elgg-migrate-clean-' . uniqid();
        mkdir($dir, 0755, true);
        file_put_contents($dir . '/start.php', "<?php\n\nelgg_register_event_handler('init', 'system', 'my_init');\n");

        try {
            $analysis = $this->rule->analyze($dir);
            $this->assertFalse($analysis->applicable);
        } finally {
            unlink($dir . '/start.php');
            rmdir($dir);
        }
    }

    public function testApplyRemovesPagesetupRegistrations(): void
    {
        $workDir = $this->makeWorkDir('pagesetup-event');

        try {
            $result = $this->rule->apply($workDir);
            $this->assertTrue($result->success);
            $this->assertNotEmpty($result->changes);

            $code = file_get_contents($workDir . '/start.php');

            // Pagesetup registrations removed
            $this->assertStringNotContainsString("'pagesetup'", $code);
            $this->assertStringNotContainsString('myplugin_pagesetup', $code);
            $this->assertStringNotContainsString('myplugin_sidebar_setup', $code);

            // Non-pagesetup registrations preserved
            $this->assertStringContainsString("'init'", $code);
            $this->assertStringContainsString('myplugin_init', $code);
            $this->assertStringContainsString("'create'", $code);
            $this->assertStringContainsString('myplugin_create_handler', $code);
        } finally {
            $this->removeDir($workDir);
        }
    }

    public function testApplyGeneratesWarnings(): void
    {
        $workDir = $this->makeWorkDir('pagesetup-event');

        try {
            $result = $this->rule->apply($workDir);
            $this->assertNotEmpty($result->warnings);
        } finally {
            $this->removeDir($workDir);
        }
    }

    public function testApplyProducesValidPhp(): void
    {
        $workDir = $this->makeWorkDir('pagesetup-event');

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
        $workDir = $this->makeWorkDir('pagesetup-event');

        try {
            $this->rule->apply($workDir);
            $reAnalysis = $this->rule->analyze($workDir);
            $this->assertFalse($reAnalysis->applicable);
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
