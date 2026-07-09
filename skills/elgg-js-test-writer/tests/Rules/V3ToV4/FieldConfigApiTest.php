<?php

declare(strict_types=1);

namespace ElggMigrate\Tests\Rules\V3ToV4;

use ElggMigrate\Rules\V3ToV4\FieldConfigApi;
use PHPUnit\Framework\TestCase;

final class FieldConfigApiTest extends TestCase
{
    private FieldConfigApi $rule;

    protected function setUp(): void
    {
        $this->rule = new FieldConfigApi();
    }

    public function testAnalyzeFindsAllThreeConfigKeys(): void
    {
        $dir = __DIR__ . '/../../fixtures/3x-to-4x/field-config-api/input';
        $analysis = $this->rule->analyze($dir);

        $this->assertTrue($analysis->applicable);
        $this->assertCount(3, $analysis->findings);

        $descriptions = array_map(fn($f) => $f->description, $analysis->findings);
        $this->assertStringContainsString("elgg_get_config('pages')", $descriptions[0]);
        $this->assertStringContainsString("elgg()->fields->get('object', 'page')", $descriptions[0]);
        $this->assertStringContainsString("elgg_get_config('group')", $descriptions[1]);
        $this->assertStringContainsString("elgg()->fields->get('group', 'group')", $descriptions[1]);
        $this->assertStringContainsString("elgg_get_config('profile_fields')", $descriptions[2]);
        $this->assertStringContainsString("elgg()->fields->get('user', 'user')", $descriptions[2]);
    }

    public function testApplyReplacesAllThreeKeys(): void
    {
        $workDir = $this->makeWorkDir();

        try {
            $result = $this->rule->apply($workDir);

            $this->assertTrue($result->success);
            $this->assertNotEmpty($result->changes);

            $content = file_get_contents($workDir . '/classes/FieldsPlugin.php');
            $this->assertStringContainsString("elgg()->fields->get('object', 'page')", $content);
            $this->assertStringContainsString("elgg()->fields->get('group', 'group')", $content);
            $this->assertStringContainsString("elgg()->fields->get('user', 'user')", $content);
            $this->assertStringNotContainsString("elgg_get_config('pages')", $content);
            $this->assertStringNotContainsString("elgg_get_config('group')", $content);
            $this->assertStringNotContainsString("elgg_get_config('profile_fields')", $content);

            // Unrelated config key must be untouched
            $this->assertStringContainsString("elgg_get_config('site_name')", $content);

            $output = [];
            exec("php -l " . escapeshellarg($workDir . '/classes/FieldsPlugin.php') . " 2>&1", $output, $exitCode);
            $this->assertSame(0, $exitCode, "Generated file has syntax errors: " . implode("\n", $output));
        } finally {
            $this->removeDir($workDir);
        }
    }

    public function testApplyIsIdempotent(): void
    {
        $workDir = $this->makeWorkDir();

        try {
            $this->rule->apply($workDir);
            $analysis = $this->rule->analyze($workDir);
            $this->assertFalse($analysis->applicable);
        } finally {
            $this->removeDir($workDir);
        }
    }

    public function testAnalyzeNotApplicableWhenNoFieldConfigCalls(): void
    {
        $workDir = sys_get_temp_dir() . '/elgg-migrate-' . uniqid();
        mkdir($workDir, 0755, true);
        file_put_contents($workDir . '/plugin.php', "<?php\n\$name = elgg_get_config('site_name');\n");

        try {
            $analysis = $this->rule->analyze($workDir);
            $this->assertFalse($analysis->applicable);
        } finally {
            $this->removeDir($workDir);
        }
    }

    private function makeWorkDir(): string
    {
        $src = __DIR__ . '/../../fixtures/3x-to-4x/field-config-api/input';
        $dst = sys_get_temp_dir() . '/elgg-migrate-' . uniqid();

        $this->copyDir($src, $dst);

        return $dst;
    }

    private function copyDir(string $src, string $dst): void
    {
        mkdir($dst, 0755, true);
        foreach (new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($src, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::SELF_FIRST
        ) as $item) {
            $target = $dst . substr($item->getPathname(), strlen($src));
            if ($item->isDir()) {
                mkdir($target, 0755, true);
            } else {
                copy($item->getPathname(), $target);
            }
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
