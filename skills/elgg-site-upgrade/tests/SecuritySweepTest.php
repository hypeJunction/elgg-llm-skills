<?php

declare(strict_types=1);

namespace ElggMigrate\Tests;

use ElggMigrate\SecuritySweep;
use ElggMigrate\SemgrepRunner;
use PHPUnit\Framework\TestCase;

final class SecuritySweepTest extends TestCase
{
    private SecuritySweep $scanner;

    protected function setUp(): void
    {
        // Inject a runner pointing at a nonexistent binary so the regex sweep
        // is tested in isolation, regardless of whether semgrep is on PATH.
        $this->scanner = new SecuritySweep(new SemgrepRunner(binary: '/nonexistent/semgrep'));
    }

    // --- Critical patterns ---

    public function testDetectsEval(): void
    {
        $dir = $this->makePluginDir([
            'classes/Evil.php' => "<?php\neval(\$_GET['code']);",
        ]);

        try {
            $result = $this->scanner->scan($dir);
            $this->assertFalse($result->passed);
            $this->assertViolationWithCategory($result->violations, 'eval');
        } finally {
            $this->removeDir($dir);
        }
    }

    public function testDetectsUnserialize(): void
    {
        $dir = $this->makePluginDir([
            'classes/Loader.php' => "<?php\n\$obj = unserialize(\$data);",
        ]);

        try {
            $result = $this->scanner->scan($dir);
            $this->assertFalse($result->passed);
            $this->assertViolationWithCategory($result->violations, 'unserialize');
        } finally {
            $this->removeDir($dir);
        }
    }

    public function testDetectsExec(): void
    {
        $dir = $this->makePluginDir([
            'classes/Runner.php' => "<?php\nexec(\$command);",
        ]);

        try {
            $result = $this->scanner->scan($dir);
            $this->assertFalse($result->passed);
            $this->assertViolationWithCategory($result->violations, 'command-injection');
        } finally {
            $this->removeDir($dir);
        }
    }

    public function testDetectsShellExec(): void
    {
        $dir = $this->makePluginDir([
            'classes/Runner.php' => "<?php\nshell_exec('ls ' . \$dir);",
        ]);

        try {
            $result = $this->scanner->scan($dir);
            $this->assertFalse($result->passed);
            $this->assertViolationWithCategory($result->violations, 'command-injection');
        } finally {
            $this->removeDir($dir);
        }
    }

    // --- SQL injection ---

    public function testDetectsSqlConcatenation(): void
    {
        $dir = $this->makePluginDir([
            'classes/Repo.php' => "<?php\n\$rows = \$db->getData(\"SELECT * FROM users WHERE name = '\" . \$name . \"'\");",
        ]);

        try {
            $result = $this->scanner->scan($dir);
            $this->assertViolationWithCategory($result->violations, 'sql-injection');
        } finally {
            $this->removeDir($dir);
        }
    }

    public function testDetectsRawSqlStringConcat(): void
    {
        $dir = $this->makePluginDir([
            'classes/Query.php' => "<?php\n\$sql = \"SELECT * FROM elgg_entities WHERE guid = \" . \$guid;",
        ]);

        try {
            $result = $this->scanner->scan($dir);
            $this->assertViolationWithCategory($result->violations, 'sql-injection');
        } finally {
            $this->removeDir($dir);
        }
    }

    // --- XSS ---

    public function testDetectsUnescapedUserInputInViews(): void
    {
        $dir = $this->makePluginDir([
            'views/default/myplugin/view.php' => "<?php\necho \$vars['title'];",
        ]);

        try {
            $result = $this->scanner->scan($dir);
            $this->assertViolationWithCategory($result->violations, 'xss');
        } finally {
            $this->removeDir($dir);
        }
    }

    public function testIgnoresEchoOfPreparedContentInViews(): void
    {
        // Standard Elgg pattern: $content = elgg_view(...); echo $content;
        $dir = $this->makePluginDir([
            'views/default/myplugin/list.php' => "<?php\n\$content = elgg_view('item', \$vars);\necho \$content;",
        ]);

        try {
            $result = $this->scanner->scan($dir);
            $xssViolations = array_filter(
                $result->violations,
                fn($v) => $v->category === 'xss',
            );
            $this->assertEmpty($xssViolations, 'Should not flag echo of prepared content from elgg_view()');
        } finally {
            $this->removeDir($dir);
        }
    }

