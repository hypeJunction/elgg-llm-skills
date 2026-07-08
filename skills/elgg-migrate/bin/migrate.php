#!/usr/bin/env php
<?php

/**
 * Run automated migration rules on a plugin directory.
 *
 * Usage: php bin/migrate.php <manifest> <plugin-path> [--dry-run] [--report] [--no-guard] [--verify] [--security] [--no-tests]
 *
 * A TESTS-FIRST gate runs before any transform is applied (Iron Law 4): the plugin
 * must ship a test suite (incl. MigrationRegressionTest) AND a passing baseline
 * record, or migrate.php refuses with exit code 7. Waive with --no-tests (logged).
 *
 * Example:
 *   php bin/migrate.php rules/2x-to-3x/manifest.json tmp/hypeWall
 *   php bin/migrate.php rules/2x-to-3x/manifest.json tmp/hypeWall --dry-run
 *   php bin/migrate.php rules/3x-to-4x/manifest.json tmp/hypeWall --verify --security
 */

require_once __DIR__ . '/../vendor/autoload.php';

use ElggMigrate\DependencyAudit;
use ElggMigrate\PostMigrationVerifier;
use ElggMigrate\RuleRunner;
use ElggMigrate\SecuritySweep;
use ElggMigrate\VersionGuard;

