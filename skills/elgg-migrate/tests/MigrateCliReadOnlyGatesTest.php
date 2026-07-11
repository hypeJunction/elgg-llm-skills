<?php

declare(strict_types=1);

namespace ElggMigrate\Tests;

use PHPUnit\Framework\TestCase;

/**
 * bin/migrate.php contract: the gate flags --verify/--security/--audit must NEVER
 * rewrite the plugin. They used to ride on the apply path, so a sweep of --verify
 * silently rewrote 260 files across the bodyology fleet (bd elgg-migrate-fohyb).
 *
 * These tests invoke the real CLI and hash every *.php in the plugin before and
 * after, so a future change that re-couples a gate to the apply path fails here.
 */
final class MigrateCliReadOnlyGatesTest extends TestCase
{
    private string $dir;
    private string $cli;
    private string $manifest;

    protected function setUp(): void
    {
        $this->cli = dirname(__DIR__) . '/bin/migrate.php';
        $this->manifest = dirname(__DIR__) . '/rules/3x-to-4x/manifest.json';
        $this->dir = sys_get_temp_dir() . '/migrate-cli-ro-' . bin2hex(random_bytes(4));
        mkdir($this->dir . '/lib', 0777, true);
        file_put_contents($this->dir . '/composer.json', "{\n  \"require\": { \"elgg/elgg\": \"~3.0\" }\n}\n");
        file_put_contents($this->dir . '/elgg-plugin.php', "<?php\nreturn ['routes' => []];\n");
        // an undocumented helper: add-docblocks is the transform that mutated on --verify
        file_put_contents($this->dir . '/lib/functions.php', "<?php\nfunction demo_helper(\$x) {\n\treturn \$x + 1;\n}\n");
    }

    protected function tearDown(): void
    {
        $this->rrmdir($this->dir);
    }

    private function treeHash(): string
    {
        $files = glob($this->dir . '/**/*.php') ?: [];
        $files = array_merge($files, glob($this->dir . '/*.php') ?: []);
        sort($files);
        $h = '';
        foreach ($files as $f) {
            $h .= $f . ':' . md5_file($f) . "\n";
        }
        return md5($h);
    }

    /** @return array{int,string} exit code and combined output */
    private function run(string $flags): array
    {
        $cmd = sprintf(
            'php %s %s %s %s 2>&1',
            escapeshellarg($this->cli),
            escapeshellarg($this->manifest),
            escapeshellarg($this->dir),
            $flags
        );
        $out = [];
        $code = 0;
        exec($cmd, $out, $code);
        return [$code, implode("\n", $out)];
    }

    public function testVerifyIsReadOnly(): void
    {
        $before = $this->treeHash();
        $this->run('--verify --no-guard --no-tests');
        $this->assertSame($before, $this->treeHash(), '--verify must not modify any file');
    }

    public function testVerifySecurityAuditIsReadOnly(): void
    {
        $before = $this->treeHash();
        $this->run('--verify --security --audit --no-guard --no-tests');
        $this->assertSame($before, $this->treeHash(), '--verify --security --audit must not modify any file');
    }

    public function testDryRunVerifyIsReadOnly(): void
    {
        $before = $this->treeHash();
        $this->run('--dry-run --verify --no-guard');
        $this->assertSame($before, $this->treeHash());
    }

    public function testApplyMutates(): void
    {
        $before = $this->treeHash();
        $this->run('--apply --verify --no-guard --no-tests');
        $this->assertNotSame($before, $this->treeHash(), '--apply must run the automated transforms');
    }

    public function testBareInvocationMutates(): void
    {
        $before = $this->treeHash();
        $this->run('--no-guard --no-tests');
        $this->assertNotSame($before, $this->treeHash(), 'a bare invocation applies by default');
    }

    public function testVerifyStillPropagatesGateExitCode(): void
    {
        // A removed-in-4.x call: the verify gate must flag it and exit 3, while
        // leaving the file untouched — read-only does not mean toothless.
        file_put_contents(
            $this->dir . '/lib/functions.php',
            "<?php\nfunction demo() { return elgg_format_attributes(['a' => 'b']); }\n"
        );
        $before = $this->treeHash();
        [$code] = $this->run('--verify --no-guard --no-tests');
        $this->assertSame(3, $code, '--verify must still exit 3 when the gate finds violations');
        $this->assertSame($before, $this->treeHash(), 'the gate must not rewrite while reporting');
    }

    private function rrmdir(string $d): void
    {
        if (!is_dir($d)) {
            return;
        }
        foreach (scandir($d) ?: [] as $e) {
            if ($e === '.' || $e === '..') {
                continue;
            }
            $p = "$d/$e";
            is_dir($p) ? $this->rrmdir($p) : @unlink($p);
        }
        @rmdir($d);
    }
}
