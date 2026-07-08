<?php

declare(strict_types=1);

namespace ElggMigrate\Tests\Rules\V6ToV7;

use ElggMigrate\Rules\V6ToV7\RemovedFunctions;
use PHPUnit\Framework\TestCase;

final class RemovedFunctionsTest extends TestCase
{
    private RemovedFunctions $rule;

    protected function setUp(): void
    {
        $this->rule = new RemovedFunctions();
    }

    public function testId(): void
    {
        $this->assertSame('removed-functions-7x', $this->rule->getId());
    }

    public function testRewritesLoggedInUser(): void
    {
        $dir = $this->makeDir([
            'classes/A.php' => "<?php\nfunction who() { return elgg_get_logged_in_user(); }\n",
        ]);
        try {
            $this->assertTrue($this->rule->analyze($dir)->applicable);
            $this->rule->apply($dir);
            $out = file_get_contents($dir . '/classes/A.php');
            $this->assertStringContainsString('elgg_get_logged_in_user_entity(', $out);
            // The _guid variant (different function) must be untouched by the rename.
            $this->assertStringNotContainsString('elgg_get_logged_in_user_entity_guid', $out);
        } finally {
            $this->removeDir($dir);
        }
    }

    public function testDoesNotTouchGuidVariant(): void
    {
        $dir = $this->makeDir([
            'classes/B.php' => "<?php\nfunction g() { return elgg_get_logged_in_user_guid(); }\n",
        ]);
        try {
            $this->assertFalse($this->rule->analyze($dir)->applicable);
        } finally {
            $this->removeDir($dir);
        }
    }

    private function makeDir(array $files): string
    {
        $dir = sys_get_temp_dir() . '/elgg-migrate-rf7-' . uniqid();
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
