<?php

declare(strict_types=1);

namespace ElggMigrate\Tests\Rules\V3ToV4;

use ElggMigrate\Rules\V3ToV4\ExceptionClassRenames;
use PHPUnit\Framework\TestCase;

final class ExceptionClassRenamesTest extends TestCase
{
    private ExceptionClassRenames $rule;

    protected function setUp(): void
    {
        $this->rule = new ExceptionClassRenames();
    }

    public function testAnalyzeFindsOldExceptionNames(): void
    {
        $dir = __DIR__ . '/../../fixtures/3x-to-4x/exception-class-renames/input';
        $analysis = $this->rule->analyze($dir);

        $this->assertTrue($analysis->applicable);
        $this->assertGreaterThanOrEqual(3, count($analysis->findings));
    }

    public function testApplyRenamesExceptionClasses(): void
    {
        $workDir = $this->makeWorkDir();

        try {
            $result = $this->rule->apply($workDir);

            $this->assertTrue($result->success);
            $this->assertNotEmpty($result->changes);

            $content = file_get_contents($workDir . '/code.php');

            // New names present (old ones replaced)
            $this->assertStringContainsString('use Elgg\\Exceptions\\Http\\BadRequestException;', $content);
            $this->assertStringContainsString('use Elgg\\Exceptions\\Http\\EntityNotFoundException;', $content);
            $this->assertStringContainsString('\\Elgg\\Exceptions\\Configuration\\RegistrationException', $content);
            $this->assertStringContainsString('\\Elgg\\Exceptions\\DatabaseException', $content);

            // Old use statements gone
            $this->assertStringNotContainsString('use Elgg\\BadRequestException;', $content);
            $this->assertStringNotContainsString('use Elgg\\EntityNotFoundException;', $content);

            // Valid PHP
            exec("php -l " . escapeshellarg($workDir . '/code.php') . " 2>&1", $output, $exitCode);
            $this->assertSame(0, $exitCode, implode("\n", $output));
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

    public function testAnalyzeNotApplicableWhenNoOldExceptions(): void
    {
        $workDir = sys_get_temp_dir() . '/elgg-migrate-' . uniqid();
        mkdir($workDir, 0755, true);
        file_put_contents($workDir . '/test.php', "<?php\nuse Elgg\\Exceptions\\Http\\BadRequestException;\n");

        try {
            $analysis = $this->rule->analyze($workDir);
            $this->assertFalse($analysis->applicable);
        } finally {
            $this->removeDir($workDir);
        }
    }

    private function makeWorkDir(): string
    {
        $src = __DIR__ . '/../../fixtures/3x-to-4x/exception-class-renames/input';
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
