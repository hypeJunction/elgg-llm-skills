<?php

declare(strict_types=1);

namespace ElggMigrate\Rules\V3ToV4;

use ElggMigrate\AbstractRule;
use ElggMigrate\FileChange;
use ElggMigrate\Finding;
use ElggMigrate\RuleAnalysis;
use ElggMigrate\RuleResult;

/**
 * Renames Elgg\Database to Elgg\Application\Database in Elgg 4.x.
 *
 * Only the top-level class is renamed; sub-namespace classes like
 * Elgg\Database\QueryBuilder are NOT affected.
 */
final class DatabaseClassRename extends AbstractRule
{
    private const REPLACEMENTS = [
        'use Elgg\\Database;'      => 'use Elgg\\Application\\Database;',
        'use Elgg\\Database as'    => 'use Elgg\\Application\\Database as',
        '\\Elgg\\Database '        => '\\Elgg\\Application\\Database ',
        ': \\Elgg\\Database'       => ': \\Elgg\\Application\\Database',
    ];

    public function getId(): string
    {
        return 'database-class-rename-4x';
    }

    public function getDescription(): string
    {
        return 'Rename Elgg\\Database class to Elgg\\Application\\Database';
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

            $count = 0;
            foreach (self::REPLACEMENTS as $old => $new) {
                if (str_contains($code, $old)) {
                    $count += substr_count($code, $old);
                }
            }

            if ($count > 0) {
                $findings[] = new Finding(
                    file: $relativePath,
                    line: 0,
                    description: "Found {$count} Elgg\\Database reference(s) to rename to Elgg\\Application\\Database",
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
                ? sprintf('Found %d file(s) with Elgg\\Database references to rename', count($findings))
                : 'No Elgg\\Database references found',
        );
    }

    public function apply(string $pluginPath): RuleResult
    {
        $changes = [];

        foreach ($this->findPhpFiles($pluginPath) as $file) {
            $relativePath = $this->relativePath($pluginPath, $file);
            $code = file_get_contents($file);
            if ($code === false) continue;

            $original = $code;
            foreach (self::REPLACEMENTS as $old => $new) {
                $code = str_replace($old, $new, $code);
            }

            if ($code !== $original) {
                file_put_contents($file, $code);
                $changes[] = new FileChange(
                    file: $relativePath,
                    type: 'modified',
                    description: 'Renamed Elgg\\Database to Elgg\\Application\\Database',
                );
            }
        }

        return new RuleResult(
            ruleId: $this->getId(),
            success: true,
            changes: $changes,
        );
    }
}
