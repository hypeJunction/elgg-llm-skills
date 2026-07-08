<?php

declare(strict_types=1);

namespace ElggMigrate\Tests\Rules\V6ToV7;

use ElggMigrate\Rules\V6ToV7\ComposerStabilitySettings;
use PHPUnit\Framework\TestCase;

final class ComposerStabilitySettingsTest extends TestCase
{
    private ComposerStabilitySettings $rule;

    protected function setUp(): void
    {
        $this->rule = new ComposerStabilitySettings();
    }

    public function testId(): void
    {
        $this->assertSame('composer-stability-settings-7x', $this->rule->getId());
    }

    public function testCanAutomate(): void
    {
        $this->assertTrue($this->rule->canAutomate());
    }

    public function testAddsMissingStabilitySettings(): void
    {
        $dir = $this->makeDir([
            'composer.json' => json_encode(['name' => 'acme/plugin'], JSON_PRETTY_PRINT),
        ]);
        try {
            $analysis = $this->rule->analyze($dir);
            $this->assertTrue($analysis->applicable);
            // minimum-stability + prefer-stable both missing.
            $this->assertCount(2, $analysis->findings);

            $result = $this->rule->apply($dir);
            $this->assertTrue($result->success);
            $this->assertCount(1, $result->changes);

            $data = $this->readComposer($dir);
            $this->assertSame('dev', $data['minimum-stability']);
            $this->assertTrue($data['prefer-stable']);
        } finally {
            $this->removeDir($dir);
        }
    }

    public function testAlreadyCompliantIsNotApplicableAndUnchanged(): void
    {
        $dir = $this->makeDir([
            'composer.json' => json_encode([
                'name' => 'acme/plugin',
                'minimum-stability' => 'dev',
                'prefer-stable' => true,
            ], JSON_PRETTY_PRINT),
        ]);
        try {
            $this->assertFalse($this->rule->analyze($dir)->applicable);
            $before = file_get_contents($dir . '/composer.json');
            $result = $this->rule->apply($dir);
            $this->assertTrue($result->success);
            $this->assertCount(0, $result->changes);
            $this->assertSame($before, file_get_contents($dir . '/composer.json'));
        } finally {
            $this->removeDir($dir);
        }
    }

    public function testAddsAssetPackagistWhenBowerAssetDependencyPresent(): void
    {
        $dir = $this->makeDir([
            'composer.json' => json_encode([
                'name' => 'acme/plugin',
                'require' => ['bower-asset/jquery' => '^3.0'],
            ], JSON_PRETTY_PRINT),
        ]);
        try {
            $this->assertTrue($this->rule->analyze($dir)->applicable);
            $this->rule->apply($dir);
            $data = $this->readComposer($dir);
            $urls = array_map(static fn ($r) => $r['url'] ?? null, $data['repositories'] ?? []);
            $this->assertContains('https://asset-packagist.org', $urls);
        } finally {
            $this->removeDir($dir);
        }
    }

    public function testDoesNotAddAssetPackagistWithoutAssetDependency(): void
    {
        $dir = $this->makeDir([
            'composer.json' => json_encode([
                'name' => 'acme/plugin',
                'require' => ['elgg/elgg' => '^7.0'],
            ], JSON_PRETTY_PRINT),
        ]);
        try {
            $this->rule->apply($dir);
            $data = $this->readComposer($dir);
            $this->assertArrayNotHasKey('repositories', $data);
            // Stability settings still applied.
            $this->assertSame('dev', $data['minimum-stability']);
            $this->assertTrue($data['prefer-stable']);
        } finally {
            $this->removeDir($dir);
        }
    }

    public function testNoComposerJsonIsNotApplicable(): void
    {
        $dir = $this->makeDir([
            'start.php' => "<?php\n",
        ]);
        try {
            $this->assertFalse($this->rule->analyze($dir)->applicable);
            $result = $this->rule->apply($dir);
            $this->assertTrue($result->success);
        } finally {
            $this->removeDir($dir);
        }
    }

    /**
     * @return array<string,mixed>
     */
    private function readComposer(string $dir): array
    {
        $data = json_decode((string) file_get_contents($dir . '/composer.json'), true);
        $this->assertIsArray($data);
        return $data;
    }

    /**
     * @param array<string,string> $files
     */
    private function makeDir(array $files): string
    {
        $dir = sys_get_temp_dir() . '/elgg-migrate-css7-' . uniqid();
        mkdir($dir, 0755, true);
        foreach ($files as $name => $content) {
            $path = $dir . '/' . $name;
            if (!is_dir(dirname($path))) {
                mkdir(dirname($path), 0755, true);
            }
            file_put_contents($path, $content);
        }
        return $dir;
    }

    private function removeDir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        foreach (new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        ) as $f) {
            $f->isDir() ? rmdir($f->getPathname()) : unlink($f->getPathname());
        }
        rmdir($dir);
    }
}
