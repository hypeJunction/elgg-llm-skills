<?php

declare(strict_types=1);

namespace ElggMigrate\Tests\Rules\V3ToV4;

use ElggMigrate\Rules\V3ToV4\HookCallbackSignatures;
use PHPUnit\Framework\TestCase;

final class HookCallbackSignaturesTest extends TestCase
{
    private HookCallbackSignatures $rule;

    protected function setUp(): void
    {
        $this->rule = new HookCallbackSignatures();
    }

    public function testAnalyzeFindsOldSignatures(): void
    {
        $dir = __DIR__ . '/../../fixtures/3x-to-4x/hook-callback-signatures/input';
        $analysis = $this->rule->analyze($dir);

        $this->assertTrue($analysis->applicable);
        // entityMenu, filterUrlVars (hooks), onCreate (event) = 3
        $this->assertCount(3, $analysis->findings);
    }

    public function testApplyRewritesHookSignatures(): void
    {
        $workDir = $this->makeWorkDir();

        try {
            $result = $this->rule->apply($workDir);
            $this->assertTrue($result->success);
            $this->assertNotEmpty($result->changes);

            // Check Hooks.php was rewritten
            $hooksCode = file_get_contents($workDir . '/classes/MyPlugin/Hooks.php');

            // Signature should now be \Elgg\Hook $hook
            $this->assertStringContainsString('function entityMenu(\Elgg\Hook $hook)', $hooksCode);
            $this->assertStringContainsString('function filterUrlVars(\Elgg\Hook $hook)', $hooksCode);

            // Old params should be replaced
            $this->assertStringNotContainsString('$params', $hooksCode);
            $this->assertStringNotContainsString('$type', $hooksCode);

            // $hook->getParam() should be used
            $this->assertStringContainsString("\$hook->getParam('entity')", $hooksCode);
            $this->assertStringContainsString("\$hook->getParam('href')", $hooksCode);
            $this->assertStringContainsString("\$hook->getParam('is_trusted'", $hooksCode);

            // $return should be initialized from getValue() since it's modified
            $this->assertStringContainsString('$hook->getValue()', $hooksCode);

            // Syntax check
            $this->assertPhpSyntaxValid($workDir . '/classes/MyPlugin/Hooks.php');
        } finally {
            $this->removeDir($workDir);
        }
    }

    public function testApplyRewritesEventSignatures(): void
    {
        $workDir = $this->makeWorkDir();

        try {
            $this->rule->apply($workDir);

            $eventsCode = file_get_contents($workDir . '/classes/MyPlugin/Events.php');

            // Signature should now be \Elgg\Event $event
            $this->assertStringContainsString('function onCreate(\Elgg\Event $event)', $eventsCode);

            // $entity replaced with $event->getObject()
            $this->assertStringContainsString('$event->getObject()', $eventsCode);

            // $type replaced with $event->getType()
            $this->assertStringContainsString('$event->getType()', $eventsCode);

            // Syntax check
            $this->assertPhpSyntaxValid($workDir . '/classes/MyPlugin/Events.php');
        } finally {
            $this->removeDir($workDir);
        }
    }

    public function testApplyIsIdempotent(): void
    {
        $workDir = $this->makeWorkDir();

        try {
            $this->rule->apply($workDir);
            // Second run should find nothing
            $analysis = $this->rule->analyze($workDir);
            $this->assertFalse($analysis->applicable, 'Should be clean after first apply');
        } finally {
            $this->removeDir($workDir);
        }
    }

    public function testAnalyzeSkipsWhenNoElggPluginPhp(): void
    {
        $workDir = sys_get_temp_dir() . '/elgg-migrate-' . uniqid();
        mkdir($workDir, 0755, true);
        file_put_contents($workDir . '/test.php', "<?php\nfunction foo(\$a, \$b) {}\n");

        try {
            $analysis = $this->rule->analyze($workDir);
            $this->assertFalse($analysis->applicable);
        } finally {
            $this->removeDir($workDir);
        }
    }

    private function assertPhpSyntaxValid(string $file): void
    {
        $output = [];
        exec("php -l " . escapeshellarg($file) . " 2>&1", $output, $exitCode);
        $this->assertSame(0, $exitCode, "Syntax error in {$file}: " . implode("\n", $output));
    }

    private function makeWorkDir(): string
    {
        $src = __DIR__ . '/../../fixtures/3x-to-4x/hook-callback-signatures/input';
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
