<?php

declare(strict_types=1);

namespace ElggMigrate\Tests\Rules\V4ToV5;

use ElggMigrate\Rules\V4ToV5\RemovedFunctions;
use PHPUnit\Framework\TestCase;

/**
 * Exercises EVERY entry in references/removed-function-renames.json['5.x']
 * end-to-end. The message/redirect family (forward, register_error,
 * system_message, current_page_url, elgg_get_version) is core-verified as a 5.x
 * removal; before bd elgg-migrate-jfrc1 it was mis-filed under the 6.x rename
 * block, so a 4x->5x migration never rewrote it at the step where it breaks.
 * Guards the exact-replacement invariant, incl. register_error ->
 * elgg_register_error_message (NOT elgg_register_error, which does not exist).
 */
final class RemovedFunctions5xRenameMapTest extends TestCase
{
    private RemovedFunctions $rule;

    protected function setUp(): void
    {
        $this->rule = new RemovedFunctions();
    }

    public function testId(): void
    {
        $this->assertSame('removed-functions-5x', $this->rule->getId());
    }

    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function renameProvider(): array
    {
        return [
            'current_page_url'   => ['current_page_url', 'elgg_get_current_url'],
            'get_default_access' => ['get_default_access', 'elgg_get_default_access'],
            'forward'            => ['forward', 'elgg_redirect_response'],
            'register_error'     => ['register_error', 'elgg_register_error_message'],
            'system_message'     => ['system_message', 'elgg_register_success_message'],
            'elgg_get_version'   => ['elgg_get_version', 'elgg_get_release'],
        ];
    }

    /**
     * @dataProvider renameProvider
     */
    public function testEachRenameMapEntryIsRewritten(string $old, string $new): void
    {
        $dir = $this->makeDir([
            'classes/R.php' => "<?php\nfunction wrap() {\n    return {$old}('arg');\n}\n",
        ]);

        try {
            $this->assertTrue($this->rule->analyze($dir)->applicable, "{$old}() should be flagged");

            $result = $this->rule->apply($dir);
            $this->assertTrue($result->success);
            $this->assertNotEmpty($result->changes, "{$old}() should produce a change");

            $out = file_get_contents($dir . '/classes/R.php');
            $this->assertStringContainsString("{$new}('arg')", $out, "{$old}() should be renamed to {$new}()");
            // Word-boundary check: the new name may legitimately contain the old
            // as a suffix (get_default_access ⊂ elgg_get_default_access).
            $this->assertDoesNotMatchRegularExpression(
                '/(?<![\w])' . preg_quote($old, '/') . '\(/',
                $out,
                "{$old}() should no longer appear as a standalone call",
            );
        } finally {
            $this->removeDir($dir);
        }
    }

    public function testRegisterErrorIsNotRenamedToNonexistentElggRegisterError(): void
    {
        $dir = $this->makeDir([
            'classes/E.php' => "<?php\nfunction boom() { register_error('nope'); }\n",
        ]);
        try {
            $this->rule->apply($dir);
            $out = file_get_contents($dir . '/classes/E.php');
            $this->assertStringContainsString('elgg_register_error_message(', $out);
            // The bare-name trap: elgg_register_error() does NOT exist in core.
            $this->assertDoesNotMatchRegularExpression('/(?<![\w])elgg_register_error\(/', $out);
        } finally {
            $this->removeDir($dir);
        }
    }

    /**
     * @param array<string, string> $files
     */
    private function makeDir(array $files): string
    {
        $dir = sys_get_temp_dir() . '/elgg-migrate-rf5x-' . uniqid();
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
        if (!is_dir($dir)) {
            return;
        }
        foreach (new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        ) as $f) {
            $f->isDir() ? rmdir($f->getPathname()) : unlink($f->getPathname());
        }
        rmdir($dir);
    }
}
