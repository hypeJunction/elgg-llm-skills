<?php

declare(strict_types=1);

namespace ElggMigrate\Tests\Rules\V4ToV5;

use ElggMigrate\Rules\V4ToV5\FakerLibrary;
use PHPUnit\Framework\TestCase;

final class FakerLibraryTest extends TestCase
{
    private FakerLibrary $rule;

    protected function setUp(): void
    {
        $this->rule = new FakerLibrary();
    }

    public function testId(): void
    {
        $this->assertSame('faker-library-5x', $this->rule->getId());
    }

    public function testCanAutomate(): void
    {
        $this->assertTrue($this->rule->canAutomate());
    }

    public function testAnalyzeDetectsOldFakerInRequireDev(): void
    {
        $dir = $this->makeDir([
            'require-dev' => [
                'fzaninotto/faker' => '^1.9',
            ],
        ]);

        try {
            $analysis = $this->rule->analyze($dir);
            $this->assertTrue($analysis->applicable);

            $combined = implode("\n", array_map(fn($f) => $f->description, $analysis->findings));
            $this->assertStringContainsString('fzaninotto/faker', $combined);
            $this->assertStringContainsString('fakerphp/faker', $combined);
        } finally {
            $this->removeDir($dir);
        }
    }

    public function testAnalyzeNotApplicableWithoutOldFaker(): void
    {
        $dir = $this->makeDir([
            'require-dev' => [
                'fakerphp/faker' => '^1.19',
            ],
        ]);

        try {
            $analysis = $this->rule->analyze($dir);
            $this->assertFalse($analysis->applicable);
        } finally {
            $this->removeDir($dir);
        }
    }

    public function testAnalyzeNotApplicableWithoutComposerJson(): void
    {
        $dir = sys_get_temp_dir() . '/elgg-migrate-faker-none-' . uniqid();
        mkdir($dir, 0755, true);

        try {
            $analysis = $this->rule->analyze($dir);
            $this->assertFalse($analysis->applicable);
        } finally {
            $this->removeDir($dir);
        }
    }

    public function testApplyReplacesPackagePreservingConstraint(): void
    {
        $dir = $this->makeDir([
            'require-dev' => [
                'fzaninotto/faker' => '^1.9',
                'phpunit/phpunit'  => '^9.0',
            ],
        ]);

        try {
            $result = $this->rule->apply($dir);
            $this->assertTrue($result->success);
            $this->assertNotEmpty($result->changes);

            $json = json_decode(file_get_contents($dir . '/composer.json'), true);
            $this->assertArrayNotHasKey('fzaninotto/faker', $json['require-dev']);
            $this->assertSame('^1.9', $json['require-dev']['fakerphp/faker']);
            $this->assertSame('^9.0', $json['require-dev']['phpunit/phpunit']);
        } finally {
            $this->removeDir($dir);
        }
    }

    public function testApplyHandlesRequireSection(): void
    {
        $dir = $this->makeDir([
            'require' => [
                'fzaninotto/faker' => '~1.8',
            ],
        ]);

        try {
            $this->rule->apply($dir);
            $json = json_decode(file_get_contents($dir . '/composer.json'), true);

            $this->assertArrayNotHasKey('fzaninotto/faker', $json['require']);
            $this->assertSame('~1.8', $json['require']['fakerphp/faker']);
        } finally {
            $this->removeDir($dir);
        }
    }

    public function testApplyIsNoopWithoutOldFaker(): void
    {
        $dir = $this->makeDir([
            'require' => [
                'elgg/elgg' => '~5.0.0',
            ],
        ]);

        try {
            $result = $this->rule->apply($dir);
            $this->assertTrue($result->success);
            $this->assertEmpty($result->changes);
        } finally {
            $this->removeDir($dir);
        }
    }

    /**
     * @param array<string,mixed> $composer
     */
    private function makeDir(array $composer): string
    {
        $dir = sys_get_temp_dir() . '/elgg-migrate-faker-' . uniqid();
        mkdir($dir, 0755, true);
        file_put_contents(
            $dir . '/composer.json',
            json_encode($composer, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n",
        );

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
