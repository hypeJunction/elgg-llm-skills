<?php

declare(strict_types=1);

namespace ElggMigrate\Tests\Rules\V5ToV6;

use ElggMigrate\Rules\V5ToV6\RemovedFunctions;
use PHPUnit\Framework\TestCase;

final class RemovedFunctionsTest extends TestCase
{
    private RemovedFunctions $rule;

    protected function setUp(): void
    {
        $this->rule = new RemovedFunctions();
    }

    public function testIdAndAutomatable(): void
    {
        $this->assertSame('removed-functions-6x', $this->rule->getId());
        $this->assertTrue($this->rule->canAutomate());
    }

    public function testRewritesPluginHookFamilyAndLeavesUnrelatedExcludedAnd5xRemovals(): void
    {
        $dir = $this->makeDir([
            'classes/H.php' => "<?php\nfunction reg() {\n"
                . "    elgg_register_plugin_hook_handler('a', 'b', 'c');\n"
                . "    \$r = elgg_trigger_plugin_hook('x', 'y', [], null);\n"
                . "    register_error('bad');\n"                 // 5.x removal — NOT rewritten here
                . "    forward('/home');\n"                      // 5.x removal — NOT rewritten here
                . "    elgg_require_js('foo/bar');\n"            // 6.x-excluded (ESM judgment)
                . "    \$obj->forward('/nope');\n"               // method call — must NOT change
                . "    return \$r;\n"
                . "}\n",
        ]);

        try {
            $analysis = $this->rule->analyze($dir);
            $this->assertTrue($analysis->applicable);

            $result = $this->rule->apply($dir);
            $this->assertTrue($result->success);

            $out = file_get_contents($dir . '/classes/H.php');

            // The genuine 6.x plugin-hook family IS rewritten:
            $this->assertStringContainsString('elgg_register_event_handler(', $out);
            $this->assertStringContainsString('elgg_trigger_event_results(', $out);
            $this->assertStringNotContainsString('elgg_register_plugin_hook_handler(', $out);
            $this->assertStringNotContainsString('elgg_trigger_plugin_hook(', $out);

            // The message/redirect family are 5.x removals — the 6.x rule must
            // leave them for the 4x->5x step (bd elgg-migrate-jfrc1). They stay
            // as-is, NOT rewritten to their 5.x replacements at this step.
            $this->assertStringContainsString('register_error(', $out);
            $this->assertStringContainsString('forward(', $out);
            $this->assertStringNotContainsString('elgg_register_error_message(', $out);
            $this->assertStringNotContainsString('elgg_redirect_response(', $out);

            // Excluded (ESM) — left for LLM judgment:
            $this->assertStringContainsString('elgg_require_js(', $out);

            // Method call preserved (not a global FuncCall):
            $this->assertStringContainsString('$obj->forward(', $out);
        } finally {
            $this->removeDir($dir);
        }
    }

    public function testNoOpWhenNothingMatches(): void
    {
        $dir = $this->makeDir(['classes/A.php' => "<?php\nfunction ok() { return elgg_get_logged_in_user_guid(); }\n"]);
        try {
            $analysis = $this->rule->analyze($dir);
            $this->assertFalse($analysis->applicable);
            $this->assertEmpty($this->rule->apply($dir)->changes);
        } finally {
            $this->removeDir($dir);
        }
    }

    private function makeDir(array $files): string
    {
        $dir = sys_get_temp_dir() . '/elgg-migrate-rf-' . uniqid();
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
