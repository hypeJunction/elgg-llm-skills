<?php

declare(strict_types=1);

namespace ElggMigrate\Tests\Rules\V3ToV4;

use ElggMigrate\MigrationRule;
use ElggMigrate\Rules\V3ToV4\CanWriteToContainer;
use ElggMigrate\Rules\V3ToV4\DiObjectToCreate;
use ElggMigrate\Rules\V3ToV4\EntityAttributeSetters;
use ElggMigrate\Rules\V3ToV4\GenerateElggPluginPhp;
use ElggMigrate\Rules\V3ToV4\RemoveLegacyBootstrap;
use ElggMigrate\Rules\V3ToV4\UpdateManifestVersion;
use ElggMigrate\Rules\V3ToV4\ZendToLaminas;
use PHPUnit\Framework\TestCase;

/**
 * Runs ALL automated 3.x→4.x rules against the guinea pig plugins.
 * Verifies that analyze() works and apply() doesn't crash on real code.
 *
 * Guinea pigs are Elgg3-hype* repos cloned into tmp/.
 * These plugins target Elgg 3.x and have elgg-plugin.php already.
 */
final class AllRulesGuineaPigTest extends TestCase
{
    /**
     * @return array<string, array{MigrationRule}>
     */
    public static function ruleProvider(): array
    {
        return [
            'UpdateManifestVersion' => [new UpdateManifestVersion()],
            'GenerateElggPluginPhp' => [new GenerateElggPluginPhp()],
            'RemoveLegacyBootstrap' => [new RemoveLegacyBootstrap()],
            'DiObjectToCreate' => [new DiObjectToCreate()],
            'ZendToLaminas' => [new ZendToLaminas()],
            'EntityAttributeSetters' => [new EntityAttributeSetters()],
            'CanWriteToContainer' => [new CanWriteToContainer()],
        ];
    }

    /**
     * @return array<string, array{string}>
     */
    public static function guineaPigProvider(): array
    {
        return [
            'Elgg3-hypeWall' => ['Elgg3-hypeWall'],
            'Elgg3-hypeInteractions' => ['Elgg3-hypeInteractions'],
            'Elgg3-hypeDropzone' => ['Elgg3-hypeDropzone'],
        ];
    }

    /**
     * @dataProvider ruleProvider
     */
    public function testAnalyzeDoesNotCrashOnAnyGuineaPig(MigrationRule $rule): void
    {
        $anyTested = false;

        foreach (['Elgg3-hypeWall', 'Elgg3-hypeInteractions', 'Elgg3-hypeDropzone'] as $plugin) {
            $path = __DIR__ . '/../../../tmp/' . $plugin;
            if (!is_dir($path)) {
                continue;
            }

            $analysis = $rule->analyze($path);
            $this->assertSame($rule->getId(), $analysis->ruleId);
            $anyTested = true;
        }

        if (!$anyTested) {
            $this->markTestSkipped('No guinea pig plugins cloned in tmp/');
        }
    }

