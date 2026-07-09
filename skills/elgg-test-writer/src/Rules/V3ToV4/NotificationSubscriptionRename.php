<?php

declare(strict_types=1);

namespace ElggMigrate\Rules\V3ToV4;

use ElggMigrate\AbstractRule;
use ElggMigrate\FileChange;
use ElggMigrate\Finding;
use ElggMigrate\RuleAnalysis;
use ElggMigrate\RuleResult;

/**
 * Renames notification subscription relationship strings in Elgg 4.x.
 *
 * The relationship name format changed: notifyemail → notify:email, etc.
 */
final class NotificationSubscriptionRename extends AbstractRule
{
    private const REPLACEMENTS = [
        "'notifyemail'" => "'notify:email'",
        '"notifyemail"' => '"notify:email"',
        "'notifysite'"  => "'notify:site'",
        '"notifysite"'  => '"notify:site"',
        "'notifyweb'"   => "'notify:web'",
        '"notifyweb"'   => '"notify:web"',
        "'notifysms'"   => "'notify:sms'",
        '"notifysms"'   => '"notify:sms"',
    ];

    public function getId(): string
    {
        return 'notification-subscription-rename-4x';
    }

    public function getDescription(): string
    {
        return 'Rename notification subscription relationship names (notifyemail → notify:email)';
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

            foreach (self::REPLACEMENTS as $old => $new) {
                if (str_contains($code, $old)) {
                    $count = substr_count($code, $old);
                    $findings[] = new Finding(
                        file: $relativePath,
                        line: 0,
                        description: "{$old} → {$new} ({$count} occurrence(s))",
                        code: '',
                    );
                }
            }
        }

        $applicable = count($findings) > 0;

        return new RuleAnalysis(
            ruleId: $this->getId(),
            applicable: $applicable,
            findings: $findings,
            summary: $applicable
                ? sprintf('Found %d notification subscription relationship name(s) to rename', count($findings))
                : 'No old notification subscription relationship names found',
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
                    description: 'Renamed notification subscription relationship names to notify:method format',
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
