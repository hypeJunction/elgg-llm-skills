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

    /**
     * Regression test for the silent-failure bug where the rule rewrote
     * the parameter signature but left `switch ($event)` and
     * `$event === 'create'` body usages pointing at the new \Elgg\Event
     * object instead of the old string parameter, so the case never
     * matched and the comparison was always false.
     */
    public function testApplyTranslatesEventStringContextUsages(): void
    {
        $workDir = $this->makeStringContextWorkDir('event');

        try {
            $this->rule->apply($workDir);
            $code = file_get_contents($workDir . '/classes/MyPlugin/StringContext.php');

            // Signature rewritten as expected
            $this->assertStringContainsString(
                'function onChange(\Elgg\Event $event)',
                $code
            );

            // switch ($event) → switch ($event->getName())
            $this->assertStringContainsString('switch ($event->getName())', $code);
            $this->assertStringNotContainsString('switch ($event)', $code);

            // String comparisons translated
            $this->assertStringContainsString(
                "\$event->getName() === 'create'",
                $code
            );
            $this->assertStringContainsString(
                "\$event->getName() !== 'unrelated'",
                $code
            );

            // Object-context usage we just inserted is preserved
            $this->assertStringContainsString('$event->getObject()', $code);

            $this->assertPhpSyntaxValid($workDir . '/classes/MyPlugin/StringContext.php');
        } finally {
            $this->removeDir($workDir);
        }
    }

    /**
     * Regression test for the equivalent bug on hook handlers: the rule
     * left `switch ($hook)` body usages pointing at the new \Elgg\Hook
     * object instead of the old string hook-name parameter.
     */
    public function testApplyTranslatesHookStringContextUsages(): void
    {
        $workDir = $this->makeStringContextWorkDir('hook');

        try {
            $this->rule->apply($workDir);
            $code = file_get_contents($workDir . '/classes/MyPlugin/StringContext.php');

            $this->assertStringContainsString(
                'function dispatch(\Elgg\Hook $hook)',
                $code
            );
            $this->assertStringContainsString('switch ($hook->getName())', $code);
            $this->assertStringNotContainsString('switch ($hook)', $code);
            $this->assertStringContainsString(
                "\$hook->getName() === 'view_vars'",
                $code
            );
            $this->assertStringContainsString('$hook->getValue()', $code);

            $this->assertPhpSyntaxValid($workDir . '/classes/MyPlugin/StringContext.php');
        } finally {
            $this->removeDir($workDir);
        }
    }

    private function makeStringContextWorkDir(string $kind): string
    {
        $dst = sys_get_temp_dir() . '/elgg-migrate-' . uniqid();
        mkdir($dst . '/classes/MyPlugin', 0755, true);

        if ($kind === 'event') {
            $classCode = <<<'PHP'
<?php
namespace MyPlugin;
class StringContext {
    public static function onChange($event, $type, $entity) {
        switch ($event) {
            case 'create':
                error_log("create: {$entity->guid}");
                break;
            case 'update':
                error_log("update: {$entity->guid}");
                break;
        }
        if ($event === 'create' && $type === 'object') {
            error_log('first create');
        }
        if ($event !== 'unrelated') {
            error_log('not unrelated');
        }
    }
}
PHP;
            $pluginPhp = "<?php\nreturn [\n\t'events' => [\n\t\t'create' => [\n\t\t\t'all' => [\n\t\t\t\t\\MyPlugin\\StringContext::class . '::onChange' => [],\n\t\t\t],\n\t\t],\n\t],\n];\n";
        } else {
            $classCode = <<<'PHP'
<?php
namespace MyPlugin;
class StringContext {
    public static function dispatch($hook, $type, $return, $params) {
        switch ($hook) {
            case 'view_vars':
                $return['extra'] = 'a';
                break;
            case 'head':
                $return['title'] = 'b';
                break;
        }
        if ($hook === 'view_vars') {
            $return['flag'] = true;
        }
        return $return;
    }
}
PHP;
            $pluginPhp = "<?php\nreturn [\n\t'hooks' => [\n\t\t'view_vars' => [\n\t\t\t'output/url' => [\n\t\t\t\t\\MyPlugin\\StringContext::class . '::dispatch' => [],\n\t\t\t],\n\t\t],\n\t],\n];\n";
        }

        file_put_contents($dst . '/classes/MyPlugin/StringContext.php', $classCode);
        file_put_contents($dst . '/elgg-plugin.php', $pluginPhp);
        return $dst;
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
