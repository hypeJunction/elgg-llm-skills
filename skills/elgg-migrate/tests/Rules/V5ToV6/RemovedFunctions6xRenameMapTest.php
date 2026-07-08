<?php

declare(strict_types=1);

namespace ElggMigrate\Tests\Rules\V5ToV6;

use ElggMigrate\Rules\V5ToV6\RemovedFunctions;
use PHPUnit\Framework\TestCase;

/**
 * Complements RemovedFunctionsTest by exercising every entry in
 * references/removed-function-renames.json['6.x'] end-to-end (input code →
 * transformed output) plus the documented non-rewrite invariants
 * (method calls, namespaced calls, function definitions and string literals
 * are left untouched). The base RemovedFunctionsTest only asserts a handful
 * of the 15 renames.
 */
final class RemovedFunctions6xRenameMapTest extends TestCase
{
    private RemovedFunctions $rule;

    protected function setUp(): void
    {
        $this->rule = new RemovedFunctions();
    }

    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function renameProvider(): array
    {
        return [
            'trigger plugin hook'          => ['elgg_trigger_plugin_hook', 'elgg_trigger_event_results'],
            'register hook handler'        => ['elgg_register_plugin_hook_handler', 'elgg_register_event_handler'],
            'unregister hook handler'      => ['elgg_unregister_plugin_hook_handler', 'elgg_unregister_event_handler'],
            'clear hook handlers'          => ['elgg_clear_plugin_hook_handlers', 'elgg_clear_event_handlers'],
            'register_error'               => ['register_error', 'elgg_register_error_message'],
            'system_message'               => ['system_message', 'elgg_register_success_message'],
            'forward'                      => ['forward', 'elgg_redirect_response'],
            'elgg_redirect'                => ['elgg_redirect', 'elgg_redirect_response'],
            'current_page_url'             => ['current_page_url', 'elgg_get_current_url'],
            'rmdir helper'                 => ['_elgg_rmdir', 'elgg_delete_directory'],
            'flush caches'                 => ['elgg_flush_caches', 'elgg_clear_caches'],
            'get version'                  => ['elgg_get_version', 'elgg_get_release'],
            'strrchr'                      => ['elgg_strrchr', 'mb_strrchr'],
            'strripos'                     => ['elgg_strripos', 'mb_strripos'],
            'html decode'                  => ['_elgg_html_decode', 'html_entity_decode'],
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
            $analysis = $this->rule->analyze($dir);
            $this->assertTrue($analysis->applicable, "{$old}() should be flagged");

            $result = $this->rule->apply($dir);
            $this->assertTrue($result->success);
            $this->assertNotEmpty($result->changes, "{$old}() should produce a change");

            $out = file_get_contents($dir . '/classes/R.php');
            $this->assertStringContainsString("{$new}('arg')", $out, "{$old}() should be renamed to {$new}()");
            $this->assertStringNotContainsString("{$old}(", $out, "{$old}() should no longer appear");
        } finally {
            $this->removeDir($dir);
        }
    }

    public function testMethodCallsNamespacedCallsAndStringsAreNotRewritten(): void
    {
        $code = "<?php\n"
            . "namespace App;\n"
            . "class C {\n"
            . "    public function run(\$obj) {\n"
            . "        \$obj->forward('/x');\n"           // method call — untouched
            . "        \\Other\\forward('/y');\n"          // namespaced call — untouched
            . "        \$s = 'call register_error here';\n" // string literal — untouched
            . "        return \$s;\n"
            . "    }\n"
            . "}\n";
        $dir = $this->makeDir(['classes/C.php' => $code]);

        try {
            $analysis = $this->rule->analyze($dir);
            $this->assertFalse($analysis->applicable, 'No global FuncCall present — nothing to rewrite');

            $result = $this->rule->apply($dir);
            $this->assertEmpty($result->changes);

            $out = file_get_contents($dir . '/classes/C.php');
            $this->assertStringContainsString("\$obj->forward('/x')", $out);
            $this->assertStringContainsString("Other\\forward('/y')", $out);
            $this->assertStringContainsString("'call register_error here'", $out);
            $this->assertStringNotContainsString('elgg_redirect_response', $out);
        } finally {
            $this->removeDir($dir);
        }
    }

    public function testFunctionDefinitionOfSameNameIsNotRewritten(): void
    {
        // A plugin that *defines* a function named like a removed one must not
        // have its declaration renamed — only call sites are eligible, and here
        // the call site is a genuine global FuncCall so it IS renamed.
        $dir = $this->makeDir([
            'lib/compat.php' => "<?php\nfunction current_page_url() { return 'stub'; }\n"
                . "\$u = current_page_url();\n",
        ]);

        try {
            $this->rule->apply($dir);
            $out = file_get_contents($dir . '/lib/compat.php');

            // Declaration keeps its original name.
            $this->assertStringContainsString('function current_page_url()', $out);
            // Call site is rewritten to the 6.x equivalent.
            $this->assertStringContainsString('$u = elgg_get_current_url()', $out);
        } finally {
            $this->removeDir($dir);
        }
    }

    /**
     * @param array<string, string> $files
     */
    private function makeDir(array $files): string
    {
        $dir = sys_get_temp_dir() . '/elgg-migrate-rf6x-' . uniqid();
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
