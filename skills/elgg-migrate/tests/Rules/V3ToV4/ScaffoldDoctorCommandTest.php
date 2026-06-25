<?php

declare(strict_types=1);

namespace ElggMigrate\Tests\Rules\V3ToV4;

use ElggMigrate\Rules\V3ToV4\ScaffoldDoctorCommand;
use PHPUnit\Framework\TestCase;

final class ScaffoldDoctorCommandTest extends TestCase
{
    private ScaffoldDoctorCommand $rule;

    protected function setUp(): void
    {
        $this->rule = new ScaffoldDoctorCommand();
    }

    // -------------------------------------------------------------------------
    // Metadata
    // -------------------------------------------------------------------------

    public function testId(): void
    {
        $this->assertSame('scaffold-doctor-command', $this->rule->getId());
    }

    public function testCanAutomate(): void
    {
        $this->assertTrue($this->rule->canAutomate());
    }

    // -------------------------------------------------------------------------
    // analyze() — applicability
    // -------------------------------------------------------------------------

    public function testAnalyzeApplicableWhenEntitiesOwned(): void
    {
        $dir = $this->fixture('with-entities');
        $analysis = $this->rule->analyze($dir);

        $this->assertTrue($analysis->applicable, 'Rule should be applicable when entities are declared');
        $this->assertGreaterThanOrEqual(2, count($analysis->findings));

        $descriptions = array_map(fn($f) => $f->description, $analysis->findings);
        $this->assertTrue(
            (bool) array_filter($descriptions, fn($d) => str_contains($d, 'note')),
            'Should report note subtype',
        );
        $this->assertTrue(
            (bool) array_filter($descriptions, fn($d) => str_contains($d, 'note_album')),
            'Should report note_album subtype',
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

    public function testAnalyzeNotApplicableWhenDoctorAlreadyExists(): void
    {
        $dir = $this->fixture('already-has-doctor');
        $analysis = $this->rule->analyze($dir);

        $this->assertFalse($analysis->applicable);
        $this->assertStringContainsString('already exists', $analysis->summary);
    }

    public function testAnalyzeNotApplicableWithoutPluginPhp(): void
    {
        $workDir = sys_get_temp_dir() . '/elgg-migrate-doctor-' . uniqid();
        mkdir($workDir, 0755, true);

        try {
            $analysis = $this->rule->analyze($workDir);
            $this->assertFalse($analysis->applicable);
        } finally {
            rmdir($workDir);
        }
    }

    // -------------------------------------------------------------------------
    // apply() — DoctorCommand.php generation
    // -------------------------------------------------------------------------

    public function testApplyCreatesDoctorCommandFile(): void
    {
        $workDir = $this->copyFixture('with-entities');

        try {
            $result = $this->rule->apply($workDir);

            $this->assertTrue($result->success);

            // Assert DoctorCommand.php appears in FileChange output
            $createdFiles = array_map(fn($c) => $c->file, $result->changes);
            $doctorChange = array_filter($createdFiles, fn($f) => str_ends_with($f, 'DoctorCommand.php'));
            $this->assertNotEmpty($doctorChange, 'DoctorCommand.php must appear in FileChange output');

            // Assert the physical file exists
            $doctorPath = $workDir . '/' . array_values($doctorChange)[0];
            $this->assertFileExists($doctorPath);
        } finally {
            $this->removeDir($workDir);
        }
    }

    public function testApplyDoctorCommandHasCorrectNamespace(): void
    {
        $workDir = $this->copyFixture('with-entities');

        try {
            $this->rule->apply($workDir);

            $doctorPath = $this->findDoctorCommandPath($workDir);
            $this->assertNotNull($doctorPath, 'DoctorCommand.php must have been created');

            $content = file_get_contents($doctorPath);
            $this->assertStringContainsString('namespace Notes\\Cli;', $content);
        } finally {
            $this->removeDir($workDir);
        }
    }

    public function testApplyDoctorCommandHasCorrectCommandName(): void
    {
        $workDir = $this->copyFixture('with-entities');

        try {
            $this->rule->apply($workDir);

            $doctorPath = $this->findDoctorCommandPath($workDir);
            $this->assertNotNull($doctorPath);

            $content = file_get_contents($doctorPath);
            // Plugin id is 'notes', so command name must be 'notes:doctor'
            $this->assertStringContainsString("'notes:doctor'", $content);
        } finally {
            $this->removeDir($workDir);
        }
    }

    public function testApplyDoctorCommandExtendsElggCommand(): void
    {
        $workDir = $this->copyFixture('with-entities');

        try {
            $this->rule->apply($workDir);

            $doctorPath = $this->findDoctorCommandPath($workDir);
            $this->assertNotNull($doctorPath);

            $content = file_get_contents($doctorPath);
            $this->assertStringContainsString('extends Command', $content);
            $this->assertStringContainsString('use Elgg\\Cli\\Command;', $content);
        } finally {
            $this->removeDir($workDir);
        }
    }

    public function testApplyDoctorCommandHasEntityCountChecks(): void
    {
        $workDir = $this->copyFixture('with-entities');

        try {
            $this->rule->apply($workDir);

            $doctorPath = $this->findDoctorCommandPath($workDir);
            $this->assertNotNull($doctorPath);

            $content = file_get_contents($doctorPath);

            // Should have count checks for both owned subtypes
            $this->assertStringContainsString("'subtype' => 'note'", $content);
            $this->assertStringContainsString("'subtype' => 'note_album'", $content);
            $this->assertStringContainsString("'count' => true", $content);
            $this->assertStringContainsString('elgg_get_entities', $content);
        } finally {
            $this->removeDir($workDir);
        }
    }

    public function testApplyDoctorCommandHasUpgradeAndOrphanStubs(): void
    {
        $workDir = $this->copyFixture('with-entities');

        try {
            $this->rule->apply($workDir);

            $doctorPath = $this->findDoctorCommandPath($workDir);
            $this->assertNotNull($doctorPath);

            $content = file_get_contents($doctorPath);
            $this->assertStringContainsString('Upgrade', $content, 'Should have upgrade check stub');
            $this->assertStringContainsString('relationship', $content, 'Should have orphan relationship check stub');
        } finally {
            $this->removeDir($workDir);
        }
    }

    public function testApplyGeneratesValidPhp(): void
    {
        $workDir = $this->copyFixture('with-entities');

        try {
            $this->rule->apply($workDir);

            $doctorPath = $this->findDoctorCommandPath($workDir);
            $this->assertNotNull($doctorPath);

            exec("php -l " . escapeshellarg($doctorPath) . " 2>&1", $output, $exitCode);
            $this->assertSame(
                0,
                $exitCode,
                'Generated DoctorCommand.php must be valid PHP: ' . implode("\n", $output),
            );
        } finally {
            $this->removeDir($workDir);
        }
    }

    // -------------------------------------------------------------------------
    // apply() — elgg-plugin.php registration
    // -------------------------------------------------------------------------

    public function testApplyRegistersCommandInPluginPhp(): void
    {
        $workDir = $this->copyFixture('with-entities');

        try {
            $result = $this->rule->apply($workDir);

            // elgg-plugin.php should be in changes
            $modifiedFiles = array_map(fn($c) => $c->file, $result->changes);
            $pluginPhpChange = array_filter($modifiedFiles, fn($f) => $f === 'elgg-plugin.php');
            $this->assertNotEmpty($pluginPhpChange, 'elgg-plugin.php should appear in changes');

            // Verify CLI registration is in the file
            $pluginPhpContent = file_get_contents($workDir . '/elgg-plugin.php');
            $this->assertStringContainsString("'cli'", $pluginPhpContent);
            $this->assertStringContainsString("'commands'", $pluginPhpContent);
            $this->assertStringContainsString('DoctorCommand::class', $pluginPhpContent);
        } finally {
            $this->removeDir($workDir);
        }
    }

    public function testApplyPluginPhpRemainsValidAfterRegistration(): void
    {
        $workDir = $this->copyFixture('with-entities');

        try {
            $this->rule->apply($workDir);

            $pluginPhpPath = $workDir . '/elgg-plugin.php';
            exec("php -l " . escapeshellarg($pluginPhpPath) . " 2>&1", $output, $exitCode);
            $this->assertSame(
                0,
                $exitCode,
                'elgg-plugin.php must remain valid PHP after registration: ' . implode("\n", $output),
            );
        } finally {
            $this->removeDir($workDir);
        }
    }

    // -------------------------------------------------------------------------
    // apply() — no-op cases
    // -------------------------------------------------------------------------

    public function testApplyNoOpWhenNoEntitiesOwned(): void
    {
        $dir = $this->fixture('no-entities');
        $result = $this->rule->apply($dir);

        $this->assertTrue($result->success);
        $this->assertEmpty($result->changes);
        $this->assertNotEmpty($result->warnings);
    }

    public function testApplySkipsWhenDoctorAlreadyExists(): void
    {
        $workDir = $this->copyFixture('already-has-doctor');

        try {
            $result = $this->rule->apply($workDir);

            $this->assertTrue($result->success);
            $this->assertEmpty($result->changes);
            $this->assertNotEmpty($result->warnings);
            $this->assertStringContainsString('already exists', implode(' ', $result->warnings));
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

            // Second apply — DoctorCommand exists now
            $result2 = $this->rule->apply($workDir);
            $this->assertTrue($result2->success);
            $this->assertEmpty($result2->changes, 'Second apply should produce no changes');
        } finally {
            $this->removeDir($workDir);
        }
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    private function fixture(string $name): string
    {
        return __DIR__ . '/../../fixtures/3x-to-4x/scaffold-doctor-command/' . $name;
    }

    private function copyFixture(string $name): string
    {
        $src = $this->fixture($name);
        $dst = sys_get_temp_dir() . '/elgg-migrate-doctor-' . uniqid();
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
     * Find the generated DoctorCommand.php in a plugin directory (under classes/).
     */
    private function findDoctorCommandPath(string $pluginDir): ?string
    {
        $classesDir = $pluginDir . '/classes';
        if (!is_dir($classesDir)) return null;

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($classesDir, \FilesystemIterator::SKIP_DOTS),
        );

        foreach ($iterator as $file) {
            /** @var \SplFileInfo $file */
            if ($file->getBasename() === 'DoctorCommand.php') {
                return $file->getPathname();
            }
        }

        return null;
    }
}
