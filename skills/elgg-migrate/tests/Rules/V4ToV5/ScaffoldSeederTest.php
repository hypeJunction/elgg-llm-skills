<?php

declare(strict_types=1);

namespace ElggMigrate\Tests\Rules\V4ToV5;

use ElggMigrate\Rules\V4ToV5\ScaffoldSeeder;
use PHPUnit\Framework\TestCase;

final class ScaffoldSeederTest extends TestCase
{
    private ScaffoldSeeder $rule;

    protected function setUp(): void
    {
        $this->rule = new ScaffoldSeeder();
    }

    // -------------------------------------------------------------------------
    // Metadata
    // -------------------------------------------------------------------------

    public function testId(): void
    {
        $this->assertSame('scaffold-seeder', $this->rule->getId());
    }

    public function testCanAutomate(): void
    {
        $this->assertTrue($this->rule->canAutomate());
    }

    // -------------------------------------------------------------------------
    // Detection from elgg-plugin.php entities array
    // -------------------------------------------------------------------------

    public function testAnalyzeDetectsEntitiesFromPluginPhp(): void
    {
        $dir = $this->fixture('with-entities');
        $analysis = $this->rule->analyze($dir);

        $this->assertTrue($analysis->applicable, 'Rule should be applicable when entities are declared');
        $this->assertGreaterThanOrEqual(2, count($analysis->findings));

        $descriptions = array_map(fn($f) => $f->description, $analysis->findings);
        $this->assertTrue(
            (bool) array_filter($descriptions, fn($d) => str_contains($d, 'tracker_item')),
            'Should detect tracker_item subtype',
        );
        $this->assertTrue(
            (bool) array_filter($descriptions, fn($d) => str_contains($d, 'tracker_list')),
            'Should detect tracker_list subtype',
        );
    }

    public function testAnalyzeNotApplicableWhenNoEntities(): void
    {
        $dir = $this->fixture('no-entities');
        $analysis = $this->rule->analyze($dir);

        $this->assertFalse($analysis->applicable);
        $this->assertEmpty($analysis->findings);
        $this->assertStringContainsString('not required', $analysis->summary);
    }

    // -------------------------------------------------------------------------
    // Detection from add_subtype() calls
    // -------------------------------------------------------------------------

    public function testAnalyzeDetectsAddSubtypeCalls(): void
    {
        $dir = $this->fixture('with-add-subtype');
        $analysis = $this->rule->analyze($dir);

        $this->assertTrue($analysis->applicable, 'Rule should be applicable when add_subtype is found');

        $descriptions = array_map(fn($f) => $f->description, $analysis->findings);
        $this->assertTrue(
            (bool) array_filter($descriptions, fn($d) => str_contains($d, 'legacy_item')),
            'Should detect legacy_item from add_subtype()',
        );
        $this->assertTrue(
            (bool) array_filter($descriptions, fn($d) => str_contains($d, 'legacy_list')),
            'Should detect legacy_list from update_subtype()',
        );
    }

    // -------------------------------------------------------------------------
    // Scaffold file generation
    // -------------------------------------------------------------------------

    public function testApplyCreatesSeederFile(): void
    {
        $workDir = $this->copyFixture('with-entities');

        try {
            $result = $this->rule->apply($workDir);

            $this->assertTrue($result->success);

            // Assert Seeder.php appears in FileChange output
            $createdFiles = array_map(fn($c) => $c->file, $result->changes);
            $seederChange = array_filter($createdFiles, fn($f) => str_ends_with($f, 'Seeder.php'));
            $this->assertNotEmpty($seederChange, 'Seeder.php must appear in FileChange output');

            // Assert the physical file exists
            $seederPath = $workDir . '/' . array_values($seederChange)[0];
            $this->assertFileExists($seederPath);

            // Assert it extends Seed
            $content = file_get_contents($seederPath);
            $this->assertStringContainsString('extends Seed', $content);
            $this->assertStringContainsString('use Elgg\Database\Seeds\Seed;', $content);
        } finally {
            $this->removeDir($workDir);
        }
    }

    public function testApplySeederClassHasRequiredMethods(): void
    {
        $workDir = $this->copyFixture('with-entities');

        try {
            $this->rule->apply($workDir);

            $seederPath = $this->findSeederPath($workDir);
            $this->assertNotNull($seederPath, 'Seeder.php must have been created');

            $content = file_get_contents($seederPath);

            $this->assertStringContainsString('public static function getType(): string', $content);
            $this->assertStringContainsString('protected function getCountOptions(): array', $content);
            $this->assertStringContainsString('public function seed(): void', $content);
            $this->assertStringContainsString('public function unseed(): void', $content);
            $this->assertStringContainsString('public static function addSeed(Event $event): array', $content);
        } finally {
            $this->removeDir($workDir);
        }
    }

    public function testApplySeederContainsOwnedSubtypes(): void
    {
        $workDir = $this->copyFixture('with-entities');

        try {
            $this->rule->apply($workDir);

            $seederPath = $this->findSeederPath($workDir);
            $this->assertNotNull($seederPath);

            $content = file_get_contents($seederPath);
            $this->assertStringContainsString('tracker_item', $content);
            $this->assertStringContainsString('tracker_list', $content);
        } finally {
            $this->removeDir($workDir);
        }
    }

