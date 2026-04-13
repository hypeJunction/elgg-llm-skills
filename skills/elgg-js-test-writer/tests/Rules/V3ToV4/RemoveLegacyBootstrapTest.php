<?php

declare(strict_types=1);

namespace ElggMigrate\Tests\Rules\V3ToV4;

use ElggMigrate\Rules\V3ToV4\RemoveLegacyBootstrap;
use PHPUnit\Framework\TestCase;

final class RemoveLegacyBootstrapTest extends TestCase
{
    private RemoveLegacyBootstrap $rule;

    protected function setUp(): void
    {
        $this->rule = new RemoveLegacyBootstrap();
    }

    public function testAnalyzeFindsLegacyFiles(): void
    {
        $dir = __DIR__ . '/../../fixtures/3x-to-4x/remove-legacy-bootstrap/input';
        $analysis = $this->rule->analyze($dir);

        $this->assertTrue($analysis->applicable);
        // start.php, activate.php, deactivate.php, views.php = 4
        $this->assertCount(4, $analysis->findings);
    }

    public function testAnalyzeNotApplicableWithoutElggPluginPhp(): void
    {
        $workDir = sys_get_temp_dir() . '/elgg-migrate-' . uniqid();
        mkdir($workDir, 0755, true);
        file_put_contents($workDir . '/start.php', "<?php\n");

        try {
            $analysis = $this->rule->analyze($workDir);
            $this->assertFalse($analysis->applicable);
            $this->assertStringContainsString('elgg-plugin.php', $analysis->summary);
        } finally {
            $this->removeDir($workDir);
        }
    }

    public function testAnalyzeNotApplicableWhenNoLegacyFiles(): void
    {
        $workDir = sys_get_temp_dir() . '/elgg-migrate-' . uniqid();
        mkdir($workDir, 0755, true);
        file_put_contents($workDir . '/elgg-plugin.php', "<?php\nreturn [];\n");

        try {
            $analysis = $this->rule->analyze($workDir);
            $this->assertFalse($analysis->applicable);
        } finally {
            $this->removeDir($workDir);
        }
    }

    public function testApplyRemovesActivateWithSubtypeOnly(): void
    {
        $workDir = $this->makeWorkDir();

        try {
            $result = $this->rule->apply($workDir);

            $this->assertTrue($result->success);
            $this->assertFileDoesNotExist($workDir . '/activate.php');

            $changeFiles = array_map(fn($c) => $c->file, $result->changes);
            $this->assertContains('activate.php', $changeFiles);
        } finally {
            $this->removeDir($workDir);
        }
    }

    public function testApplyRemovesEmptyDeactivate(): void
    {
        $workDir = $this->makeWorkDir();

        try {
            $result = $this->rule->apply($workDir);

            $this->assertTrue($result->success);
            $this->assertFileDoesNotExist($workDir . '/deactivate.php');

            $changeFiles = array_map(fn($c) => $c->file, $result->changes);
            $this->assertContains('deactivate.php', $changeFiles);
        } finally {
            $this->removeDir($workDir);
        }
    }

    public function testApplyRemovesViewsPhp(): void
    {
        $workDir = $this->makeWorkDir();

        try {
            $result = $this->rule->apply($workDir);

            $this->assertTrue($result->success);
            $this->assertFileDoesNotExist($workDir . '/views.php');

            $changeFiles = array_map(fn($c) => $c->file, $result->changes);
            $this->assertContains('views.php', $changeFiles);
        } finally {
            $this->removeDir($workDir);
        }
    }

    public function testApplyRemovesStartPhpWithOnlyRegistrations(): void
    {
        $workDir = $this->makeWorkDir();

        try {
            $result = $this->rule->apply($workDir);

            $this->assertTrue($result->success);
            // The fixture start.php only has registration calls — should be removed
            $this->assertFileDoesNotExist($workDir . '/start.php');

            $changeFiles = array_map(fn($c) => $c->file, $result->changes);
            $this->assertContains('start.php', $changeFiles);
        } finally {
            $this->removeDir($workDir);
        }
    }

    public function testApplyWarnsAboutStartPhpWithComplexLogic(): void
    {
        $workDir = $this->makeWorkDir();
        // Add complex logic to start.php
        file_put_contents($workDir . '/start.php', "<?php\nif (elgg_is_admin_logged_in()) {\n    doSomethingCustom();\n}\n");

        try {
            $result = $this->rule->apply($workDir);

            $this->assertTrue($result->success);
            $this->assertFileExists($workDir . '/start.php');
            $this->assertNotEmpty($result->warnings);

            $warningText = implode("\n", $result->warnings);
            $this->assertStringContainsString('start.php', $warningText);
        } finally {
            $this->removeDir($workDir);
        }
    }

    public function testApplyKeepsActivateWithCustomLogic(): void
    {
        $workDir = $this->makeWorkDir();
        // Add custom logic beyond subtype registration
        file_put_contents($workDir . '/activate.php', "<?php\nelgg_set_entity_class('object', 'item', Item::class);\ncreate_default_settings();\n");

        try {
            $result = $this->rule->apply($workDir);

            $this->assertTrue($result->success);
            $this->assertFileExists($workDir . '/activate.php');
            $this->assertNotEmpty($result->warnings);
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

    /**
     * @dataProvider guineaPigProvider
     */
    public function testAnalyzeRealPlugin(string $pluginDir): void
    {
        $path = __DIR__ . '/../../../tmp/' . $pluginDir;
        if (!is_dir($path)) {
            $this->markTestSkipped("Not cloned: tmp/{$pluginDir}");
        }

        $analysis = $this->rule->analyze($path);
        $this->assertSame($this->rule->getId(), $analysis->ruleId);
    }

    public static function guineaPigProvider(): array
    {
        return [
            'Elgg3-hypeWall' => ['Elgg3-hypeWall'],
            'Elgg3-hypeInteractions' => ['Elgg3-hypeInteractions'],
            'Elgg3-hypeDropzone' => ['Elgg3-hypeDropzone'],
        ];
    }

    private function makeWorkDir(): string
    {
        $src = __DIR__ . '/../../fixtures/3x-to-4x/remove-legacy-bootstrap/input';
        $dst = sys_get_temp_dir() . '/elgg-migrate-' . uniqid();
        mkdir($dst, 0755, true);
        foreach (new \DirectoryIterator($src) as $f) {
            if ($f->isDot() || $f->isDir()) continue;
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
