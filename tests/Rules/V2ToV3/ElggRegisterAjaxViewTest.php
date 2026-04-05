<?php

declare(strict_types=1);

namespace ElggMigrate\Tests\Rules\V2ToV3;

use ElggMigrate\Rules\V2ToV3\ElggRegisterAjaxView;
use PHPUnit\Framework\TestCase;

final class ElggRegisterAjaxViewTest extends TestCase
{
    private ElggRegisterAjaxView $rule;

    protected function setUp(): void
    {
        $this->rule = new ElggRegisterAjaxView();
    }

    public function testId(): void
    {
        $this->assertSame('elgg-register-ajax-view', $this->rule->getId());
    }

    public function testCanAutomate(): void
    {
        $this->assertTrue($this->rule->canAutomate());
    }

    public function testAnalyzeFindsAjaxViewRegistrations(): void
    {
        $dir = __DIR__ . '/../../fixtures/2x-to-3x/elgg-register-ajax-view/input';
        $analysis = $this->rule->analyze($dir);

        $this->assertTrue($analysis->applicable);
        $this->assertCount(2, $analysis->findings);
    }

    public function testAnalyzeNotApplicableOnCleanCode(): void
    {
        $dir = sys_get_temp_dir() . '/elgg-migrate-clean-' . uniqid();
        mkdir($dir, 0755, true);
        file_put_contents($dir . '/start.php', "<?php\n\nelgg_extend_view('page/elements/sidebar', 'myplugin/sidebar');\n");

        try {
            $analysis = $this->rule->analyze($dir);
            $this->assertFalse($analysis->applicable);
        } finally {
            unlink($dir . '/start.php');
            rmdir($dir);
        }
    }

    public function testApplyRemovesAjaxViewCalls(): void
    {
        $workDir = $this->makeWorkDir('elgg-register-ajax-view');

        try {
            $result = $this->rule->apply($workDir);
            $this->assertTrue($result->success);
            $this->assertNotEmpty($result->changes);

            $code = file_get_contents($workDir . '/start.php');

            // Ajax view calls removed
            $this->assertStringNotContainsString('elgg_register_ajax_view', $code);

            // Other calls preserved
            $this->assertStringContainsString('elgg_register_event_handler', $code);
            $this->assertStringContainsString('elgg_extend_view', $code);
        } finally {
            $this->removeDir($workDir);
        }
    }

    public function testApplyProducesValidPhp(): void
    {
        $workDir = $this->makeWorkDir('elgg-register-ajax-view');

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
        $workDir = $this->makeWorkDir('elgg-register-ajax-view');

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
