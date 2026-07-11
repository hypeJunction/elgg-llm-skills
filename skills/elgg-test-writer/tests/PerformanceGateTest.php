<?php

declare(strict_types=1);

namespace ElggMigrate\Tests;

use ElggMigrate\PerformanceGate;
use PHPUnit\Framework\TestCase;

final class PerformanceGateTest extends TestCase
{
    private PerformanceGate $gate;

    protected function setUp(): void
    {
        $this->gate = new PerformanceGate();
    }

    public function testPassesWhenNoSchemaChange(): void
    {
        $dir = $this->makePluginDir([
            'start.php' => "<?php\nelgg_register_event_handler('init', 'system', 'foo');\n",
            'classes/Foo.php' => "<?php\nclass Foo { public function bar() { return 1; } }\n",
        ]);
        try {
            $result = $this->gate->scan($dir);
            $this->assertTrue($result->passed);
            $this->assertFalse($result->hasSchemaChange());
        } finally {
            $this->removeDir($dir);
        }
    }

    public function testFailsWhenRawDdlHasNoBenchmark(): void
    {
        $dir = $this->makePluginDir([
            'upgrades/AddIndex.php' => "<?php\n"
                . "class AddIndex {\n"
                . "  public function run() {\n"
                . "    \$db->query('ALTER TABLE my_table ADD INDEX my_idx (a, b)');\n"
                . "  }\n"
                . "}\n",
        ]);
        try {
            $result = $this->gate->scan($dir);
            $this->assertTrue($result->hasSchemaChange());
            $this->assertFalse($result->hasEvidence());
            $this->assertFalse($result->passed, 'schema DDL without evidence must fail the gate');
            $this->assertSame('add-index', $result->findings[0]['ddl']);
        } finally {
            $this->removeDir($dir);
        }
    }

    public function testDetectsSchemaBuilderDdl(): void
    {
        $dir = $this->makePluginDir([
            'classes/Elgg/Upgrades/AddColumn.php' => "<?php\n"
                . "\$schema->table('x')->addIndex(['owner_guid']);\n",
        ]);
        try {
            $result = $this->gate->scan($dir);
            $this->assertTrue($result->hasSchemaChange());
            $this->assertSame('builder-index', $result->findings[0]['ddl']);
        } finally {
            $this->removeDir($dir);
        }
    }

    public function testPassesWhenDdlHasBenchmarkFile(): void
    {
        $dir = $this->makePluginDir([
            'upgrades/AddIndex.php' => "<?php\n\$db->query('CREATE INDEX foo ON bar (baz)');\n",
            'benchmarks/add-index.json' => '{"note":"measured"}',
        ]);
        try {
            $result = $this->gate->scan($dir);
            $this->assertTrue($result->hasSchemaChange());
            $this->assertTrue($result->hasEvidence());
            $this->assertTrue($result->passed);
        } finally {
            $this->removeDir($dir);
        }
    }

    public function testPassesWhenEvidenceIsHandlerMetricInReport(): void
    {
        $dir = $this->makePluginDir([
            'upgrades/AddIndex.php' => "<?php\n\$db->query('ALTER TABLE t ADD KEY k (c)');\n",
            'RESULTS.md' => "# result\nHandler_read_next 130003 -> 0 after the index.\n",
        ]);
        try {
            $result = $this->gate->scan($dir);
            $this->assertTrue($result->passed, 'a report recording Handler_read_next is valid evidence');
        } finally {
            $this->removeDir($dir);
        }
    }

    public function testIgnoresDataOnlySql(): void
    {
        $dir = $this->makePluginDir([
            'install/seed.php' => "<?php\n\$db->query('INSERT INTO t (a) VALUES (1)');\n"
                . "\$db->query('UPDATE t SET a = 2 WHERE a = 1');\n",
        ]);
        try {
            $result = $this->gate->scan($dir);
            $this->assertFalse($result->hasSchemaChange(), 'INSERT/UPDATE are not schema changes');
            $this->assertTrue($result->passed);
        } finally {
            $this->removeDir($dir);
        }
    }

    public function testSkipsVendorDirectories(): void
    {
        $dir = $this->makePluginDir([
            'vendor/acme/lib/Schema.php' => "<?php\n\$db->query('ALTER TABLE vendored ADD INDEX i (x)');\n",
        ]);
        try {
            $result = $this->gate->scan($dir);
            $this->assertFalse($result->hasSchemaChange(), 'third-party DDL under vendor/ must be ignored');
        } finally {
            $this->removeDir($dir);
        }
    }

    // --- helpers (shared shape with the other gate tests) ---

    private function makePluginDir(array $files): string
    {
        $dir = sys_get_temp_dir() . '/elgg-perf-gate-' . uniqid();
        mkdir($dir, 0755, true);
        foreach ($files as $name => $content) {
            $path = $dir . '/' . $name;
            $parent = dirname($path);
            if (!is_dir($parent)) {
                mkdir($parent, 0755, true);
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
