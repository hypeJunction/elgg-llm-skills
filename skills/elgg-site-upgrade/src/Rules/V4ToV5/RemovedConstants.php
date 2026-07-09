<?php

declare(strict_types=1);

namespace ElggMigrate\Rules\V4ToV5;

use ElggMigrate\AbstractRule;
use ElggMigrate\FileChange;
use ElggMigrate\Finding;
use ElggMigrate\RuleAnalysis;
use ElggMigrate\RuleResult;

/**
 * Renames the misspelled REFERER constant to REFERRER in Elgg 5.0.
 *
 * Elgg defined a REFERER constant (misspelled) that was removed in 5.0.
 * The correct spelling REFERRER should be used instead.
 *
 * This rule only targets the bare constant token `REFERER` in PHP files.
 * It uses a word-boundary regex to avoid touching PHP's built-in
 * $_SERVER['HTTP_REFERER'] superglobal key strings, which are intentionally
 * spelled with one R per the HTTP spec.
 */
final class RemovedConstants extends AbstractRule
{
    /**
     * Map of old constant → new constant (both bare PHP constant names).
     *
     * @var array<string, string>
     */
    public const MAP = [
        'REFERER' => 'REFERRER',
    ];

    public function getId(): string
    {
        return 'removed-constants-5x';
    }

    public function getDescription(): string
    {
        return 'Rename REFERER constant to REFERRER (typo fixed in Elgg 5.0)';
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
            if ($code === false) {
                continue;
            }

            foreach (self::MAP as $old => $new) {
                // Match bare constant token — not inside strings or array key strings
                // Use token-safe word boundary: \b does not work on _ but REFERER
                // has no underscores at the boundary so \b is correct here.
                if (preg_match('/\b' . preg_quote($old, '/') . '\b/', $code)) {
                    // Exclude false positives: HTTP_REFERER (with leading HTTP_)
                    // and string literals like 'HTTP_REFERER'.
                    // We only flag if there's a standalone occurrence.
                    if (preg_match('/(?<![\'"\w])' . preg_quote($old, '/') . '(?![\'"\w])/', $code)) {
                        $findings[] = new Finding(
                            file: $relativePath,
                            line: 0,
                            description: "REFERER constant removed in 5.0 — rename to REFERRER",
                            code: '',
                        );
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
                ? sprintf('Found %d file(s) using removed REFERER constant', count($findings))
                : 'No usage of removed REFERER constant found',
        );
    }

    public function apply(string $pluginPath): RuleResult
    {
        $changes = [];

        foreach ($this->findPhpFiles($pluginPath) as $file) {
            $relativePath = $this->relativePath($pluginPath, $file);
            $code = file_get_contents($file);
            if ($code === false) {
                continue;
            }

            $original = $code;

            foreach (self::MAP as $old => $new) {
                // Replace bare REFERER (not preceded or followed by word chars or quotes)
                // Preserves HTTP_REFERER superglobal key strings untouched.
                $code = preg_replace(
                    '/(?<![\'"\w])' . preg_quote($old, '/') . '(?![\'"\w])/',
                    $new,
                    $code,
                );
            }

            if ($code !== $original) {
                file_put_contents($file, $code);
                $changes[] = new FileChange(
                    file: $relativePath,
                    type: 'modified',
                    description: 'Renamed REFERER constant to REFERRER (Elgg 5.0)',
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
