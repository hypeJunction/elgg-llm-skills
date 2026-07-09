<?php

declare(strict_types=1);

namespace ElggMigrate\Tests\Rules\V3ToV4;

use ElggMigrate\Rules\V3ToV4\CssRegistration;
use PHPUnit\Framework\TestCase;

final class CssRegistrationTest extends TestCase
{
    private CssRegistration $rule;

    protected function setUp(): void
    {
        $this->rule = new CssRegistration();
    }

    public function testAnalyzeFindsCssJsFunctions(): void
    {
        $dir = __DIR__ . '/../../fixtures/3x-to-4x/css-registration/input';
        $analysis = $this->rule->analyze($dir);

        $this->assertTrue($analysis->applicable);
        // elgg_register_css, elgg_load_css, elgg_register_js, elgg_load_js = 4
        $this->assertCount(4, $analysis->findings);
    }

    public function testAnalyzeReturnsFalseForCleanPhp(): void
    {
        $workDir = sys_get_temp_dir() . '/elgg-migrate-' . uniqid();
        mkdir($workDir, 0755, true);
        file_put_contents($workDir . '/test.php', "<?php\nelgg_load_external_file('css', 'my-styles');\n");

        try {
            $analysis = $this->rule->analyze($workDir);
            $this->assertFalse($analysis->applicable);
        } finally {
            $this->removeDir($workDir);
        }
    }

    public function testApplyProducesWarningsButNoFileChanges(): void
    {
        $workDir = sys_get_temp_dir() . '/elgg-migrate-' . uniqid();
        mkdir($workDir, 0755, true);
        copy(__DIR__ . '/../../fixtures/3x-to-4x/css-registration/input/code.php', $workDir . '/code.php');

        try {
            $result = $this->rule->apply($workDir);

            $this->assertTrue($result->success);
            $this->assertEmpty($result->changes, 'Warn-only rule should not modify files');
            $this->assertCount(4, $result->warnings);
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
