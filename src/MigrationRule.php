<?php

declare(strict_types=1);

namespace ElggMigrate;

/**
 * A single migration rule that handles one specific breaking change.
 *
 * Rules are composable units — each handles exactly one transformation
 * (e.g., "convert page handlers to named routes"). They compose into
 * version manifests for full major-version migrations.
 */
interface MigrationRule
{
    /**
     * Machine-readable identifier, e.g. "page-handler-to-route".
     */
    public function getId(): string;

    /**
     * Human-readable description of what this rule does.
     */
    public function getDescription(): string;

    /**
     * Whether this rule can be fully automated via AST transformation.
     * If false, the manifest should include LLM instructions instead.
     */
    public function canAutomate(): bool;

    /**
     * Scan a plugin directory and report what needs changing.
     * This is read-only — it must not modify any files.
     */
    public function analyze(string $pluginPath): RuleAnalysis;

    /**
     * Apply the transformation to the plugin files.
     * Returns a result describing what was changed.
     */
    public function apply(string $pluginPath): RuleResult;
}
