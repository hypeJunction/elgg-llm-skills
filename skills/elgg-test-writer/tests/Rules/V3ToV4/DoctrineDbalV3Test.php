<?php

declare(strict_types=1);

namespace ElggMigrate\Tests\Rules\V3ToV4;

use ElggMigrate\Rules\V3ToV4\DoctrineDbalV3;
use PHPUnit\Framework\TestCase;

final class DoctrineDbalV3Test extends TestCase
{
    private DoctrineDbalV3 $rule;

    protected function setUp(): void
    {
        $this->rule = new DoctrineDbalV3();
    }

    public function testAnalyzeFlagsDbalMethodCalls(): void
    {
        $dir = __DIR__ . '/../../fixtures/3x-to-4x/doctrine-dbal-v3/input';
        $analysis = $this->rule->analyze($dir);

        $this->assertTrue($analysis->applicable);
        // fetch x1, fetchAll x1, fetchColumn x2
        $this->assertCount(4, $analysis->findings);
    }

    public function testAnalysisFindingDescriptionsContainAdvice(): void
    {
        $dir = __DIR__ . '/../../fixtures/3x-to-4x/doctrine-dbal-v3/input';
        $analysis = $this->rule->analyze($dir);

        $descriptions = array_map(fn($f) => $f->description, $analysis->findings);

        $hasFetchWarning = (bool) array_filter($descriptions, fn($d) => str_contains($d, 'array'));
        $this->assertTrue($hasFetchWarning, 'Expected fetch() warning about array return type');

        $hasFetchColumnWarning = (bool) array_filter($descriptions, fn($d) => str_contains($d, 'fetchOne'));
        $this->assertTrue($hasFetchColumnWarning, 'Expected fetchColumn() rename advice');
    }

    public function testApplyEmitsWarningsAndNoFileChanges(): void
    {
        $dir = __DIR__ . '/../../fixtures/3x-to-4x/doctrine-dbal-v3/input';
        $result = $this->rule->apply($dir);

        $this->assertTrue($result->success);
        $this->assertEmpty($result->changes);
        $this->assertNotEmpty($result->warnings);
    }

    public function testAnalyzeSkipsFilesWithoutDbalMarker(): void
    {
        $workDir = sys_get_temp_dir() . '/elgg-migrate-' . uniqid();
        mkdir($workDir, 0755, true);
        // fetch() on a non-DBAL object — should be ignored
        file_put_contents($workDir . '/plain.php', <<<'PHP'
<?php
$result = $cursor->fetch();
$row = $pdo->fetchAll();
PHP);

        try {
            $analysis = $this->rule->analyze($workDir);
            $this->assertFalse($analysis->applicable);
        } finally {
            $this->removeDir($workDir);
        }
    }

    public function testAnalyzeNotApplicableWhenNoDbalMethods(): void
    {
        $workDir = sys_get_temp_dir() . '/elgg-migrate-' . uniqid();
        mkdir($workDir, 0755, true);
        file_put_contents($workDir . '/repo.php', <<<'PHP'
<?php
use Elgg\Database\QueryBuilder;
class Repo {
    public function count(QueryBuilder $qb): int {
        return (int) $qb->execute();
    }
}
PHP);

        try {
            $analysis = $this->rule->analyze($workDir);
            $this->assertFalse($analysis->applicable);
        } finally {
            $this->removeDir($workDir);
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
