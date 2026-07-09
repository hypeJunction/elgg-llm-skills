<?php

declare(strict_types=1);

namespace ElggMigrate\Rules\V3ToV4;

use ElggMigrate\AbstractRule;
use ElggMigrate\FileChange;
use ElggMigrate\Finding;
use ElggMigrate\RuleAnalysis;
use ElggMigrate\RuleResult;

/**
 * Replaces elgg_instanceof() calls with native PHP instanceof checks.
 *
 * elgg_instanceof() was removed in Elgg 4.x.
 *
 * Replacements:
 * - elgg_instanceof($e, 'object', 'blog') → $e instanceof \ElggObject && $e->getSubtype() === 'blog'
 * - elgg_instanceof($e, 'user')           → $e instanceof \ElggUser
 * - elgg_instanceof($e, 'group')          → $e instanceof \ElggGroup
 * - elgg_instanceof($e, 'site')           → $e instanceof \ElggSite
 */
final class ElggInstanceof extends AbstractRule
{
    private const TYPE_CLASS_MAP = [
        'object' => '\\ElggObject',
        'user'   => '\\ElggUser',
        'group'  => '\\ElggGroup',
        'site'   => '\\ElggSite',
    ];

    public function getId(): string
    {
        return 'elgg-instanceof-4x';
    }

    public function getDescription(): string
    {
        return 'Replace elgg_instanceof() with native PHP instanceof checks';
    }

    public function canAutomate(): bool
    {
        return true;
    }

    public function analyze(string $pluginPath): RuleAnalysis
    {
        $findings = [];

        foreach ($this->findPhpFiles($pluginPath) as $file) {
            $rel = $this->relativePath($pluginPath, $file);
            $code = file_get_contents($file);
            if ($code === false) continue;

            if (!str_contains($code, 'elgg_instanceof(')) {
                continue;
            }

            $count = substr_count($code, 'elgg_instanceof(');
            $findings[] = new Finding(
                file: $rel,
                line: $this->firstLineOf('/elgg_instanceof\s*\(/', $code),
                description: "elgg_instanceof() — removed in Elgg 4.x; replace with native instanceof ({$count} occurrence(s))",
                code: 'elgg_instanceof(',
            );
        }

        $applicable = count($findings) > 0;

        return new RuleAnalysis(
            ruleId: $this->getId(),
            applicable: $applicable,
            findings: $findings,
            summary: $applicable
                ? sprintf('Found %d file(s) with elgg_instanceof() calls', count($findings))
                : 'No elgg_instanceof() calls found',
        );
    }

    public function apply(string $pluginPath): RuleResult
    {
        $changes = [];

        foreach ($this->findPhpFiles($pluginPath) as $file) {
            $rel = $this->relativePath($pluginPath, $file);
            $code = file_get_contents($file);
            if ($code === false) continue;

            if (!str_contains($code, 'elgg_instanceof(')) {
                continue;
            }

            $original = $code;

            $pattern = '/elgg_instanceof\(\s*(\$\w+)\s*,\s*\'(\w+)\'\s*(?:,\s*\'(\w+)\')?\s*\)/';

            $code = preg_replace_callback($pattern, function (array $m): string {
                $var     = $m[1];
                $type    = $m[2];
                $subtype = $m[3] ?? '';

                $class = self::TYPE_CLASS_MAP[$type] ?? ('\\Elgg' . ucfirst($type));

                if ($subtype !== '') {
                    // Wrap the composite expression in parentheses so it stays
                    // correct under a leading `!` and inside `&&`/`||`/assignment.
                    // Without parens, `!elgg_instanceof($e, 'object', 'blog')`
                    // would become `!$e instanceof X && $e->getSubtype() === ...`
                    // which PHP parses as `(!$e instanceof X) && ...` — inverting
                    // only the first half of the check.
                    return "({$var} instanceof {$class} && {$var}->getSubtype() === '{$subtype}')";
                }

                return "{$var} instanceof {$class}";
            }, $code) ?? $code;

            if ($code !== $original) {
                file_put_contents($file, $code);
                $changes[] = new FileChange(
                    file: $rel,
                    type: 'modified',
                    description: 'Replaced elgg_instanceof() with native PHP instanceof checks',
                );
            }
        }

        return new RuleResult(
            ruleId: $this->getId(),
            success: true,
            changes: $changes,
        );
    }

    // -------------------------------------------------------------------------

    /**
     * Return the 1-based line number of the first regex match in $code.
     */
    private function firstLineOf(string $pattern, string $code): int
    {
        if (!preg_match($pattern, $code, $m, PREG_OFFSET_CAPTURE)) {
            return 0;
        }
        return substr_count(substr($code, 0, $m[0][1]), "\n") + 1;
    }
}
