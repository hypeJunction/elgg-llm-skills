<?php

declare(strict_types=1);

namespace ElggMigrate\Rules\V3ToV4;

use ElggMigrate\AbstractRule;
use ElggMigrate\FileChange;
use ElggMigrate\Finding;
use ElggMigrate\RuleAnalysis;
use ElggMigrate\RuleResult;

/**
 * Handles constants removed or renamed in Elgg 4.x.
 *
 * Auto-replaced:
 * - ElggRelationship::RELATIONSHIP_LIMIT → Elgg\Database\RelationshipsTable::RELATIONSHIP_COLUMN_LENGTH
 *
 * Warn-only (no safe auto-fix):
 * - ELGG_PLUGIN_USER_SETTING_PREFIX — removed with no equivalent
 * - ELGG_PLUGIN_INTERNAL_PREFIX    — removed; if used for priority, use \ElggPlugin::PRIORITY_SETTING_NAME
 * - ORIGIN_SUBSCRIPTIONS / ORIGIN_INSTANT — removed from Elgg\Notifications\Notification
 */
final class RemovedConstants extends AbstractRule
{
    private const AUTO_REPLACEMENTS = [
        '\\ElggRelationship::RELATIONSHIP_LIMIT' => '\\Elgg\\Database\\RelationshipsTable::RELATIONSHIP_COLUMN_LENGTH',
        'ElggRelationship::RELATIONSHIP_LIMIT'   => 'Elgg\\Database\\RelationshipsTable::RELATIONSHIP_COLUMN_LENGTH',
    ];

    private const WARN_ONLY = [
        'ELGG_PLUGIN_USER_SETTING_PREFIX' => 'Removed — no equivalent; audit all usages',
        'ELGG_PLUGIN_INTERNAL_PREFIX'     => 'Removed — if used for the priority setting, use \\ElggPlugin::PRIORITY_SETTING_NAME',
        'ORIGIN_SUBSCRIPTIONS'            => 'Elgg\\Notifications\\Notification::ORIGIN_SUBSCRIPTIONS removed in 4.x',
        'ORIGIN_INSTANT'                  => 'Elgg\\Notifications\\Notification::ORIGIN_INSTANT removed in 4.x',
    ];

    public function getId(): string
    {
        return 'removed-constants-4x';
    }

    public function getDescription(): string
    {
        return 'Replace or flag constants removed in Elgg 4.x';
    }

    public function canAutomate(): bool
    {
        return true;
    }

    public function analyze(string $pluginPath): RuleAnalysis
    {
        $findings = [];

        foreach ($this->findPhpFiles($pluginPath) as $file) {
            $rel  = $this->relativePath($pluginPath, $file);
            $code = file_get_contents($file);
            if ($code === false) continue;

            foreach (self::AUTO_REPLACEMENTS as $old => $new) {
                if (!str_contains($code, $old)) continue;
                $count = substr_count($code, $old);
                $findings[] = new Finding(
                    file: $rel,
                    line: 0,
                    description: "{$old} → {$new} ({$count} occurrence(s))",
                    code: '',
                );
            }

            foreach (self::WARN_ONLY as $constant => $note) {
                if (!str_contains($code, $constant)) continue;
                $count = substr_count($code, $constant);
                $findings[] = new Finding(
                    file: $rel,
                    line: 0,
                    description: "{$constant}: {$note} ({$count} occurrence(s))",
                    code: '',
                );
            }
        }

        $applicable = count($findings) > 0;

        return new RuleAnalysis(
            ruleId: $this->getId(),
            applicable: $applicable,
            findings: $findings,
            summary: $applicable
                ? sprintf('Found %d removed constant reference(s)', count($findings))
                : 'No removed constants found',
        );
    }

    public function apply(string $pluginPath): RuleResult
    {
        $changes  = [];
        $warnings = [];

        foreach ($this->findPhpFiles($pluginPath) as $file) {
            $rel  = $this->relativePath($pluginPath, $file);
            $code = file_get_contents($file);
            if ($code === false) continue;

            $original = $code;

            // Auto-replace — process longest key first to avoid partial matches
            foreach (self::AUTO_REPLACEMENTS as $old => $new) {
                $code = str_replace($old, $new, $code);
            }

            if ($code !== $original) {
                file_put_contents($file, $code);
                $changes[] = new FileChange(
                    file: $rel,
                    type: 'modified',
                    description: 'Replaced ElggRelationship::RELATIONSHIP_LIMIT with RelationshipsTable::RELATIONSHIP_COLUMN_LENGTH',
                );
            }

            foreach (self::WARN_ONLY as $constant => $note) {
                if (str_contains($code, $constant)) {
                    $warnings[] = "{$rel}: {$constant} — {$note}";
                }
            }
        }

        return new RuleResult(
            ruleId: $this->getId(),
            success: true,
            changes: $changes,
            warnings: $warnings,
        );
    }
}
