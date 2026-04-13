<?php

declare(strict_types=1);

namespace ElggMigrate\Tests\Rules\V2ToV3;

use ElggMigrate\Rules\V2ToV3\SubtypeRegistration;
use PHPUnit\Framework\TestCase;

final class SubtypeRegistrationTest extends TestCase
{
    private SubtypeRegistration $rule;

    protected function setUp(): void
    {
        $this->rule = new SubtypeRegistration();
    }

    public function testAnalyzeFindsSubtypeCalls(): void
    {
        $dir = __DIR__ . '/../../fixtures/2x-to-3x/subtype-registration/input';
        $analysis = $this->rule->analyze($dir);

        $this->assertTrue($analysis->applicable);
        // update_subtype (3-arg), add_subtype, update_subtype (2-arg clear) = 3
        $this->assertCount(3, $analysis->findings);
    }

    public function testApplyConvertsToEntityClass(): void
    {
        $workDir = $this->makeWorkDir('subtype-registration');

        try {
            $result = $this->rule->apply($workDir);

            $this->assertTrue($result->success);

            $code = file_get_contents($workDir . '/activate.php');
            $this->assertStringContainsString('elgg_set_entity_class', $code);
            $this->assertStringNotContainsString('add_subtype', $code);
            $this->assertStringNotContainsString('update_subtype', $code);
        } finally {
            $this->removeDir($workDir);
        }
    }

    /**
     * @dataProvider guineaPigProvider
     */
    public function testAnalyzeRealPlugin(string $pluginDir, int $minFindings): void
    {
        $path = __DIR__ . '/../../../tmp/' . $pluginDir;
        if (!is_dir($path)) {
            $this->markTestSkipped("Guinea pig not cloned: tmp/{$pluginDir}");
        }

        $analysis = $this->rule->analyze($path);

        $this->assertTrue($analysis->applicable, "Should find subtype calls in {$pluginDir}");
        $this->assertGreaterThanOrEqual($minFindings, count($analysis->findings));
    }

    public static function guineaPigProvider(): array
    {
        return [
            'hypeWall' => ['hypeWall', 2],
            'community_plugins' => ['community_plugins', 4],
        ];
    }

    private function makeWorkDir(string $fixture): string
    {
        $src = __DIR__ . "/../../fixtures/2x-to-3x/{$fixture}/input";
        $dst = sys_get_temp_dir() . '/elgg-migrate-' . uniqid();
        mkdir($dst, 0755, true);

        foreach (new \DirectoryIterator($src) as $f) {
            if ($f->isDot()) continue;
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
