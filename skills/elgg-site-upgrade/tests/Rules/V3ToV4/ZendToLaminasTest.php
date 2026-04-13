<?php

declare(strict_types=1);

namespace ElggMigrate\Tests\Rules\V3ToV4;

use ElggMigrate\Rules\V3ToV4\ZendToLaminas;
use PHPUnit\Framework\TestCase;

final class ZendToLaminasTest extends TestCase
{
    private ZendToLaminas $rule;

    protected function setUp(): void
    {
        $this->rule = new ZendToLaminas();
    }

    public function testAnalyzeFindsZendReferences(): void
    {
        $dir = __DIR__ . '/../../fixtures/3x-to-4x/zend-to-laminas/input';
        $analysis = $this->rule->analyze($dir);

        $this->assertTrue($analysis->applicable);
        $this->assertGreaterThanOrEqual(2, count($analysis->findings)); // PHP + composer
    }

    public function testApplyReplacesZendWithLaminas(): void
    {
        $workDir = $this->makeWorkDir();

        try {
            $result = $this->rule->apply($workDir);

            $this->assertTrue($result->success);
            $this->assertNotEmpty($result->changes);

            // Check PHP file
            $phpContent = file_get_contents($workDir . '/classes/MyPlugin/Mailer.php');
            $this->assertStringContainsString('Laminas\\Mail\\Message', $phpContent);
            $this->assertStringContainsString('Laminas\\Mail\\Transport\\Sendmail', $phpContent);
            $this->assertStringNotContainsString('Zend\\Mail', $phpContent);

            // Verify valid PHP
            $output = [];
            exec("php -l " . escapeshellarg($workDir . '/classes/MyPlugin/Mailer.php') . " 2>&1", $output, $exitCode);
            $this->assertSame(0, $exitCode, "File has syntax errors: " . implode("\n", $output));

            // Check composer.json
            $json = json_decode(file_get_contents($workDir . '/composer.json'), true);
            $this->assertArrayHasKey('laminas/laminas-mail', $json['require']);
            $this->assertArrayNotHasKey('zendframework/zend-mail', $json['require']);
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

    public function testAnalyzeNotApplicableWhenNoZend(): void
    {
        $workDir = sys_get_temp_dir() . '/elgg-migrate-' . uniqid();
        mkdir($workDir, 0755, true);
        file_put_contents($workDir . '/test.php', "<?php\nuse Laminas\\Mail\\Message;\n");

        try {
            $analysis = $this->rule->analyze($workDir);
            $this->assertFalse($analysis->applicable);
        } finally {
            $this->removeDir($workDir);
        }
    }

    private function makeWorkDir(): string
    {
        $src = __DIR__ . '/../../fixtures/3x-to-4x/zend-to-laminas/input';
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
            $target = $dst . '/' . substr($item->getPathname(), strlen($src) + 1);
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
