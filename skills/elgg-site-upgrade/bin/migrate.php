#!/usr/bin/env php
<?php

/**
 * Run automated migration rules on a plugin directory.
 *
 * Usage: php bin/migrate.php <manifest> <plugin-path> [--dry-run] [--apply] [--report] [--no-guard] [--verify] [--security] [--audit] [--benchmark] [--no-tests]
 *
 * A TESTS-FIRST gate runs before any transform is applied (Iron Law 4): the plugin
 * must ship a test suite (incl. MigrationRegressionTest) AND a passing baseline
 * record, or migrate.php refuses with exit code 7. Waive with --no-tests (logged).
 *
 * Example:
 *   php bin/migrate.php rules/2x-to-3x/manifest.json tmp/hypeWall
 *   php bin/migrate.php rules/2x-to-3x/manifest.json tmp/hypeWall --dry-run
 *   php bin/migrate.php rules/3x-to-4x/manifest.json tmp/hypeWall --apply --verify --security
 */

require_once __DIR__ . '/../vendor/autoload.php';

use ElggMigrate\DependencyAudit;
use ElggMigrate\PerformanceGate;
use ElggMigrate\PostMigrationVerifier;
use ElggMigrate\RuleRunner;
use ElggMigrate\SecuritySweep;
use ElggMigrate\TestsFirstGate;
use ElggMigrate\VersionGuard;

$args = array_slice($argv, 1);
$dryRun = in_array('--dry-run', $args);
$report = in_array('--report', $args);
$noGuard = in_array('--no-guard', $args);
$verify = in_array('--verify', $args);
$security = in_array('--security', $args);
$audit = in_array('--audit', $args);
$benchmark = in_array('--benchmark', $args);
$checkOnly = in_array('--check', $args);
$strictCompleteness = in_array('--strict-completeness', $args);
$apply = in_array('--apply', $args);

