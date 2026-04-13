<?php

declare(strict_types=1);

namespace ElggMigrate\Tests\Rules\V2ToV3;

use ElggMigrate\Rules\V2ToV3\SubtableJoinWarning;
use PHPUnit\Framework\TestCase;

final class SubtableJoinWarningTest extends TestCase
{
    private SubtableJoinWarning $rule;

    protected function setUp(): void
    {
        $this->rule = new SubtableJoinWarning();
    }

    public function testAnalyzeFindsSubtableReferences(): void
    {
        $dir = __DIR__ . '/../../fixtures/2x-to-3x/subtable-join-warning/input';
        $analysis = $this->rule->analyze($dir);

        $this->assertTrue($analysis->applicable);
        // users_entity, objects_entity, groups_entity = 3
        $this->assertGreaterThanOrEqual(3, count($analysis->findings));
    }

    public function testApplyAddsWarningComments(): void
    {
        $workDir = $this->makeWorkDir('subtable-join-warning');

        try {
            $result = $this->rule->apply($workDir);

            $this->assertTrue($result->success);

            $code = file_get_contents($workDir . '/query.php');

            // Warning comments should be added
            $this->assertStringContainsString('// WARNING: users_entity subtable removed in Elgg 3.0', $code);
            $this->assertStringContainsString('// WARNING: objects_entity subtable removed in Elgg 3.0', $code);
            $this->assertStringContainsString('// WARNING: groups_entity subtable removed in Elgg 3.0', $code);

            // Original SQL lines should still be there
            $this->assertStringContainsString('users_entity', $code);
            $this->assertStringContainsString('objects_entity', $code);
        } finally {
            $this->removeDir($workDir);
        }
    }

    public function testApplyIsWarnOnly(): void
    {
        $workDir = $this->makeWorkDir('subtable-join-warning');

        try {
            $this->rule->apply($workDir);
            // Warn-only rule: the subtable references remain in the code
            // (they need manual rewriting), so re-analyze still finds them
            $reAnalysis = $this->rule->analyze($workDir);
            $this->assertTrue($reAnalysis->applicable);
        } finally {
            $this->removeDir($workDir);
        }
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
