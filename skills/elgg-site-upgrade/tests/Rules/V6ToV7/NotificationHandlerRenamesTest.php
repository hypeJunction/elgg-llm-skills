<?php

declare(strict_types=1);

namespace ElggMigrate\Tests\Rules\V6ToV7;

use ElggMigrate\Rules\V6ToV7\NotificationHandlerRenames;
use PHPUnit\Framework\TestCase;

final class NotificationHandlerRenamesTest extends TestCase
{
    private NotificationHandlerRenames $rule;

    protected function setUp(): void
    {
        $this->rule = new NotificationHandlerRenames();
    }

    public function testIdAndAutomatable(): void
    {
        $this->assertSame('notification-handler-renames-7x', $this->rule->getId());
        $this->assertTrue($this->rule->canAutomate());
    }

    public function testRewritesUseStatementAndClassConstAndRegistration(): void
    {
        $dir = $this->makeDir([
            // use-statement + ::class reference
            'classes/Bootstrap.php' => "<?php\nuse Elgg\\Notifications\\CreateCommentEventHandler;\nuse Elgg\\Notifications\\CreateContentEventHandler;\n\$h = CreateCommentEventHandler::class;\n",
            // string-literal registration in elgg-plugin.php + FQN with leading backslash
            'elgg-plugin.php' => "<?php\nreturn ['events' => ['x' => ['y' => [\\Elgg\\Notifications\\MentionsEventHandler::class => []]]]];\n",
        ]);

        try {
            $this->assertTrue($this->rule->analyze($dir)->applicable);
            $this->rule->apply($dir);

            $boot = file_get_contents($dir . '/classes/Bootstrap.php');
            $this->assertStringContainsString('use Elgg\\Notifications\\Handlers\\CreateComment;', $boot);
            $this->assertStringContainsString('use Elgg\\Notifications\\Events\\CreateContent;', $boot);
            // old FQNs gone
            $this->assertStringNotContainsString('CreateCommentEventHandler', $boot);
            $this->assertStringNotContainsString('CreateContentEventHandler', $boot);

            $plugin = file_get_contents($dir . '/elgg-plugin.php');
            $this->assertStringContainsString('Elgg\\Notifications\\Handlers\\Mentions::class', $plugin);
            $this->assertStringNotContainsString('MentionsEventHandler', $plugin);
        } finally {
            $this->removeDir($dir);
        }
    }

    public function testDoesNotRewriteAlreadyMigratedOrUnrelated(): void
    {
        $dir = $this->makeDir([
            'classes/A.php' => "<?php\nuse Elgg\\Notifications\\Handlers\\CreateComment;\nuse Elgg\\Notifications\\NotificationEvent;\n",
        ]);
        try {
            $this->assertFalse($this->rule->analyze($dir)->applicable);
            $this->assertEmpty($this->rule->apply($dir)->changes);
        } finally {
            $this->removeDir($dir);
        }
    }

    private function makeDir(array $files): string
    {
        $dir = sys_get_temp_dir() . '/elgg-migrate-nhr-' . uniqid();
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
