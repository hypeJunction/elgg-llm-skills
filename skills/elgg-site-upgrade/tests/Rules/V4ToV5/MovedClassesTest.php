<?php

declare(strict_types=1);

namespace ElggMigrate\Tests\Rules\V4ToV5;

use ElggMigrate\Rules\V4ToV5\MovedClasses;
use PHPUnit\Framework\TestCase;

final class MovedClassesTest extends TestCase
{
    private MovedClasses $rule;

    protected function setUp(): void
    {
        $this->rule = new MovedClasses();
    }

    public function testId(): void
    {
        $this->assertSame('moved-classes-5x', $this->rule->getId());
    }

    public function testCanAutomate(): void
    {
        $this->assertTrue($this->rule->canAutomate());
    }

    public function testAnalyzeDetectsMovedUseImport(): void
    {
        $dir = $this->makeDir(<<<'PHP'
<?php
use ElggDiskFilestore;

$store = new \ElggDiskFilestore();
PHP);

        try {
            $analysis = $this->rule->analyze($dir);
            $this->assertTrue($analysis->applicable);

            $combined = implode("\n", array_map(fn($f) => $f->description, $analysis->findings));
            $this->assertStringContainsString('ElggDiskFilestore', $combined);
            $this->assertStringContainsString('Elgg\\Filesystem\\Filestore\\DiskFilestore', $combined);
        } finally {
            $this->removeDir($dir);
        }
    }

    public function testAnalyzeFlagsRemovedClassForManualReview(): void
    {
        $dir = $this->makeDir(<<<'PHP'
<?php
use Elgg\WebServices\ApiKeyForm;
PHP);

        try {
            $analysis = $this->rule->analyze($dir);
            $this->assertTrue($analysis->applicable);

            $combined = implode("\n", array_map(fn($f) => $f->description, $analysis->findings));
            $this->assertStringContainsString('removed in 5.0', $combined);
        } finally {
            $this->removeDir($dir);
        }
    }

    public function testAnalyzeNotApplicableOnCleanCode(): void
    {
        $dir = $this->makeDir(<<<'PHP'
<?php
use Elgg\Filesystem\Filestore\DiskFilestore;

$store = new DiskFilestore();
PHP);

        try {
            $analysis = $this->rule->analyze($dir);
            $this->assertFalse($analysis->applicable);
        } finally {
            $this->removeDir($dir);
        }
    }

    public function testApplyRewritesUseImport(): void
    {
        $dir = $this->makeDir(<<<'PHP'
<?php
use ElggDiskFilestore;
PHP);

        try {
            $result = $this->rule->apply($dir);
            $this->assertTrue($result->success);
            $this->assertNotEmpty($result->changes);

            $code = file_get_contents($dir . '/target.php');
            $this->assertStringContainsString('use Elgg\Filesystem\Filestore\DiskFilestore;', $code);
            $this->assertStringNotContainsString('use ElggDiskFilestore;', $code);
        } finally {
            $this->removeDir($dir);
        }
    }

    public function testApplyRewritesFullyQualifiedReference(): void
    {
        $dir = $this->makeDir(<<<'PHP'
<?php
$store = new \ElggDiskFilestore();
$cache = new \ElggCache();
PHP);

        try {
            $this->rule->apply($dir);
            $code = file_get_contents($dir . '/target.php');

            $this->assertStringContainsString('new \Elgg\Filesystem\Filestore\DiskFilestore()', $code);
            $this->assertStringContainsString('new \Elgg\Cache\BaseCache()', $code);
        } finally {
            $this->removeDir($dir);
        }
    }

    public function testApplyRewritesNamespacedMovedClass(): void
    {
        $dir = $this->makeDir(<<<'PHP'
<?php
use Elgg\Database\SiteSecret;
PHP);

        try {
            $this->rule->apply($dir);
            $code = file_get_contents($dir . '/target.php');

            $this->assertStringContainsString('use Elgg\Security\SiteSecret;', $code);
            $this->assertStringNotContainsString('Elgg\Database\SiteSecret', $code);
        } finally {
            $this->removeDir($dir);
        }
    }

    public function testApplyOnCleanCodeMakesNoChanges(): void
    {
        $dir = $this->makeDir("<?php\n\$x = 1;\n");

        try {
            $result = $this->rule->apply($dir);
            $this->assertTrue($result->success);
            $this->assertEmpty($result->changes);
        } finally {
            $this->removeDir($dir);
        }
    }

    private function makeDir(string $code): string
    {
        $dir = sys_get_temp_dir() . '/elgg-migrate-movedcls-' . uniqid();
        mkdir($dir, 0755, true);
        file_put_contents($dir . '/target.php', $code);

        return $dir;
    }

    private function removeDir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        $iter = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($iter as $f) {
            $f->isDir() ? rmdir($f->getPathname()) : unlink($f->getPathname());
        }
        rmdir($dir);
    }
}
