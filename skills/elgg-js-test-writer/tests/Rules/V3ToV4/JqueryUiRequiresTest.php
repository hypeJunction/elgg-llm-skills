<?php

declare(strict_types=1);

namespace ElggMigrate\Tests\Rules\V3ToV4;

use ElggMigrate\Rules\V3ToV4\JqueryUiRequires;
use PHPUnit\Framework\TestCase;

final class JqueryUiRequiresTest extends TestCase
{
    private JqueryUiRequires $rule;

    protected function setUp(): void
    {
        $this->rule = new JqueryUiRequires();
    }

    public function testAnalyzeFindsJqueryUiMethods(): void
    {
        $dir = __DIR__ . '/../../fixtures/3x-to-4x/jquery-ui-requires/input';
        $analysis = $this->rule->analyze($dir);

        $this->assertTrue($analysis->applicable);
        // .sortable(, .draggable(, .datepicker( = 3
        $this->assertCount(3, $analysis->findings);
    }

    public function testApplyProducesWarningsButNoFileChanges(): void
    {
        $workDir = sys_get_temp_dir() . '/elgg-migrate-' . uniqid();
        mkdir($workDir, 0755, true);
        $jsDir = $workDir . '/views/default/myplugin';
        mkdir($jsDir, 0755, true);
        copy(
            __DIR__ . '/../../fixtures/3x-to-4x/jquery-ui-requires/input/views/default/myplugin/widget.js',
            $jsDir . '/widget.js',
        );

        try {
            $result = $this->rule->apply($workDir);

            $this->assertTrue($result->success);
            $this->assertEmpty($result->changes, 'Warn-only rule should not modify files');
            $this->assertCount(3, $result->warnings);
        } finally {
            $this->removeDir($workDir);
        }
    }

    public function testCleanJsFileIsNotFlagged(): void
    {
        $workDir = sys_get_temp_dir() . '/elgg-migrate-' . uniqid();
        mkdir($workDir, 0755, true);
        file_put_contents($workDir . '/clean.js', "define([], function() { var x = 1; });\n");

        try {
            $analysis = $this->rule->analyze($workDir);
            $this->assertFalse($analysis->applicable);
        } finally {
            $this->removeDir($workDir);
        }
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
