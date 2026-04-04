#!/usr/bin/env php
<?php

/**
 * Run automated migration rules on a plugin directory.
 *
 * Usage: php bin/migrate.php <manifest> <plugin-path> [--dry-run] [--report]
 *
 * Example:
 *   php bin/migrate.php rules/2x-to-3x/manifest.json tmp/hypeWall
 *   php bin/migrate.php rules/2x-to-3x/manifest.json tmp/hypeWall --dry-run
 */

require_once __DIR__ . '/../vendor/autoload.php';

use ElggMigrate\RuleRunner;

$args = array_slice($argv, 1);
$dryRun = in_array('--dry-run', $args);
$report = in_array('--report', $args);
$args = array_filter($args, fn($a) => !str_starts_with($a, '--'));
$args = array_values($args);

if (count($args) < 2) {
    fwrite(STDERR, "Usage: php bin/migrate.php <manifest.json> <plugin-path> [--dry-run] [--report]\n");
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

$runner = new RuleRunner();

echo "=== Migration: {$manifestPath} → {$pluginPath} ===\n\n";

// Analyze phase
echo "--- ANALYSIS ---\n\n";
$analyses = $runner->analyzeAll($manifestPath, $pluginPath);
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
    exit(0);
}

// Apply phase
echo "--- APPLYING AUTOMATED RULES ---\n\n";
$results = $runner->applyAll($manifestPath, $pluginPath);

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