    public function testIgnoresUnescapedOutputOutsideViews(): void
    {
        // echo $var in non-view files should NOT trigger view-specific XSS check
        $dir = $this->makePluginDir([
            'classes/Helper.php' => "<?php\necho \$count;",
        ]);

        try {
            $result = $this->scanner->scan($dir);
            $xssViolations = array_filter(
                $result->violations,
                fn($v) => $v->category === 'xss',
            );
            $this->assertEmpty($xssViolations, 'Should not flag echo in non-view files');
        } finally {
            $this->removeDir($dir);
        }
    }

    // --- Crypto ---

    public function testDetectsMd5(): void
    {
        $dir = $this->makePluginDir([
            'classes/Auth.php' => "<?php\n\$hash = md5(\$password);",
        ]);

        try {
            $result = $this->scanner->scan($dir);
            $this->assertViolationWithCategory($result->violations, 'deprecated-crypto');
        } finally {
            $this->removeDir($dir);
        }
    }

    // --- Hardcoded credentials ---

    public function testDetectsHardcodedPassword(): void
    {
        $dir = $this->makePluginDir([
            'classes/Config.php' => "<?php\n\$password = 'mysecretpassword123';",
        ]);

        try {
            $result = $this->scanner->scan($dir);
            $this->assertViolationWithCategory($result->violations, 'hardcoded-credentials');
        } finally {
            $this->removeDir($dir);
        }
    }

    // --- Elgg-specific ---

    public function testDetectsDbprefixUsage(): void
    {
        $dir = $this->makePluginDir([
            'classes/Repo.php' => "<?php\n\$prefix = elgg_get_config('dbprefix');",
        ]);

        try {
            $result = $this->scanner->scan($dir);
            $this->assertViolationWithCategory($result->violations, 'sql-injection');
        } finally {
            $this->removeDir($dir);
        }
    }

    // --- Clean code passes ---

    public function testCleanPluginPasses(): void
    {
        $dir = $this->makePluginDir([
            'elgg-plugin.php' => "<?php\nreturn ['hooks' => []];",
            'classes/MyPlugin/Hooks.php' => "<?php\nnamespace MyPlugin;\n\nclass Hooks {\n    public static function entityMenu(\\Elgg\\Hook \$hook): array {\n        \$return = \$hook->getValue();\n        return \$return;\n    }\n}\n",
        ]);

        try {
            $result = $this->scanner->scan($dir);
            $this->assertTrue($result->passed);
        } finally {
            $this->removeDir($dir);
        }
    }

    // --- Result structure ---

    public function testSummaryIncludesCategories(): void
    {
        $dir = $this->makePluginDir([
            'classes/Bad.php' => "<?php\neval(\$code);\nexec(\$cmd);",
        ]);

        try {
            $result = $this->scanner->scan($dir);
            $this->assertStringContainsString('error', $result->summary);
            $this->assertNotEmpty($result->byCategory());
        } finally {
            $this->removeDir($dir);
        }
    }

    public function testSkipsComments(): void
    {
        $dir = $this->makePluginDir([
            'classes/Safe.php' => "<?php\n// eval(\$code); is dangerous\n/* shell_exec() should never be used */\n * exec() in phpdoc\n",
        ]);

        try {
            $result = $this->scanner->scan($dir);
            $this->assertTrue($result->passed, 'Comments should not trigger violations');
        } finally {
            $this->removeDir($dir);
        }
    }

    public function testSkipsVendorDirectory(): void
    {
        $dir = $this->makePluginDir([
            'vendor/lib/Dangerous.php' => "<?php\neval(\$code);",
            'classes/Safe.php' => "<?php\nreturn true;",
        ]);

        try {
            $result = $this->scanner->scan($dir);
            $this->assertTrue($result->passed, 'vendor/ files should be skipped');
        } finally {
            $this->removeDir($dir);
        }
    }

    // --- Helpers ---

    private function assertViolationWithCategory(array $violations, string $category): void
    {
        $found = false;
        foreach ($violations as $v) {
            if ($v->category === $category) {
                $found = true;
                break;
            }
        }
        $this->assertTrue($found, "Expected violation with category '{$category}'");
    }

    private function makePluginDir(array $files): string
    {
        $dir = sys_get_temp_dir() . '/elgg-migrate-' . uniqid();
        mkdir($dir, 0755, true);

        foreach ($files as $name => $content) {
            $path = $dir . '/' . $name;
            $parentDir = dirname($path);
            if (!is_dir($parentDir)) {
                mkdir($parentDir, 0755, true);
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
