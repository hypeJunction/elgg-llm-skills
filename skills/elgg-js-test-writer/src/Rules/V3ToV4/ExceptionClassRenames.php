<?php

declare(strict_types=1);

namespace ElggMigrate\Rules\V3ToV4;

use ElggMigrate\AbstractRule;
use ElggMigrate\FileChange;
use ElggMigrate\Finding;
use ElggMigrate\RuleAnalysis;
use ElggMigrate\RuleResult;

/**
 * Renames exception classes moved to Elgg\Exceptions namespace in 4.0.
 *
 * ~30 exception classes were moved. Affects catch blocks, throw statements,
 * use statements, instanceof checks, and type hints.
 */
final class ExceptionClassRenames extends AbstractRule
{
    /**
     * Map of old fully-qualified class name → new fully-qualified class name.
     * Both without leading backslash.
     */
    public const MAP = [
        // Root-level exceptions → Elgg\Exceptions
        'ClassException' => 'Elgg\\Exceptions\\ClassException',
        'ConfigurationException' => 'Elgg\\Exceptions\\ConfigurationException',
        'CronException' => 'Elgg\\Exceptions\\CronException',
        'DatabaseException' => 'Elgg\\Exceptions\\DatabaseException',
        'DataFormatException' => 'Elgg\\Exceptions\\DataFormatException',
        'InstallationException' => 'Elgg\\Exceptions\\Configuration\\InstallationException',
        'InvalidParameterException' => 'Elgg\\Exceptions\\InvalidParameterException',
        'IOException' => 'Elgg\\Exceptions\\FileSystem\\IOException',
        'LoginException' => 'Elgg\\Exceptions\\LoginException',
        'PluginException' => 'Elgg\\Exceptions\\PluginException',
        'RegistrationException' => 'Elgg\\Exceptions\\Configuration\\RegistrationException',
        'SecurityException' => 'Elgg\\Exceptions\\SecurityException',

        // Elgg\* exceptions → Elgg\Exceptions\Http\*
        'Elgg\\BadRequestException' => 'Elgg\\Exceptions\\Http\\BadRequestException',
        'Elgg\\CsrfException' => 'Elgg\\Exceptions\\Http\\CsrfException',
        'Elgg\\EntityNotFoundException' => 'Elgg\\Exceptions\\Http\\EntityNotFoundException',
        'Elgg\\EntityPermissionsException' => 'Elgg\\Exceptions\\Http\\EntityPermissionsException',
        'Elgg\\GatekeeperException' => 'Elgg\\Exceptions\\Http\\GatekeeperException',
        'Elgg\\GroupGatekeeperException' => 'Elgg\\Exceptions\\Http\\Gatekeeper\\GroupGatekeeperException',
        'Elgg\\HttpException' => 'Elgg\\Exceptions\\HttpException',
        'Elgg\\PageNotFoundException' => 'Elgg\\Exceptions\\Http\\PageNotFoundException',
        'Elgg\\ValidationException' => 'Elgg\\Exceptions\\Http\\ValidationException',
        'Elgg\\WalledGardenException' => 'Elgg\\Exceptions\\Http\\Gatekeeper\\WalledGardenException',

        // Deeper namespace moves
        'Elgg\\Database\\EntityTable\\UserFetchFailureException' => 'Elgg\\Exceptions\\Database\\UserFetchFailureException',
        'Elgg\\Di\\FactoryUncallableException' => 'Elgg\\Exceptions\\Di\\FactoryUncallableException',
        'Elgg\\Di\\MissingValueException' => 'Elgg\\Exceptions\\Di\\MissingValueException',
        'Elgg\\Http\\Exception\\AdminGatekeeperException' => 'Elgg\\Exceptions\\Http\\Gatekeeper\\AdminGatekeeperException',
        'Elgg\\Http\\Exception\\AjaxGatekeeperException' => 'Elgg\\Exceptions\\Http\\Gatekeeper\\AjaxGatekeeperException',
        'Elgg\\Http\\Exception\\GroupToolGatekeeperException' => 'Elgg\\Exceptions\\Http\\Gatekeeper\\GroupToolGatekeeperException',
        'Elgg\\Http\\Exception\\LoggedInGatekeeperException' => 'Elgg\\Exceptions\\Http\\Gatekeeper\\LoggedInGatekeeperException',
        'Elgg\\Http\\Exception\\LoggedOutGatekeeperException' => 'Elgg\\Exceptions\\Http\\Gatekeeper\\LoggedOutGatekeeperException',
        'Elgg\\Http\\Exception\\UpgradeGatekeeperException' => 'Elgg\\Exceptions\\Http\\Gatekeeper\\UpgradeGatekeeperException',
        'Elgg\\I18n\\InvalidLocaleException' => 'Elgg\\Exceptions\\I18n\\InvalidLocaleException',

        // Trait moves (Elgg\* → Elgg\Traits\*)
        'Elgg\\Loggable' => 'Elgg\\Traits\\Loggable',
        'Elgg\\TimeUsing' => 'Elgg\\Traits\\TimeUsing',
        'Elgg\\Cacheable' => 'Elgg\\Traits\\Cacheable',
        'Elgg\\Di\\ServiceFacade' => 'Elgg\\Traits\\Di\\ServiceFacade',
        'Elgg\\Entity\\ProfileData' => 'Elgg\\Traits\\Entity\\ProfileData',
        'Elgg\\Database\\Seeds\\Seeding' => 'Elgg\\Traits\\Seeding',

        // Notification event class
        'Elgg\\Notifications\\Event' => 'Elgg\\Notifications\\SubscriptionNotificationEvent',
    ];

