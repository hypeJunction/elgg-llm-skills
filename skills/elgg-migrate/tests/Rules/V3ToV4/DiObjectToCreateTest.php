<?php

declare(strict_types=1);

namespace ElggMigrate\Tests\Rules\V3ToV4;

use ElggMigrate\Rules\V3ToV4\DiObjectToCreate;
use PHPUnit\Framework\TestCase;

final class DiObjectToCreateTest extends TestCase
{
    private DiObjectToCreate $rule;

    protected function setUp(): void
    {
        $this->rule = new DiObjectToCreate();
    }

    public function testAnalyzeFindsDiObjectCalls(): void
    {
        $dir = __DIR__ . '/../../fixtures/3x-to-4x/di-object-to-create/input';
        $analysis = $this->rule->analyze($dir);

        $this->assertTrue($analysis->applicable);
        $this->assertCount(2, $analysis->findings);
        $this->assertStringContainsString('\\DI\\object()', $analysis->findings[0]->description);
    }

    public function testApplyReplacesDiObjectWithCreate(): void
    {
        $workDir = $this->makeWorkDir();

        try {
            $result = $this->rule->apply($workDir);

            $this->assertTrue($result->success);
            $this->assertNotEmpty($result->changes);

            $content = file_get_contents($workDir . '/elgg-services.php');
            $this->assertStringContainsString('\\DI\\create(', $content);
            $this->assertStringNotContainsString('\\DI\\object(', $content);

            // Verify valid PHP
            $output = [];
            exec("php -l " . escapeshellarg($workDir . '/elgg-services.php') . " 2>&1", $output, $exitCode);
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

    public function testAnalyzeNotApplicableWhenNoDiObject(): void
    {
        $workDir = sys_get_temp_dir() . '/elgg-migrate-' . uniqid();
        mkdir($workDir, 0755, true);
        file_put_contents($workDir . '/elgg-services.php', "<?php\nreturn [\n    MyService::class => \\DI\\create(MyService::class),\n];\n");

        try {
            $analysis = $this->rule->analyze($workDir);
            $this->assertFalse($analysis->applicable);
        } finally {
            $this->removeDir($workDir);
        }
    }

    private function makeWorkDir(): string
    {
        $src = __DIR__ . '/../../fixtures/3x-to-4x/di-object-to-create/input';
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
