<?php

declare(strict_types=1);

namespace ElggMigrate\Tests\Rules\V2ToV3;

use ElggMigrate\MigrationRule;
use ElggMigrate\Rules\V2ToV3\ConfigGlobalRemoval;
use ElggMigrate\Rules\V2ToV3\DeprecatedEntityQueries;
use ElggMigrate\Rules\V2ToV3\ElggPluginsPath;
use ElggMigrate\Rules\V2ToV3\ElggRegisterAjaxView;
use ElggMigrate\Rules\V2ToV3\LibraryToAutoload;
use ElggMigrate\Rules\V2ToV3\PageHandlerToRoute;
use ElggMigrate\Rules\V2ToV3\PagesetupEvent;
use ElggMigrate\Rules\V2ToV3\RemovedClasses;
use ElggMigrate\Rules\V2ToV3\RemovedFunctions;
use ElggMigrate\Rules\V2ToV3\RemovedMethods;
use ElggMigrate\Rules\V2ToV3\SubtypeRegistration;
use ElggMigrate\Rules\V2ToV3\UpdateManifestVersion;
use PHPUnit\Framework\TestCase;

/**
 * Runs ALL automated 2.x→3.x rules against the guinea pig plugins.
 * Verifies that analyze() works and apply() doesn't crash on real code.
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
            'PageHandlerToRoute' => [new PageHandlerToRoute()],
            'LibraryToAutoload' => [new LibraryToAutoload()],
            'DeprecatedEntityQueries' => [new DeprecatedEntityQueries()],
            'SubtypeRegistration' => [new SubtypeRegistration()],
            'ConfigGlobalRemoval' => [new ConfigGlobalRemoval()],
            'RemovedFunctions' => [new RemovedFunctions()],
            'RemovedMethods' => [new RemovedMethods()],
            'RemovedClasses' => [new RemovedClasses()],
            'PagesetupEvent' => [new PagesetupEvent()],
            'ElggPluginsPath' => [new ElggPluginsPath()],
            'ElggRegisterAjaxView' => [new ElggRegisterAjaxView()],
        ];
    }

    /**
     * @return array<string, array{string}>
     */
    public static function guineaPigProvider(): array
    {
        return [
            'hypeWall' => ['hypeWall'],
            'elgg-forum' => ['elgg-forum'],
            'community_plugins' => ['community_plugins'],
        ];
    }

    /**
     * @dataProvider ruleProvider
     */
    public function testAnalyzeDoesNotCrashOnAnyGuineaPig(MigrationRule $rule): void
    {
        foreach (['hypeWall', 'elgg-forum', 'community_plugins'] as $plugin) {
            $path = __DIR__ . '/../../../tmp/' . $plugin;
            if (!is_dir($path)) {
                $this->markTestSkipped("Guinea pig not cloned: tmp/{$plugin}");
            }

            $analysis = $rule->analyze($path);
            $this->assertSame($rule->getId(), $analysis->ruleId);
            // Just verify it doesn't throw — no assertion on findings count
        }

        $this->assertTrue(true); // Ensure at least one assertion
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

        $workDir = sys_get_temp_dir() . '/elgg-migrate-full-' . uniqid();
        $this->copyDirectory($srcPath, $workDir);

        try {
            $rules = [
                new UpdateManifestVersion(),
                new PageHandlerToRoute(),
                new LibraryToAutoload(),
                new DeprecatedEntityQueries(),
                new SubtypeRegistration(),
                new ConfigGlobalRemoval(),
                new RemovedFunctions(),
                new RemovedMethods(),
                new RemovedClasses(),
                new PagesetupEvent(),
                new ElggPluginsPath(),
                new ElggRegisterAjaxView(),
            ];

            $totalChanges = 0;
            foreach ($rules as $rule) {
                $analysis = $rule->analyze($workDir);
                if (!$analysis->applicable) {
                    continue;
                }

                $result = $rule->apply($workDir);
                $this->assertTrue($result->success, "Rule {$rule->getId()} failed on {$plugin}");
                $totalChanges += count($result->changes);
            }

            // At least some rules should have applied
            $this->assertGreaterThan(0, $totalChanges, "Expected at least one rule to apply to {$plugin}");

            // Verify all PHP files still have valid syntax
            // (skip files that had syntax errors before migration)
            foreach ($this->findPhpFiles($workDir) as $file) {
                $relativePath = str_replace($workDir . '/', '', $file);
                $originalFile = $srcPath . '/' . $relativePath;

                // Skip files that already had syntax errors in the original
                if (file_exists($originalFile)) {
                    $origOutput = [];
                    $origExit = 0;
                    exec("php -l " . escapeshellarg($originalFile) . " 2>&1", $origOutput, $origExit);
                    if ($origExit !== 0) {
                        continue; // Pre-existing syntax error
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