    public function getId(): string
    {
        return 'exception-class-renames';
    }

    public function getDescription(): string
    {
        return 'Rename exception classes and traits moved to new namespaces in Elgg 4.0';
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

            foreach (self::MAP as $old => $new) {
                $needsReplacement = false;

                if (str_contains($old, '\\')) {
                    // Namespaced class: check for exact use/reference
                    if (str_contains($code, 'use ' . $old)
                        || str_contains($code, '\\' . $old)
                        || str_contains($code, $old)
                    ) {
                        // But not if already replaced (new name contains old as substring for some)
                        if ($old !== $new && !str_contains($new, $old)) {
                            $needsReplacement = true;
                        } elseif (str_contains($code, 'use ' . $old . ';') || str_contains($code, '\\' . $old . ';') || str_contains($code, '\\' . $old . '(')) {
                            $needsReplacement = true;
                        }
                    }
                } else {
                    // Root-level class: only match when preceded by backslash
                    if (str_contains($code, '\\' . $old)) {
                        // Ensure it's not already the new name
                        // e.g., \RegistrationException but not \Elgg\Exceptions\...\RegistrationException
                        if (preg_match('/[^\\\\a-zA-Z]\\\\' . preg_quote($old, '/') . '(?=[^a-zA-Z])/', $code)) {
                            $needsReplacement = true;
                        }
                    }
                }

                if ($needsReplacement) {
                    $findings[] = new Finding(
                        file: $relativePath,
                        line: 0,
                        description: "{$old} → {$new}",
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
                ? sprintf('Found %d class/trait reference(s) to rename', count($findings))
                : 'No renamed classes/traits found',
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

            // Sort by old name length (longest first) to avoid partial matches
            $sorted = self::MAP;
            uksort($sorted, fn($a, $b) => strlen($b) - strlen($a));

            foreach ($sorted as $old => $new) {
                if (str_contains($old, '\\')) {
                    // Namespaced: safe to do direct string replacement
                    $code = str_replace('\\' . $old, '\\' . $new, $code);
                    $code = str_replace('use ' . $old, 'use ' . $new, $code);
                    // Bare namespace ref (e.g., in catch blocks without leading \)
                    $code = str_replace($old, $new, $code);
                } else {
                    // Root-level class (e.g., RegistrationException) — only replace
                    // when preceded by \ to avoid replacing inside already-renamed names
                    $code = str_replace('\\' . $old, '\\' . $new, $code);
                }
            }

            if ($code !== $original) {
                file_put_contents($file, $code);
                $changes[] = new FileChange(
                    file: $relativePath,
                    type: 'modified',
                    description: 'Renamed exception classes and traits to 4.x namespaces',
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
