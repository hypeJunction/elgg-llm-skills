<?php

declare(strict_types=1);

namespace ElggMigrate\Rules\V4ToV5;

use ElggMigrate\AbstractRule;
use ElggMigrate\FileChange;
use ElggMigrate\Finding;
use ElggMigrate\RuleAnalysis;
use ElggMigrate\RuleResult;

/**
 * Replaces the fzaninotto/faker Composer dependency with fakerphp/faker.
 *
 * Elgg 5.x switched from the abandoned fzaninotto/faker package to the
 * community-maintained fakerphp/faker fork. The Faker namespace did not
 * change, so PHP source files need no edits — only composer.json.
 */
final class FakerLibrary extends AbstractRule
{
    private const OLD_PACKAGE = 'fzaninotto/faker';
    private const NEW_PACKAGE = 'fakerphp/faker';

    public function getId(): string
    {
        return 'faker-library-5x';
    }

    public function getDescription(): string
    {
        return 'Replace fzaninotto/faker with fakerphp/faker in composer.json';
    }

    public function canAutomate(): bool
    {
        return true;
    }

    public function analyze(string $pluginPath): RuleAnalysis
    {
        $findings = [];

        $composerPath = $pluginPath . '/composer.json';
        if (!is_file($composerPath)) {
            return new RuleAnalysis(
                ruleId: $this->getId(),
                applicable: false,
                findings: [],
                summary: 'No composer.json found',
            );
        }

        $json = json_decode(file_get_contents($composerPath), true);

        foreach (['require', 'require-dev'] as $section) {
            if (isset($json[$section][self::OLD_PACKAGE])) {
                $constraint = $json[$section][self::OLD_PACKAGE];
                $findings[] = new Finding(
                    'composer.json',
                    0,
                    "{$section}.fzaninotto/faker \"{$constraint}\" should be replaced with fakerphp/faker",
                    '',
                );
            }
        }

        $applicable = count($findings) > 0;

        return new RuleAnalysis(
            ruleId: $this->getId(),
            applicable: $applicable,
            findings: $findings,
            summary: $applicable
                ? 'Found fzaninotto/faker dependency to replace with fakerphp/faker'
                : 'No fzaninotto/faker dependency found',
        );
    }

    public function apply(string $pluginPath): RuleResult
    {
        $changes = [];

        $composerPath = $pluginPath . '/composer.json';
        if (!is_file($composerPath)) {
            return new RuleResult(
                ruleId: $this->getId(),
                success: true,
                changes: [],
            );
        }

        $raw = file_get_contents($composerPath);
        $json = json_decode($raw, true);
        $modified = false;

        foreach (['require', 'require-dev'] as $section) {
            if (!isset($json[$section][self::OLD_PACKAGE])) {
                continue;
            }

            $constraint = $json[$section][self::OLD_PACKAGE];

            // Remove old package and add new one with the same version constraint
            unset($json[$section][self::OLD_PACKAGE]);
            $json[$section][self::NEW_PACKAGE] = $constraint;

            $changes[] = new FileChange(
                'composer.json',
                'modified',
                "Replaced {$section}.fzaninotto/faker with fakerphp/faker (constraint: \"{$constraint}\")",
            );
            $modified = true;
        }

        if ($modified) {
            file_put_contents($composerPath, json_encode($json, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
        }

        return new RuleResult(
            ruleId: $this->getId(),
            success: true,
            changes: $changes,
        );
    }
}