    public function testApplySeederGetTypeReturnsPluginId(): void
    {
        $workDir = $this->copyFixture('with-entities');

        try {
            $this->rule->apply($workDir);

            $seederPath = $this->findSeederPath($workDir);
            $this->assertNotNull($seederPath);

            $content = file_get_contents($seederPath);
            // Plugin id from elgg-plugin.php is 'tracker'
            $this->assertStringContainsString("return 'tracker'", $content);
        } finally {
            $this->removeDir($workDir);
        }
    }

    public function testApplyGeneratesValidPhp(): void
    {
        $workDir = $this->copyFixture('with-entities');

        try {
            $this->rule->apply($workDir);

            $seederPath = $this->findSeederPath($workDir);
            $this->assertNotNull($seederPath);

            exec("php -l {$seederPath} 2>&1", $output, $exitCode);
            $this->assertSame(0, $exitCode, 'Generated Seeder.php must be valid PHP: ' . implode("\n", $output));
        } finally {
            $this->removeDir($workDir);
        }
    }

    // -------------------------------------------------------------------------
    // Bootstrap registration insertion
    // -------------------------------------------------------------------------

    public function testApplyInjectsBootstrapRegistration(): void
    {
        $workDir = $this->copyFixture('with-entities');

        try {
            $result = $this->rule->apply($workDir);

            $modifiedFiles = array_map(fn($c) => $c->file, $result->changes);
            $bootstrapChange = array_filter($modifiedFiles, fn($f) => $f === 'Bootstrap.php');
            $this->assertNotEmpty($bootstrapChange, 'Bootstrap.php should appear in changes');

            $bootstrapContent = file_get_contents($workDir . '/Bootstrap.php');
            $this->assertStringContainsString('seeds', $bootstrapContent);
            $this->assertStringContainsString('database', $bootstrapContent);
            $this->assertStringContainsString('Seeder', $bootstrapContent);
            $this->assertStringContainsString('addSeed', $bootstrapContent);
        } finally {
            $this->removeDir($workDir);
        }
    }

    public function testApplySkipsBootstrapInjectionWhenAlreadyRegistered(): void
    {
        $workDir = $this->copyFixture('with-entities');

        try {
            // Apply twice — second run should not duplicate the registration
            $this->rule->apply($workDir);
            $afterFirst = file_get_contents($workDir . '/Bootstrap.php');

            $this->rule->apply($workDir); // second apply — Seeder exists now, skipped
            $afterSecond = file_get_contents($workDir . '/Bootstrap.php');

            $this->assertSame($afterFirst, $afterSecond, 'Bootstrap.php should not be modified on second apply');
        } finally {
            $this->removeDir($workDir);
        }
    }

    // -------------------------------------------------------------------------
    // Idempotency
    // -------------------------------------------------------------------------

    public function testApplyIsIdempotent(): void
    {
        $workDir = $this->copyFixture('with-entities');

        try {
            $result1 = $this->rule->apply($workDir);
            $this->assertTrue($result1->success);

            // Second apply — Seeder already exists, should be a no-op
            $result2 = $this->rule->apply($workDir);
            $this->assertTrue($result2->success);
            $this->assertEmpty($result2->changes, 'Second apply should produce no changes');
        } finally {
            $this->removeDir($workDir);
        }
    }

    // -------------------------------------------------------------------------
    // No entities fixture
    // -------------------------------------------------------------------------

    public function testApplyNoOpWhenNoEntitiesOwned(): void
    {
        $dir = $this->fixture('no-entities');
        $result = $this->rule->apply($dir);

        $this->assertTrue($result->success);
        $this->assertEmpty($result->changes);
        $this->assertNotEmpty($result->warnings);
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    private function fixture(string $name): string
    {
        return __DIR__ . '/../../fixtures/4x-to-5x/scaffold-seeder/' . $name;
    }

    private function copyFixture(string $name): string
    {
        $src = $this->fixture($name);
        $dst = sys_get_temp_dir() . '/elgg-migrate-scaffold-seeder-' . uniqid();
        $this->copyDir($src, $dst);
        return $dst;
    }

    private function copyDir(string $src, string $dst): void
    {
        mkdir($dst, 0755, true);

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($src, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::SELF_FIRST,
        );

        foreach ($iterator as $item) {
            /** @var \SplFileInfo $item */
            $target = $dst . '/' . $iterator->getSubPathname();
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

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );

        foreach ($iterator as $item) {
            /** @var \SplFileInfo $item */
            $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
        }

        rmdir($dir);
    }

    /**
     * Find the generated Seeder.php in a plugin directory (under classes/).
     */
    private function findSeederPath(string $pluginDir): ?string
    {
        $classesDir = $pluginDir . '/classes';
        if (!is_dir($classesDir)) return null;

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($classesDir, \FilesystemIterator::SKIP_DOTS),
        );

        foreach ($iterator as $file) {
            /** @var \SplFileInfo $file */
            if ($file->getBasename() === 'Seeder.php') {
                return $file->getPathname();
            }
        }

        return null;
    }
}
