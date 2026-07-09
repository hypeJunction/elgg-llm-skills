<?php

declare(strict_types=1);

namespace ElggMigrate\Tests\Rules\V3ToV4;

use ElggMigrate\Rules\V3ToV4\NotificationSubscriptionRename;
use PHPUnit\Framework\TestCase;

final class NotificationSubscriptionRenameTest extends TestCase
{
    private NotificationSubscriptionRename $rule;

    protected function setUp(): void
    {
        $this->rule = new NotificationSubscriptionRename();
    }

    public function testAnalyzeFindsSubscriptionNames(): void
    {
        $dir = __DIR__ . '/../../fixtures/3x-to-4x/notification-subscription-rename/input';
        $analysis = $this->rule->analyze($dir);

        $this->assertTrue($analysis->applicable);
        $this->assertCount(3, $analysis->findings);
    }

    public function testApplyProducesExpectedOutput(): void
    {
        $inputDir = __DIR__ . '/../../fixtures/3x-to-4x/notification-subscription-rename/input';
        $expectedDir = __DIR__ . '/../../fixtures/3x-to-4x/notification-subscription-rename/expected';

        $workDir = sys_get_temp_dir() . '/elgg-migrate-' . uniqid();
        mkdir($workDir, 0755, true);
        copy($inputDir . '/code.php', $workDir . '/code.php');

        try {
            $result = $this->rule->apply($workDir);

            $this->assertTrue($result->success);
            $this->assertCount(1, $result->changes);

            $actual = file_get_contents($workDir . '/code.php');
            $expected = file_get_contents($expectedDir . '/code.php');
            $this->assertSame($expected, $actual);
        } finally {
            $this->removeDir($workDir);
        }
    }

    public function testCleanFilesAreNotModified(): void
    {
        $workDir = sys_get_temp_dir() . '/elgg-migrate-' . uniqid();
        mkdir($workDir, 0755, true);
        $content = "<?php\nadd_entity_relationship(\$user_guid, 'notify:email', \$target_guid);\n";
        file_put_contents($workDir . '/clean.php', $content);

        try {
            $analysis = $this->rule->analyze($workDir);
            $this->assertFalse($analysis->applicable);

            $result = $this->rule->apply($workDir);
            $this->assertTrue($result->success);
            $this->assertEmpty($result->changes);
            $this->assertSame($content, file_get_contents($workDir . '/clean.php'));
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