    /**
     * @dataProvider guineaPigProvider
     */
    public function testApplyAllRulesOnGuineaPig(string $plugin): void
    {
        $srcPath = __DIR__ . '/../../../tmp/' . $plugin;
        if (!is_dir($srcPath)) {
            $this->markTestSkipped("Guinea pig not cloned: tmp/{$plugin}");
        }

        $workDir = sys_get_temp_dir() . '/elgg-migrate-4x-' . uniqid();
        $this->copyDirectory($srcPath, $workDir);

        try {
            $rules = [
                new UpdateManifestVersion(),
                new GenerateElggPluginPhp(),
                new RemoveLegacyBootstrap(),
                new DiObjectToCreate(),
                new ZendToLaminas(),
                new EntityAttributeSetters(),
                new CanWriteToContainer(),
            ];

            $totalChanges = 0;
            $totalWarnings = 0;
            foreach ($rules as $rule) {
                $analysis = $rule->analyze($workDir);
                if (!$analysis->applicable) {
                    continue;
                }

                $result = $rule->apply($workDir);
                $this->assertTrue($result->success, "Rule {$rule->getId()} failed on {$plugin}");
                $totalChanges += count($result->changes);
                $totalWarnings += count($result->warnings);
            }

            // At least some rules should have applied or produced warnings
            $this->assertGreaterThan(
                0,
                $totalChanges + $totalWarnings,
                "Expected at least one rule to apply or warn on {$plugin}",
            );

            // Verify all PHP files still have valid syntax
            foreach ($this->findPhpFiles($workDir) as $file) {
                $relativePath = str_replace($workDir . '/', '', $file);
                $originalFile = $srcPath . '/' . $relativePath;

                // Skip files that already had syntax errors in the original
                if (file_exists($originalFile)) {
                    $origOutput = [];
                    $origExit = 0;
                    exec("php -l " . escapeshellarg($originalFile) . " 2>&1", $origOutput, $origExit);
                    if ($origExit !== 0) {
                        continue;
                    }
                }

                $output = [];
                $exitCode = 0;
                exec("php -l " . escapeshellarg($file) . " 2>&1", $output, $exitCode);
                $this->assertSame(
                    0,
                    $exitCode,
                    "PHP syntax error in {$relativePath} after migration:\n" . implode("\n", $output),
                );
            }
        } finally {
            $this->removeDirectory($workDir);
        }
    }

    /**
     * Verify DiObjectToCreate specifically transforms elgg-services.php
     * in guinea pigs that have \DI\object() calls.
     */
    public function testDiObjectToCreateOnRealPlugins(): void
    {
        $plugins = ['Elgg3-hypeInteractions', 'Elgg3-hypeDropzone'];
        $rule = new DiObjectToCreate();
        $anyTested = false;

        foreach ($plugins as $plugin) {
            $srcPath = __DIR__ . '/../../../tmp/' . $plugin;
            if (!is_dir($srcPath)) {
                continue;
            }

            $servicesFile = $srcPath . '/elgg-services.php';
            if (!is_file($servicesFile) || !str_contains(file_get_contents($servicesFile), 'DI\\object')) {
                continue;
            }

            $workDir = sys_get_temp_dir() . '/elgg-migrate-di-' . uniqid();
            $this->copyDirectory($srcPath, $workDir);

            try {
                $analysis = $rule->analyze($workDir);
                $this->assertTrue($analysis->applicable, "Should find \\DI\\object() in {$plugin}");

                $result = $rule->apply($workDir);
                $this->assertTrue($result->success);
                $this->assertNotEmpty($result->changes);

                $newContent = file_get_contents($workDir . '/elgg-services.php');
                $this->assertStringContainsString('DI\\create', $newContent);
                $this->assertStringNotContainsString('DI\\object', $newContent);

                // Verify valid PHP
                $output = [];
                exec("php -l " . escapeshellarg($workDir . '/elgg-services.php') . " 2>&1", $output, $exitCode);
                $this->assertSame(0, $exitCode, "Syntax error after transform in {$plugin}");

                $anyTested = true;
            } finally {
                $this->removeDirectory($workDir);
            }
        }

        if (!$anyTested) {
            $this->markTestSkipped('No guinea pigs with \\DI\\object() available');
        }
    }

    /**
     * @return \Generator<string>
     */
    private function findPhpFiles(string $dir): \Generator
    {
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
        );

        foreach ($iterator as $file) {
            if ($file->getExtension() === 'php') {
                yield $file->getPathname();
            }
        }
    }

    private function copyDirectory(string $source, string $dest): void
    {
        if (!is_dir($dest)) {
            mkdir($dest, 0755, true);
        }

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($source, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::SELF_FIRST,
        );

        foreach ($iterator as $item) {
            $target = $dest . '/' . $iterator->getSubPathname();
            if ($item->isDir()) {
                if (!is_dir($target)) mkdir($target, 0755, true);
            } else {
                copy($item->getPathname(), $target);
            }
        }
    }

    private function removeDirectory(string $dir): void
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
