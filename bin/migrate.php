#!/usr/bin/env php
<?php

/**
 * Run automated migration rules on a plugin directory.
 *
 * Usage: php bin/migrate.php <manifest> <plugin-path> [--dry-run] [--report] [--no-guard] [--verify] [--security]
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
$args = array_filter($args, fn($a) => !str_starts_with($a, '--'));
$args = array_values($args);

if (count($args) < 2) {
    fwrite(STDERR, "Usage: php bin/migrate.php <manifest.json> <plugin-path> [--dry-run] [--report] [--no-guard] [--verify] [--security] [--audit]\n");
    fwrite(STDERR, "\nFlags:\n");
    fwrite(STDERR, "  --dry-run    Analyze only, don't modify files\n");
    fwrite(STDERR, "  --report     Show LLM instructions for manual rules\n");
    fwrite(STDERR, "  --no-guard   Skip version guard (not recommended)\n");
    fwrite(STDERR, "  --verify     Run post-migration version boundary check\n");
    fwrite(STDERR, "  --security   Run security sweep (pattern-based) after migration\n");
    fwrite(STDERR, "  --audit      Run composer audit for dependency CVEs\n");
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

// Version guard check (validates AND prints)
if (!$noGuard) {
    try {
        $manifest = $runner->loadManifest($manifestPath);
        $guard = new VersionGuard();
        $detected = $guard->detectVersion($pluginPath);
        echo "--- VERSION CHECK ---\n";
        echo "Detected plugin version: {$detected}\n";
        echo "Manifest: {$manifest['from']} → {$manifest['to']}\n";
        // Actually validate (not just print)
        $guard->validate($pluginPath, $manifest);
        echo "✓ Version match confirmed\n\n";
    } catch (\ElggMigrate\VersionMismatchException $e) {
        fwrite(STDERR, "\n✗ VERSION MISMATCH\n");
        fwrite(STDERR, "  {$e->getMessage()}\n");
        fwrite(STDERR, "\n  Detected: {$e->detectedVersion}\n");
        fwrite(STDERR, "  Expected: {$e->expectedVersion}\n");
        fwrite(STDERR, "\n  Use --no-guard to skip this check (not recommended).\n");
        exit(2);
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

    // Still run verification, security, and audit in dry-run mode (read-only)
    if ($verify || $security || $audit) {
        echo "\n";
    }
    if ($verify) {
        $manifest = $runner->loadManifest($manifestPath);
        runVerification($pluginPath, $manifest['to']);
    }
    if ($security) {
        runSecuritySweep($pluginPath);
    }
    if ($audit) {
        runDependencyAudit($pluginPath);
    }

    exit(0);
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
