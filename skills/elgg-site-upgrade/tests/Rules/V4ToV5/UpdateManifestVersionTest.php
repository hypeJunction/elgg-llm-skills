<?php

declare(strict_types=1);

namespace ElggMigrate\Tests\Rules\V4ToV5;

use ElggMigrate\Rules\V4ToV5\UpdateManifestVersion;
use PHPUnit\Framework\TestCase;

final class UpdateManifestVersionTest extends TestCase
{
    private UpdateManifestVersion $rule;

    protected function setUp(): void
    {
        $this->rule = new UpdateManifestVersion();
    }

    public function testId(): void
    {
        $this->assertSame('update-manifest-version-5x', $this->rule->getId());
    }

    public function testCanAutomate(): void
    {
        $this->assertTrue($this->rule->canAutomate());
    }

    public function testAnalyzeFlagsOldElggPhpAndMissingIntl(): void
    {
        $dir = $this->makeDir([
            'require' => [
                'elgg/elgg' => '~4.0.0',
                'php'       => '>=7.4',
            ],
        ]);

        try {
            $analysis = $this->rule->analyze($dir);
            $this->assertTrue($analysis->applicable);

            $combined = implode("\n", array_map(fn($f) => $f->description, $analysis->findings));
            $this->assertStringContainsString('elgg/elgg', $combined);
            $this->assertStringContainsString('~5.0.0', $combined);
            $this->assertStringContainsString('>=8.1', $combined);
            $this->assertStringContainsString('ext-intl', $combined);
        } finally {
            $this->removeDir($dir);
        }
    }

    public function testAnalyzeNotApplicableWhenAlreadyOn5x(): void
    {
        $dir = $this->makeDir([
            'require' => [
                'elgg/elgg' => '~5.0.0',
                'php'       => '>=8.1',
                'ext-intl'  => '*',
            ],
        ]);

        try {
            $analysis = $this->rule->analyze($dir);
            $this->assertFalse($analysis->applicable);
        } finally {
            $this->removeDir($dir);
        }
    }

    public function testAnalyzeNotApplicableWhenNoComposerJson(): void
    {
        $dir = sys_get_temp_dir() . '/elgg-migrate-manifest-none-' . uniqid();
        mkdir($dir, 0755, true);

        try {
            $analysis = $this->rule->analyze($dir);
            $this->assertFalse($analysis->applicable);
        } finally {
            $this->removeDir($dir);
        }
    }

    public function testApplyRewritesRequirements(): void
    {
        $dir = $this->makeDir([
            'require' => [
                'elgg/elgg' => '~4.0.0',
                'php'       => '>=7.4',
            ],
        ]);

        try {
            $result = $this->rule->apply($dir);
            $this->assertTrue($result->success);
            $this->assertNotEmpty($result->changes);

            $json = json_decode(file_get_contents($dir . '/composer.json'), true);
            $this->assertSame('~5.0.0', $json['require']['elgg/elgg']);
            $this->assertSame('>=8.1', $json['require']['php']);
            $this->assertSame('*', $json['require']['ext-intl']);
        } finally {
            $this->removeDir($dir);
        }
    }

    public function testApplyIsNoopWhenAlreadyCurrent(): void
    {
        $dir = $this->makeDir([
            'require' => [
                'elgg/elgg' => '~5.0.0',
                'php'       => '>=8.1',
                'ext-intl'  => '*',
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
        $dir = sys_get_temp_dir() . '/elgg-migrate-manifest-' . uniqid();
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
