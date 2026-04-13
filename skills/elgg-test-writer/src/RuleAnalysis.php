<?php

declare(strict_types=1);

namespace ElggMigrate;

/**
 * Result of analyzing a plugin for a specific migration rule.
 */
final class RuleAnalysis
{
    /**
     * @param string $ruleId Which rule produced this analysis
     * @param bool $applicable Whether this rule applies to the plugin
     * @param array<Finding> $findings Individual occurrences that need migration
     * @param string $summary Human-readable summary
     */
    public function __construct(
        public readonly string $ruleId,
        public readonly bool $applicable,
        public readonly array $findings,
        public readonly string $summary,
    ) {}
}
