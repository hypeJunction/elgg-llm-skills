<?php

declare(strict_types=1);

namespace ElggMigrate\Tests\Rules\V3ToV4;

use ElggMigrate\Rules\V3ToV4\EntityAttributeSetters;
use PHPUnit\Framework\TestCase;

final class EntityAttributeSettersTest extends TestCase
{
    private EntityAttributeSetters $rule;

    protected function setUp(): void
    {
        $this->rule = new EntityAttributeSetters();
    }

    public function testAnalyzeFindsProtectedAttributeAssignments(): void
    {
        $dir = __DIR__ . '/../../fixtures/3x-to-4x/entity-attribute-setters/input';
        $analysis = $this->rule->analyze($dir);

        $this->assertTrue($analysis->applicable);
        // subtype, type, enabled('yes'), enabled('no'), banned, admin = 6
        $this->assertCount(6, $analysis->findings);
    }

    public function testApplyTransformsSubtypeToSetter(): void
    {
        $workDir = $this->makeWorkDir();

        try {
            $result = $this->rule->apply($workDir);

            $this->assertTrue($result->success);
            $this->assertNotEmpty($result->changes);

            $content = file_get_contents($workDir . '/classes/MyPlugin/Setup.php');

            // subtype = 'my_item' → setSubtype('my_item')
            $this->assertStringContainsString('setSubtype(', $content);
            $this->assertStringNotContainsString("->subtype = ", $content);

            // enabled = 'yes' → enable()
            $this->assertStringContainsString('->enable()', $content);
            // enabled = 'no' → disable()
            $this->assertStringContainsString('->disable()', $content);
            $this->assertStringNotContainsString("->enabled = ", $content);

            // type, admin, banned remain unchanged (warn-only)
            $this->assertStringContainsString("->type = ", $content);
            $this->assertStringContainsString("->banned = ", $content);
            $this->assertStringContainsString("->admin = ", $content);

            // Verify valid PHP
            $output = [];
            exec("php -l " . escapeshellarg($workDir . '/classes/MyPlugin/Setup.php') . " 2>&1", $output, $exitCode);
            $this->assertSame(0, $exitCode, "File has syntax errors: " . implode("\n", $output));
        } finally {
            $this->removeDir($workDir);
        }
    }

    public function testApplyProducesWarningsForWarnOnlyAttributes(): void
    {
        $workDir = $this->makeWorkDir();

        try {
            $result = $this->rule->apply($workDir);

            // Should have warnings for type, admin, banned
            $this->assertNotEmpty($result->warnings);

            $warningText = implode("\n", $result->warnings);
            $this->assertStringContainsString('type', $warningText);
            $this->assertStringContainsString('admin', $warningText);
            $this->assertStringContainsString('banned', $warningText);
        } finally {
            $this->removeDir($workDir);
        }
    }

    public function testAnalyzeNotApplicableWhenNoProtectedAttributes(): void
    {
        $workDir = sys_get_temp_dir() . '/elgg-migrate-' . uniqid();
        mkdir($workDir, 0755, true);
        file_put_contents($workDir . '/test.php', "<?php\n\$entity->title = 'Hello';\n\$entity->description = 'World';\n");

        try {
            $analysis = $this->rule->analyze($workDir);
            $this->assertFalse($analysis->applicable);
        } finally {
            $this->removeDir($workDir);
        }
    }

    public function testApplyIsIdempotent(): void
    {
        $workDir = $this->makeWorkDir();

        try {
            $this->rule->apply($workDir);
            // After first apply, subtype and enabled are transformed.
            // Second analyze should only find type, admin, banned (warn-only, not transformed).
            $analysis = $this->rule->analyze($workDir);
            // Only type, admin, banned should remain (warn-only, not transformed)
            $remainingProps = array_map(fn($f) => $f->description, $analysis->findings);
            foreach ($remainingProps as $desc) {
                $this->assertMatchesRegularExpression('/type|admin|banned/', $desc, 'Only warn-only attributes should remain');
            }
        } finally {
            $this->removeDir($workDir);
        }
    }

    private function makeWorkDir(): string
    {
        $src = __DIR__ . '/../../fixtures/3x-to-4x/entity-attribute-setters/input';
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