// --verify / --security / --audit are READ-ONLY gates: they inspect a plugin and
// report, they must never rewrite it. They used to ride on the apply path, so a
// sweep of `--verify` silently rewrote 260 files across the bodyology fleet, and
// two preview images were built from the mutated tree before it was noticed
// (bd elgg-migrate-fohyb). SKILL.md documents them as gates ("treat a non-empty
// report as your worklist") — a gate must not write.
//
// So when a gate flag is present without an explicit --apply, force read-only. A
// bare invocation (migrate-plugin.sh) and an explicit --apply still mutate.
if (($verify || $security || $audit || $benchmark) && !$apply && !$dryRun && !$checkOnly) {
    $dryRun = true;
    fwrite(STDERR, "note: --verify/--security/--audit/--benchmark are read-only gates — inspecting without applying.
");
    fwrite(STDERR, "      Pass --apply to run the migration as well.

");
}

// TESTS-FIRST gate (Iron Law 4). Defaults ON: no plugin code is mutated until a
// regression safety net (test suite + captured baseline) is proven to exist.
// `--require-tests` is the (redundant) explicit opt-in; `--no-tests` is the
// escape hatch and is loudly logged.
$noTests = in_array('--no-tests', $args);
$requireTests = !$noTests; // ON unless explicitly waived

// Optional override for where the baseline record lives (else auto-discovered).
$baselineOverride = null;
foreach ($args as $a) {
    if (str_starts_with($a, '--baseline=')) {
        $baselineOverride = substr($a, strlen('--baseline='));
    }
}

$args = array_filter($args, fn($a) => !str_starts_with($a, '--'));
$args = array_values($args);

if (count($args) < 2) {
    fwrite(STDERR, "Usage: php bin/migrate.php <manifest.json> <plugin-path> [flags]\n");
    fwrite(STDERR, "\nFlags:\n");
    fwrite(STDERR, "  --dry-run               Analyze only, don't modify files\n");
    fwrite(STDERR, "  --apply                 Apply automated transforms even when a read-only gate flag\n");
    fwrite(STDERR, "                          (--verify/--security/--audit) is present. Without it, those\n");
    fwrite(STDERR, "                          flags inspect only. A bare invocation applies by default.\n");
    fwrite(STDERR, "  --check                 Only run the incomplete-migration check (scans for prior-version\n");
    fwrite(STDERR, "                          patterns left over after a previous migration attempt). Exit 0 if\n");
    fwrite(STDERR, "                          none; exit 6 if findings. NOT a substitute for --verify: it only\n");
    fwrite(STDERR, "                          finds leftover shapes for the detected step, not the full removed-\n");
    fwrite(STDERR, "                          symbol / changed-contract catalog. Use --verify for that.\n");
    fwrite(STDERR, "  --strict-completeness   After migration, fail if any source-version patterns remain.\n");
    fwrite(STDERR, "                          Best paired with --verify.\n");
    fwrite(STDERR, "  --report                Show LLM instructions for manual rules\n");
    fwrite(STDERR, "  --no-guard              Skip the shape-based version guard. The deep completeness check\n");
    fwrite(STDERR, "                          still runs and reports leftover patterns.\n");
    fwrite(STDERR, "  --verify                Run post-migration version boundary check\n");
    fwrite(STDERR, "  --security              Run security sweep (pattern-based) after migration\n");
    fwrite(STDERR, "  --audit                 Run composer audit for dependency CVEs\n");
    fwrite(STDERR, "  --require-tests         (default ON) Refuse to apply transforms unless the plugin\n");
    fwrite(STDERR, "                          ships a test suite (incl. MigrationRegressionTest) AND a\n");
    fwrite(STDERR, "                          passing baseline record exists. Exit 7 if missing.\n");
    fwrite(STDERR, "  --no-tests              Escape hatch: skip the tests-first gate (logged loudly).\n");
    fwrite(STDERR, "                          Unsafe — no RED→GREEN proof the migration preserved behavior.\n");
    fwrite(STDERR, "  --baseline=<path>       Explicit path to the baseline JSON record (else auto-discovered\n");
    fwrite(STDERR, "                          at tests/.migration-baseline.json or \$ELGG_MIGRATE_BASELINE).\n");
    exit(1);
}

[$manifestPath, $pluginPath] = $args;

if (!file_exists($manifestPath)) {
    fwrite(STDERR, "Manifest not found: {$manifestPath}\n");
    exit(1);
}

if (!is_dir($pluginPath)) {
    fwrite(STDERR, "Plugin directory not found: {$pluginPath}\n");
    exit(1);
}

// Initialize with VersionGuard unless explicitly disabled
$versionGuard = $noGuard ? null : new VersionGuard();
$runner = new RuleRunner($versionGuard);

echo "=== Migration: {$manifestPath} → {$pluginPath} ===\n\n";

$guard = new VersionGuard();
$manifest = $runner->loadManifest($manifestPath);

// --- COMPLETENESS CHECK (deep guard) ---
// Always runs — independent of the shape-based version guard. Finds
// prior-version code patterns inside a plugin whose file shape already
// looks done. Shape-only detection misses incomplete migrations
// (start.php removed but 3.x hook signatures still in place).
echo "--- COMPLETENESS CHECK ---\n";
try {
    $detected = $guard->detectVersion($pluginPath);
    echo "Detected plugin shape: {$detected}\n";
    $gaps = $guard->detectIncompletePatterns($pluginPath, $detected);
    if (empty($gaps)) {
        echo "✓ No prior-version code patterns found\n\n";
    } else {
        echo "⚠ Found " . count($gaps) . " prior-version pattern(s) — migration is incomplete:\n";
        foreach ($gaps as $g) {
            echo "  {$g->file}:{$g->line}  [{$g->patternId}]  {$g->description}\n";
            echo "    → {$g->fix}\n";
        }
        echo "\n";
    }
} catch (\RuntimeException $e) {
    echo "  (couldn't detect plugin shape — {$e->getMessage()})\n\n";
    $gaps = [];
    $detected = null;
}

if ($checkOnly) {
    // --check mode stops here. Exit 0 if clean, 6 if leftovers found.
    exit(empty($gaps) ? 0 : 6);
}

// Version guard check (validates AND prints)
if (!$noGuard) {
    try {
        echo "--- VERSION CHECK ---\n";
        echo "Detected plugin version: {$detected}\n";
        echo "Manifest: {$manifest['from']} → {$manifest['to']}\n";
        // Actually validate (not just print)
        $guard->validate($pluginPath, $manifest);
        echo "✓ Version match confirmed\n\n";
    } catch (\ElggMigrate\VersionMismatchException $e) {
        // If the shape is later than expected BUT completeness check found
        // patterns matching the manifest's source version, this is exactly
        // the "incomplete migration" case — proceed with a clear warning
        // rather than refuse. Saves users from blindly --no-guard'ing.
        $isLaterShapeWithSourceLeftovers = $e->detectedVersion > $e->expectedVersion
            && !empty($gaps)
            && $gaps[0]->sourceVersion === $e->expectedVersion;

        if ($isLaterShapeWithSourceLeftovers) {
            echo "⚠ Shape says {$e->detectedVersion} but content has {$e->expectedVersion} leftovers.\n";
            echo "  Proceeding with {$e->expectedVersion}→{$manifest['to']} migration to clean up.\n\n";
            // RuleRunner's internal applyAll/analyzeAll will also throw the
            // same VersionMismatch. Drop the guard from the runner so the
            // cleanup migration actually runs.
            $runner = new RuleRunner(null);
        } else {
            fwrite(STDERR, "\n✗ VERSION MISMATCH\n");
            fwrite(STDERR, "  {$e->getMessage()}\n");
            fwrite(STDERR, "\n  Detected: {$e->detectedVersion}\n");
            fwrite(STDERR, "  Expected: {$e->expectedVersion}\n");
            fwrite(STDERR, "\n  Use --no-guard to skip this check (not recommended).\n");
            exit(2);
        }
    } catch (\RuntimeException $e) {
        fwrite(STDERR, "\n✗ VERSION DETECTION FAILED\n");
        fwrite(STDERR, "  {$e->getMessage()}\n");
        fwrite(STDERR, "\n  Use --no-guard to skip this check.\n");
        exit(2);
    }
}

// Analyze phase
echo "--- ANALYSIS ---\n\n";
try {
    $analyses = $runner->analyzeAll($manifestPath, $pluginPath);
} catch (\ElggMigrate\VersionMismatchException $e) {
    fwrite(STDERR, "\n✗ VERSION MISMATCH (caught during analysis)\n");
    fwrite(STDERR, "  {$e->getMessage()}\n");
    exit(2);
}
$applicableCount = 0;

foreach ($analyses as $analysis) {
    $status = $analysis->applicable ? '✓ APPLICABLE' : '  skip';
    echo "{$status}  {$analysis->ruleId}: {$analysis->summary}\n";

    if ($analysis->applicable) {
        $applicableCount++;
        foreach ($analysis->findings as $finding) {
            echo "         {$finding->file}:{$finding->line} — {$finding->description}\n";
        }
    }
}

echo "\n{$applicableCount} rule(s) applicable out of " . count($analyses) . " analyzed.\n\n";

// LLM instructions
$llmInstructions = $runner->getLlmInstructions($manifestPath);
if (!empty($llmInstructions)) {
    echo "--- LLM-GUIDED RULES (manual follow-up needed) ---\n\n";
    foreach ($llmInstructions as $item) {
        echo "  [{$item['id']}] {$item['name']}\n";
    }
    echo "\n";
}

if ($dryRun) {
    echo "[DRY RUN] No files modified.\n";

    // Still run verification, security, and audit in dry-run mode (read-only).
    // These gates must propagate their exit codes (3/4/5) even in dry-run —
    // the documented "Verify only" workflow is `--dry-run --verify`, and a gate
    // that reports violations but exits 0 is silently non-blocking in CI.
    $exitCode = 0;
    if ($verify || $security || $audit || $benchmark) {
        echo "\n";
    }
    if ($verify) {
        $manifest = $runner->loadManifest($manifestPath);
        if (!runVerification($pluginPath, $manifest['to'])) {
            $exitCode = 3;
        }
    }
    if ($security) {
        if (!runSecuritySweep($pluginPath)) {
            $exitCode = max($exitCode, 4);
        }
    }
    if ($audit) {
        if (!runDependencyAudit($pluginPath)) {
            $exitCode = max($exitCode, 5);
        }
    }
    if ($benchmark) {
        if (!runPerformanceGate($pluginPath)) {
            $exitCode = max($exitCode, 8);
        }
    }

    exit($exitCode);
}

// --- TESTS-FIRST GATE (Iron Law 4) ---
// Refuse to MUTATE plugin code until a regression safety net is proven to exist:
// (1) a plugin test suite incl. MigrationRegressionTest, and (2) a captured,
// PASSING baseline run against the CURRENT (pre-migration) code. This is the only
// thing that makes every downstream gate more than theater — without a known-good
// baseline you cannot tell whether the migration broke behavior or whether it was
// already broken. Only runs on the real apply path (dry-run/--check exited above).
if ($requireTests) {
    if (!TestsFirstGate::runTestsFirstGate($pluginPath, $manifest, $baselineOverride)) {
        exit(7);
    }
} else {
    TestsFirstGate::logTestsBypass($pluginPath, $manifest);
}

// Apply phase
echo "--- APPLYING AUTOMATED RULES ---\n\n";
try {
    $results = $runner->applyAll($manifestPath, $pluginPath);
} catch (\ElggMigrate\VersionMismatchException $e) {
    fwrite(STDERR, "\n✗ VERSION MISMATCH (caught during apply)\n");
    fwrite(STDERR, "  {$e->getMessage()}\n");
    exit(2);
}

$totalChanges = 0;
$totalWarnings = 0;

foreach ($results as $result) {
    $status = $result->success ? '✓' : '✗';
    echo "{$status} {$result->ruleId}\n";

    foreach ($result->changes as $change) {
        echo "   [{$change->type}] {$change->file}: {$change->description}\n";
        $totalChanges++;
    }

    foreach ($result->warnings as $warning) {
        echo "   [WARN] {$warning}\n";
        $totalWarnings++;
    }
}

echo "\n=== SUMMARY ===\n";
echo "Files changed: {$totalChanges}\n";
echo "Warnings: {$totalWarnings}\n";
echo "LLM-guided rules remaining: " . count($llmInstructions) . "\n";

if ($report) {
    echo "\n--- LLM INSTRUCTIONS FOR MANUAL MIGRATION ---\n\n";
    foreach ($llmInstructions as $item) {
        echo "## {$item['name']} [{$item['id']}]\n\n";
        echo "{$item['instructions']}\n\n";
        echo "---\n\n";
    }
}

// Post-migration verification
$exitCode = 0;

// --- POST-MIGRATION COMPLETENESS GATE ---
// After running rules, re-scan for prior-version leftovers. Surfaces any
// patterns the rule set didn't catch — the gap that left hypeinbox half-
// migrated on migrate/elgg-4.x. Always informational; only blocks when
// --strict-completeness is set.
if (!$dryRun) {
    echo "\n--- POST-MIGRATION COMPLETENESS ---\n";
    try {
        $postDetected = $guard->detectVersion($pluginPath);
        $postGaps = $guard->detectIncompletePatterns($pluginPath, $postDetected);
        if (empty($postGaps)) {
            echo "✓ No source-version patterns remaining\n";
        } else {
            echo "⚠ " . count($postGaps) . " source-version pattern(s) still present:\n";
            foreach ($postGaps as $g) {
                echo "  {$g->file}:{$g->line}  [{$g->patternId}]  {$g->description}\n";
            }
            if ($strictCompleteness) {
                echo "\n✗ --strict-completeness: failing because leftover patterns remain.\n";
                $exitCode = max($exitCode, 6);
            } else {
                echo "  (pass --strict-completeness to make this a hard fail)\n";
            }
        }
    } catch (\RuntimeException) {
        echo "  (skipped — plugin shape no longer detectable)\n";
    }
}

if ($verify) {
    $manifest = $runner->loadManifest($manifestPath);
    echo "\n";
    if (!runVerification($pluginPath, $manifest['to'])) {
        $exitCode = 3;
    }
}

// Security sweep
if ($security) {
    echo "\n";
    if (!runSecuritySweep($pluginPath)) {
        // Security warnings don't block — only errors would
        // but we still set exit code for CI integration
        $exitCode = max($exitCode, 4);
    }
}

// Dependency audit
if ($audit) {
    echo "\n";
    if (!runDependencyAudit($pluginPath)) {
        $exitCode = max($exitCode, 5);
    }
}

// Performance gate — a plugin that ships schema DDL must ship benchmark evidence
if ($benchmark) {
    echo "\n";
    if (!runPerformanceGate($pluginPath)) {
        $exitCode = max($exitCode, 8);
    }
}

exit($exitCode);

// --- Helper functions ---

function runVerification(string $pluginPath, string $targetVersion): bool
{
    echo "--- POST-MIGRATION VERIFICATION (target: {$targetVersion}) ---\n\n";

    $verifier = new PostMigrationVerifier();
    $result = $verifier->verify($pluginPath, $targetVersion);

    if ($result->passed) {
        echo "✓ No version boundary violations found\n";
        return true;
    }

    $errors = $result->errors();
    $warnings = $result->warnings();

    foreach ($errors as $v) {
        echo "✗ ERROR  {$v->file}";
        if ($v->line > 0) echo ":{$v->line}";
        echo "\n         {$v->message}\n";
        if ($v->code) echo "         {$v->code}\n";
    }

    foreach ($warnings as $v) {
        echo "⚠ WARN   {$v->file}";
        if ($v->line > 0) echo ":{$v->line}";
        echo "\n         {$v->message}\n";
        if ($v->code) echo "         {$v->code}\n";
    }

    echo "\n" . count($errors) . " error(s), " . count($warnings) . " warning(s)\n";

    return count($errors) === 0;
}

function runPerformanceGate(string $pluginPath): bool
{
    echo "--- PERFORMANCE GATE (benchmark evidence for schema changes) ---\n\n";

    $gate = new PerformanceGate();
    $result = $gate->scan($pluginPath);

    if (!$result->hasSchemaChange()) {
        echo "✓ {$result->summary}\n";
        return true;
    }

    echo "Schema-changing DDL:\n";
    foreach ($result->findings as $f) {
        echo "  • {$f['file']}:{$f['line']}  ({$f['ddl']})\n";
    }
    echo "\n";

    if ($result->hasEvidence()) {
        echo "Benchmark evidence:\n";
        foreach ($result->evidence as $e) {
            echo "  ✓ {$e}\n";
        }
        echo "\n✓ {$result->summary}\n";
        return true;
    }

    echo "✗ {$result->summary}\n";
    return false;
}

function runDependencyAudit(string $pluginPath): bool
{
    echo "--- DEPENDENCY AUDIT (composer audit) ---\n\n";

    $audit = new DependencyAudit();
    $result = $audit->audit($pluginPath);

    if ($result->source === '') {
        echo "ℹ {$result->summary}\n";
        return true;
    }

    echo "Source: {$result->source}\n\n";

    if (empty($result->advisories) && empty($result->abandoned)) {
        echo "✓ {$result->summary}\n";
        return true;
    }

    foreach ($result->advisories as $a) {
        $sev = strtolower($a->severity);
        $icon = match ($sev) {
            'critical', 'high' => '✗',
            'medium' => '⚠',
            default => 'ℹ',
        };
        echo "{$icon} {$sev}  [{$a->packageName}] {$a->cve}\n";
        echo "  {$a->title}\n";
        echo "  Affected: {$a->affectedVersions}\n";
        if ($a->link) echo "  Link: {$a->link}\n";
        echo "\n";
    }

    if (!empty($result->abandoned)) {
        echo "Abandoned packages:\n";
        foreach ($result->abandoned as $pkg) {
            echo "  ⚠ {$pkg}\n";
        }
        echo "\n";
    }

    echo "{$result->summary}\n";

    return $result->passed;
}

function runSecuritySweep(string $pluginPath): bool
{
    echo "--- SECURITY SWEEP ---\n\n";

    $scanner = new SecuritySweep();
    $result = $scanner->scan($pluginPath);

    if ($result->passed && empty($result->violations)) {
        echo "✓ No security issues found\n";
        return true;
    }

    foreach ($result->violations as $v) {
        $icon = match($v->severity) {
            'error' => '✗',
            'warning' => '⚠',
            default => 'ℹ',
        };
        echo "{$icon} {$v->severity}  [{$v->category}] {$v->file}";
        if ($v->line > 0) echo ":{$v->line}";
        echo "\n  {$v->message}\n";
        if ($v->code) echo "  {$v->code}\n";
    }

    echo "\n{$result->summary}\n";

    return $result->passed;
}