$args = array_slice($argv, 1);
$dryRun = in_array('--dry-run', $args);
$report = in_array('--report', $args);
$noGuard = in_array('--no-guard', $args);
$verify = in_array('--verify', $args);
$security = in_array('--security', $args);
$audit = in_array('--audit', $args);
$checkOnly = in_array('--check', $args);
$strictCompleteness = in_array('--strict-completeness', $args);

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
    fwrite(STDERR, "  --check                 Only run the incomplete-migration check (scans for prior-version\n");
    fwrite(STDERR, "                          patterns left over after a previous migration attempt). Exit 0 if\n");
    fwrite(STDERR, "                          none; exit 6 if findings.\n");
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
    if ($verify || $security || $audit) {
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
    if (!runTestsFirstGate($pluginPath, $manifest, $baselineOverride)) {
        exit(7);
    }
} else {
    logTestsBypass($pluginPath, $manifest);
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

exit($exitCode);

// --- Helper functions ---

/**
 * TESTS-FIRST gate. Returns true if the plugin is safe to migrate (a test suite
 * incl. MigrationRegressionTest exists AND a passing baseline was captured on the
 * current code). On failure it prints a targeted remediation and returns false so
 * the caller can `exit(7)` before mutating a single file.
 */
function runTestsFirstGate(string $pluginPath, array $manifest, ?string $baselineOverride): bool
{
    echo "--- TESTS-FIRST GATE (required before any transform) ---\n";

    $targetMajor = detectTargetMajor($manifest);
    $problems = [];

    // (1) A PHPUnit config must exist.
    $phpunitConfig = findPhpunitConfig($pluginPath);
    if ($phpunitConfig === null) {
        $problems[] = 'NO TEST SUITE — no phpunit.xml(.dist) found (checked tests/ and plugin root).';
    } else {
        echo "  ✓ test config: {$phpunitConfig}\n";
    }

    // (2) The RED→GREEN regression guard must be present.
    $regression = findMigrationRegressionTest($pluginPath);
    if ($regression === null) {
        $problems[] = 'NO MigrationRegressionTest — the RED-before/GREEN-after guard is missing.';
    } else {
        echo "  ✓ regression guard: {$regression}\n";
    }

    // (3) A passing baseline must have been captured on the CURRENT code.
    $baselineFile = resolveBaselineFile($pluginPath, $baselineOverride);
    if ($baselineFile === null) {
        $problems[] = 'NO BASELINE RECORD — run the suite on the CURRENT code and record a passing baseline.';
    } else {
        [$ok, $reason] = validateBaseline($baselineFile, $targetMajor);
        if ($ok) {
            echo "  ✓ baseline: {$baselineFile} (PASS)\n";
        } else {
            $problems[] = "BASELINE NOT USABLE — {$reason}";
        }
    }

    if (empty($problems)) {
        echo "  ✓ tests-first gate satisfied — proceeding to apply transforms.\n\n";
        return true;
    }

    $skillRoot = dirname(__DIR__);
    $targetLabel = $targetMajor !== null ? (string) $targetMajor : 'N';
    $baselinePath = rtrim($pluginPath, '/') . '/tests/.migration-baseline.json';

    fwrite(STDERR, "\n✗ TESTS-FIRST GATE FAILED — refusing to migrate {$pluginPath}\n");
    foreach ($problems as $p) {
        fwrite(STDERR, "  • {$p}\n");
    }
    fwrite(STDERR, "\n  Why: migration transforms are irreversible mutations. Without a passing\n");
    fwrite(STDERR, "  baseline on the CURRENT code you cannot tell whether a later failure is a\n");
    fwrite(STDERR, "  regression the migration introduced or a bug that was already there.\n");
    fwrite(STDERR, "\n  Remediation:\n");
    fwrite(STDERR, "  1. Generate the tests-first suite (BaselineTest + MigrationRegressionTest):\n");
    fwrite(STDERR, "       {$skillRoot}/../elgg-test-writer/bin/scaffold-smoke-tests.sh \\\n");
    fwrite(STDERR, "         --plugin-dir={$pluginPath} --target-version=elgg{$targetLabel}\n");
    fwrite(STDERR, "     (the guard mirrors references/migration-failure-catalog.md — RED before,\n");
    fwrite(STDERR, "      GREEN after; see references/migration-failure-catalog.md for each class.)\n");
    fwrite(STDERR, "  2. Run the suite against the CURRENT-version Docker stack. It MUST pass\n");
    fwrite(STDERR, "     (BaselineTest GREEN; MigrationRegressionTest RED is expected pre-migration).\n");
    fwrite(STDERR, "  3. Record the passing baseline so this gate can see it:\n");
    fwrite(STDERR, "       cat > {$baselinePath} <<'JSON'\n");
    fwrite(STDERR, "       {\"status\":\"pass\",\"target_major\":{$targetLabel},\n");
    fwrite(STDERR, "        \"captured_at\":\"<ISO8601>\",\"phpunit\":{\"failures\":0,\"errors\":0}}\n");
    fwrite(STDERR, "       JSON\n");
    fwrite(STDERR, "  4. Re-run this command. After the transform, re-run the suite on the TARGET\n");
    fwrite(STDERR, "     stack — BaselineTest must stay GREEN, MigrationRegressionTest must flip GREEN.\n");
    fwrite(STDERR, "\n  Override (unsafe, logged): pass --no-tests to bypass this gate.\n");

    return false;
}

/** Extract the numeric target major (e.g. "4.x" → 4) from the manifest 'to'. */
function detectTargetMajor(array $manifest): ?int
{
    $to = (string) ($manifest['to'] ?? '');
    if (preg_match('/(\d+)/', $to, $m)) {
        return (int) $m[1];
    }
    return null;
}

/** First PHPUnit config found under tests/ or the plugin root, relative path or null. */
function findPhpunitConfig(string $pluginPath): ?string
{
    foreach (['tests/phpunit.xml', 'tests/phpunit.xml.dist', 'phpunit.xml', 'phpunit.xml.dist'] as $rel) {
        if (is_file(rtrim($pluginPath, '/') . '/' . $rel)) {
            return $rel;
        }
    }
    return null;
}

/** Locate MigrationRegressionTest.php anywhere under tests/; relative path or null. */
function findMigrationRegressionTest(string $pluginPath): ?string
{
    $base = rtrim($pluginPath, '/') . '/tests';
    if (!is_dir($base)) {
        return null;
    }
    $it = new \RecursiveIteratorIterator(
        new \RecursiveDirectoryIterator($base, \FilesystemIterator::SKIP_DOTS)
    );
    foreach ($it as $f) {
        if ($f->getFilename() === 'MigrationRegressionTest.php') {
            return ltrim(substr($f->getPathname(), strlen(rtrim($pluginPath, '/'))), '/');
        }
    }
    return null;
}

/** Resolve the baseline record path: override → env → tests/ → plugin root. */
function resolveBaselineFile(string $pluginPath, ?string $override): ?string
{
    $candidates = [];
    if ($override !== null && $override !== '') {
        $candidates[] = $override;
    }
    $env = getenv('ELGG_MIGRATE_BASELINE');
    if ($env !== false && $env !== '') {
        $candidates[] = $env;
    }
    $candidates[] = rtrim($pluginPath, '/') . '/tests/.migration-baseline.json';
    $candidates[] = rtrim($pluginPath, '/') . '/.migration-baseline.json';

    foreach ($candidates as $c) {
        if (is_file($c)) {
            return $c;
        }
    }
    return null;
}

/**
 * Validate a baseline record: must be JSON, status pass/green, zero PHPUnit
 * failures/errors, and (if it declares a target_major) match this run's target.
 *
 * @return array{0:bool,1:string} [ok, reason-if-not-ok]
 */
function validateBaseline(string $file, ?int $targetMajor): array
{
    $data = json_decode((string) file_get_contents($file), true);
    if (!is_array($data)) {
        return [false, "baseline file is not valid JSON: {$file}"];
    }

    $status = strtolower((string) ($data['status'] ?? ''));
    if (!in_array($status, ['pass', 'passed', 'green', 'ok'], true)) {
        return [false, "baseline status is '" . ($status ?: '(missing)') . "', expected 'pass' — the suite must be GREEN on the CURRENT code before migrating ({$file})"];
    }

    $pu = is_array($data['phpunit'] ?? null) ? $data['phpunit'] : [];
    $failures = (int) ($pu['failures'] ?? 0);
    $errors = (int) ($pu['errors'] ?? 0);
    if ($failures > 0 || $errors > 0) {
        return [false, "baseline records {$failures} failure(s) / {$errors} error(s) — a red baseline cannot prove the migration preserved behavior ({$file})"];
    }

    if ($targetMajor !== null && isset($data['target_major']) && (int) $data['target_major'] !== $targetMajor) {
        return [false, "baseline was captured for target Elgg {$data['target_major']}.x but this run targets {$targetMajor}.x — re-capture it for this step ({$file})"];
    }

    return [true, ''];
}

/** Loudly announce (and persist, if a state dir is set) a --no-tests bypass. */
function logTestsBypass(string $pluginPath, array $manifest): void
{
    $msg = sprintf(
        '[%s] TESTS-FIRST GATE BYPASSED (--no-tests) for %s (%s→%s) — migrating WITHOUT a regression baseline',
        date('c'),
        basename(rtrim($pluginPath, '/')),
        (string) ($manifest['from'] ?? '?'),
        (string) ($manifest['to'] ?? '?')
    );

    fwrite(STDERR, "\n⚠⚠⚠  {$msg}\n");
    fwrite(STDERR, "     Unsafe: no RED→GREEN proof the migration preserved behavior.\n");
    fwrite(STDERR, "     See SKILL.md 'Tests-first' and references/migration-failure-catalog.md.\n\n");

    // Persist to the job state dir if one is configured. NEVER write into the
    // plugin dir (skill invariant) or the skill dir.
    $stateDir = getenv('ELGG_MIGRATE_STATE');
    if ($stateDir !== false && $stateDir !== '' && is_dir($stateDir)) {
        @file_put_contents(rtrim($stateDir, '/') . '/tests-bypass.log', $msg . "\n", FILE_APPEND);
    }
}

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
