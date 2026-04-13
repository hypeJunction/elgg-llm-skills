<?php

declare(strict_types=1);

namespace ElggMigrate\Rules\V2ToV3;

use ElggMigrate\AbstractRule;
use ElggMigrate\FileChange;
use ElggMigrate\Finding;
use ElggMigrate\RuleAnalysis;
use ElggMigrate\RuleResult;

/**
 * Detects raw SQL strings that reference removed entity subtables.
 *
 * In Elgg 3.x, these tables were removed:
 * - groups_entity
 * - users_entity
 * - objects_entity
 * - sites_entity
 *
 * This is a warn-only rule: it adds a WARNING comment but does not
 * remove the code, since the SQL needs manual rewriting.
 */
final class SubtableJoinWarning extends AbstractRule
{
    public const REMOVED_TABLES = [
        'groups_entity',
        'users_entity',
        'objects_entity',
        'sites_entity',
    ];

    public function getId(): string
    {
        return 'subtable-join-warning';
    }

    public function getDescription(): string
    {
        return 'Detect and warn about raw SQL referencing removed entity subtables';
    }

    public function canAutomate(): bool
    {
        return true;
    }

    public function analyze(string $pluginPath): RuleAnalysis
    {
        $findings = [];

        foreach ($this->findPhpFiles($pluginPath) as $file) {
            $relativePath = $this->relativePath($pluginPath, $file);
            $code = file_get_contents($file);
            if ($code === false) continue;

            $lines = explode("\n", $code);
            foreach ($lines as $i => $line) {
                foreach (self::REMOVED_TABLES as $table) {
                    if (str_contains($line, $table)) {
                        // Skip lines that are already warning comments from a previous apply
                        if (str_contains($line, '// WARNING:') || str_contains($line, '/* WARNING:')) {
                            continue;
                        }
                        $findings[] = new Finding(
                            file: $relativePath,
                            line: $i + 1,
                            description: "References removed subtable: {$table}",
                            code: trim($line),
                        );
                        break; // One finding per line, even if multiple tables
                    }
                }
            }
        }

        $applicable = count($findings) > 0;

        return new RuleAnalysis(
            ruleId: $this->getId(),
            applicable: $applicable,
            findings: $findings,
            summary: $applicable
                ? sprintf('Found %d reference(s) to removed entity subtables', count($findings))
                : 'No references to removed entity subtables found',
        );
    }

    public function apply(string $pluginPath): RuleResult
    {
        $changes = [];
        $warnings = [];

        foreach ($this->findPhpFiles($pluginPath) as $file) {
            $relativePath = $this->relativePath($pluginPath, $file);
            $code = file_get_contents($file);
            if ($code === false) continue;

            $lines = explode("\n", $code);
            $modified = false;

            for ($i = count($lines) - 1; $i >= 0; $i--) {
                $line = $lines[$i];
                foreach (self::REMOVED_TABLES as $table) {
                    if (str_contains($line, $table)) {
                        // Skip lines that are already warning comments
                        if (str_contains($line, '// WARNING:') || str_contains($line, '/* WARNING:')) {
                            continue;
                        }
                        // Check if previous line already has the warning
                        if ($i > 0 && str_contains($lines[$i - 1], "// WARNING: {$table}")) {
                            continue;
                        }
                        // Get leading whitespace from current line
                        preg_match('/^(\s*)/', $line, $m);
                        $indent = $m[1] ?? '';
                        $warningComment = "{$indent}// WARNING: {$table} subtable removed in Elgg 3.0 — rewrite this SQL";
                        array_splice($lines, $i, 0, [$warningComment]);
                        $modified = true;
                        $warnings[] = "{$relativePath}:{$table} subtable reference needs manual SQL rewrite";
                        break; // One warning per line
                    }
                }
            }

            if ($modified) {
                file_put_contents($file, implode("\n", $lines));
                $changes[] = new FileChange(
                    file: $relativePath,
                    type: 'modified',
                    description: 'Added warnings for removed subtable references',
                );
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
