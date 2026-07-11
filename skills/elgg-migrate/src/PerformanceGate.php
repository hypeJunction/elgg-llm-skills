<?php

declare(strict_types=1);

namespace ElggMigrate;

/**
 * Performance gate — the benchmark-backed sixth safety gate.
 *
 * A migrated plugin that ships a SCHEMA CHANGE (a new index, a new/altered
 * table, a new column) changes the query plans of the queries that touch it.
 * The elgg-benchmark skill exists to prove such a change deterministically
 * (Handler_read_* delta + EXPLAIN) before it lands. This gate makes that
 * non-optional: if a plugin's upgrade/migration code carries schema DDL, it
 * must also carry committed benchmark evidence, or the gate fails (exit 8).
 *
 * Like SecuritySweep and DependencyAudit this is a STATIC, read-only gate: it
 * does not boot a database or run the benchmark itself (that is the benchmark
 * skill's job, and it needs Docker). It gates on *evidence that the benchmark
 * was run* — the same way the tests-first gate gates on a captured baseline
 * rather than re-running the suite inline.
 *
 * A plugin with no schema change passes trivially (nothing to benchmark).
 */
final class PerformanceGate
{
    /**
     * Raw-SQL and schema-builder DDL that changes a table's structure or indexes.
     * Data-only statements (INSERT/UPDATE/DELETE/SELECT) are intentionally absent.
     *
     * @var array<string,string> id => regex
     */
    private const DDL_PATTERNS = [
        'create-index' => '/\bCREATE\s+(?:UNIQUE\s+)?INDEX\b/i',
        'add-index'    => '/\bADD\s+(?:INDEX|KEY|UNIQUE|FULLTEXT|CONSTRAINT|PRIMARY\s+KEY)\b/i',
        'add-column'   => '/\bADD\s+COLUMN\b/i',
        'drop-index'   => '/\bDROP\s+(?:INDEX|KEY)\b/i',
        'create-table' => '/\bCREATE\s+TABLE\b/i',
        'alter-table'  => '/\bALTER\s+TABLE\b/i',
        // phinx / Elgg schema-builder fluent API (used by Elgg\Upgrade\Batch scripts)
        'builder-index'  => '/->\s*(?:addIndex|removeIndex|dropIndex)\s*\(/',
        'builder-table'  => '/->\s*(?:createTable|renameTable|dropTable)\s*\(/',
        'builder-column' => '/->\s*(?:addColumn|changeColumn|renameColumn|dropColumn|addForeignKey)\s*\(/',
    ];

    /**
     * Scan a migrated plugin for schema DDL and matching benchmark evidence.
     */
    public function scan(string $pluginPath): PerformanceResult
    {
        $findings = $this->findDdl($pluginPath);
        $evidence = $this->findEvidence($pluginPath);

        if (empty($findings)) {
            return new PerformanceResult(
                [], $evidence, true,
                'No schema-changing DDL found — nothing to benchmark.'
            );
        }

        $passed = !empty($evidence);
        $n = count($findings);
        if ($passed) {
            $summary = "{$n} schema-change site(s) found, with benchmark evidence ("
                . implode(', ', $evidence) . ') — gate passes.';
        } else {
            $summary = "{$n} schema-change site(s) found but NO benchmark evidence. "
                . 'Prove the change with the elgg-benchmark skill (Handler_read_* before/after) '
                . 'and commit the result into the plugin (a benchmarks/ dir, a *BENCHMARK* file, '
                . 'or a report containing Handler_read_next), then re-run.';
        }

        return new PerformanceResult($findings, $evidence, $passed, $summary);
    }

    /**
     * @return array<int,array{file:string,line:int,ddl:string}>
     */
    private function findDdl(string $pluginPath): array
    {
        $findings = [];
        foreach ($this->sourceFiles($pluginPath) as $path) {
            $lines = @file($path, FILE_IGNORE_NEW_LINES);
            if ($lines === false) {
                continue;
            }
            $rel = $this->relativePath($pluginPath, $path);
            foreach ($lines as $i => $line) {
                foreach (self::DDL_PATTERNS as $id => $regex) {
                    if (preg_match($regex, $line)) {
                        $findings[] = [
                            'file' => $rel,
                            'line' => $i + 1,
                            'ddl'  => $id,
                        ];
                        break; // one finding per line is enough
                    }
                }
            }
        }
        return $findings;
    }

    /**
     * Benchmark evidence committed alongside the plugin. Any one is sufficient:
     *   - a benchmarks/ directory,
     *   - a file whose name signals a benchmark (BENCHMARK.md, *-bench.json, …),
     *   - a file whose content records the deterministic metric (Handler_read_next).
     *
     * @return array<int,string> relative paths (deduplicated)
     */
    private function findEvidence(string $pluginPath): array
    {
        $evidence = [];
        foreach ($this->allFiles($pluginPath) as $path) {
            $rel = $this->relativePath($pluginPath, $path);
            $base = basename($path);

            if (str_contains(strtolower($rel), 'benchmarks/')
                || preg_match('/bench(mark)?/i', $base)) {
                $evidence[$rel] = true;
                continue;
            }
            // Content probe only for small text-ish files (avoid slurping blobs).
            if (@filesize($path) > 512 * 1024) {
                continue;
            }
            $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
            if (!in_array($ext, ['md', 'json', 'txt', 'php'], true)) {
                continue;
            }
            $contents = @file_get_contents($path);
            if ($contents !== false && str_contains($contents, 'Handler_read_next')) {
                $evidence[$rel] = true;
            }
        }
        return array_keys($evidence);
    }

    /** PHP + SQL sources under the plugin, skipping third-party + nested installs. */
    private function sourceFiles(string $dir): \Generator
    {
        foreach ($this->allFiles($dir) as $path) {
            $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
            if ($ext === 'php' || $ext === 'sql') {
                yield $path;
            }
        }
    }

    private function allFiles(string $dir): \Generator
    {
        if (!is_dir($dir)) {
            return;
        }
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
        );
        foreach ($iterator as $file) {
            /** @var \SplFileInfo $file */
            if (!$file->isFile()) {
                continue;
            }
            $path = $file->getPathname();
            if (str_contains($path, '/vendor/') || str_contains($path, '/vendors/')
                || str_contains($path, '/mod/') || str_contains($path, '/.git/')) {
                continue;
            }
            yield $path;
        }
    }

    private function relativePath(string $base, string $path): string
    {
        $base = rtrim($base, '/') . '/';
        return str_starts_with($path, $base) ? substr($path, strlen($base)) : $path;
    }
}
