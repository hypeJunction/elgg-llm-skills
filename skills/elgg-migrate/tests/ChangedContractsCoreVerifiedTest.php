<?php

declare(strict_types=1);

namespace ElggMigrate\Tests;

use ElggMigrate\PostMigrationVerifier;
use PHPUnit\Framework\TestCase;

/**
 * The 5.x/7.x class-contract blocks added after the 2026-07-08 ReflectionClass
 * core sweep (jh4nd): Batch/Event became non-interfaces at 5.x, ElggObject
 * became abstract at 7.x.
 */
final class ChangedContractsCoreVerifiedTest extends TestCase
{
    private PostMigrationVerifier $verifier;

    protected function setUp(): void
    {
        $this->verifier = new PostMigrationVerifier();
    }

    public function testImplementsBatchFlagsAt5x(): void
    {
        // Batch is an abstract class from 5.x (was interface at 4.x).
        $this->assertFlagged('5.x', "<?php\nclass MyUpgrade implements \\Elgg\\Upgrade\\Batch {}\n");
        // still flagged at 6.x (cumulative)
        $this->assertFlagged('6.x', "<?php\nclass MyUpgrade implements \\Elgg\\Upgrade\\Batch {}\n");
        // NOT flagged at 4.x (still an interface there)
        $this->assertNotFlagged('4.x', "<?php\nclass MyUpgrade implements \\Elgg\\Upgrade\\Batch {}\n");
    }

    public function testImplementsEventFlagsAt5x(): void
    {
        $this->assertFlagged('5.x', "<?php\nclass MyEvent implements \\Elgg\\Event {}\n");
    }

    public function testNewElggObjectFlagsAt7x(): void
    {
        // new \ElggObject() fatals at 7.x (abstract); fine at 6.x.
        $this->assertFlagged('7.x', "<?php\n\$o = new \\ElggObject();\n");
        $this->assertNotFlagged('6.x', "<?php\n\$o = new \\ElggObject();\n");
        // extending it is legal (only `new` is illegal) — must NOT flag.
        $this->assertNotFlagged('7.x', "<?php\nclass MyThing extends \\ElggObject {}\n");
        // the documented replacement must NOT flag.
        $this->assertNotFlagged('7.x', "<?php\n\$o = new \\ElggUndefinedObject();\n");
    }

    /**
     * \Elgg\Hook was REMOVED in 6.x, so ANY reference to it is illegal — not only the
     * `use Elgg\Hook;` import that its illegal_keyword names. A fully-qualified inline
     * type hint carries no import and slipped through the gate entirely (bd od8jc).
     */
    public function testRemovedClassFlaggedViaUseImport(): void
    {
        $this->assertFlagged('6.x', "<?php\nuse Elgg\\Hook;\nfunction h(Hook $hook) {}\n");
    }

    public function testRemovedClassFlaggedViaFullyQualifiedTypeHint(): void
    {
        // No import at all — the shape the old gate missed.
        $this->assertFlagged('6.x', "<?php\nfunction h(\\Elgg\\Hook $hook) {}\n");
    }

    public function testRemovedClassFlaggedViaFullyQualifiedNewAndStatic(): void
    {
        $this->assertFlagged('6.x', "<?php\n\$h = new \\Elgg\\Hook();\n");
        $this->assertFlagged('6.x', "<?php\n\$n = \\Elgg\\Hook::class;\n");
    }

    public function testRemovedClassNotFlaggedInComments(): void
    {
        // A docblock mentioning the old type is not code; flagging it is noise.
        $this->assertNotFlagged('6.x', "<?php\n/**\n * @param \\Elgg\\Hook \$hook legacy\n */\nfunction h(\\Elgg\\Event \$e) {}\n");
    }

    public function testRemovedClassDoesNotFlagASimilarlyNamedClass(): void
    {
        $this->assertNotFlagged('6.x', "<?php\nfunction h(\\Elgg\\HookHandler \$h) {}\n");
    }

    private function assertFlagged(string $target, string $code): void
    {
        $dir = $this->dir($code);
        try {
            $cats = array_map(fn($v) => $v->category, $this->verifier->verify($dir, $target)->violations);
            $this->assertContains('changed-class-contract', $cats, "expected contract flag at $target");
        } finally {
            $this->rm($dir);
        }
    }

    private function assertNotFlagged(string $target, string $code): void
    {
        $dir = $this->dir($code);
        try {
            $cats = array_map(fn($v) => $v->category, $this->verifier->verify($dir, $target)->violations);
            $this->assertNotContains('changed-class-contract', $cats, "unexpected contract flag at $target");
        } finally {
            $this->rm($dir);
        }
    }

    private function dir(string $code): string
    {
        $d = sys_get_temp_dir() . '/elgg-migrate-cc-' . uniqid();
        mkdir($d . '/classes', 0755, true);
        file_put_contents($d . '/elgg-plugin.php', "<?php\nreturn [];\n");
        file_put_contents($d . '/classes/X.php', $code);
        return $d;
    }

    private function rm(string $dir): void
    {
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST) as $f) {
            $f->isDir() ? rmdir($f->getPathname()) : unlink($f->getPathname());
        }
        rmdir($dir);
    }
}
