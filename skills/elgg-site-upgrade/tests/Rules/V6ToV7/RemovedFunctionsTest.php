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

    /**
     * The 7.x rename block is currently empty — elgg_get_logged_in_user was
     * core-verified as a 3.x removal (removed-functions.json) and now
     * auto-renames at 2x->3x (V2ToV3\RemovedFunctionRenames), and no other 7.x
     * removal is a plain 1:1 global rename. So the 6x->7x rename rule must be a
     * no-op that touches nothing (bd elgg-migrate-jfrc1). The rule stays wired
     * so a future 7.x 1:1 rename is a data-only edit.
     */
    public function testSevenXRenameBlockIsANoOp(): void
    {
        $dir = $this->makeDir([
            // A 3.x-removed symbol must NOT be rewritten at the 7.x step.
            'classes/A.php' => "<?php\nfunction who() { return elgg_get_logged_in_user(); }\n",
        ]);
        try {
            $this->assertFalse($this->rule->analyze($dir)->applicable);
            $result = $this->rule->apply($dir);
            $this->assertTrue($result->success);
            $this->assertEmpty($result->changes);
            $out = file_get_contents($dir . '/classes/A.php');
            $this->assertStringContainsString('elgg_get_logged_in_user(', $out);
            $this->assertStringNotContainsString('elgg_get_logged_in_user_entity', $out);
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
