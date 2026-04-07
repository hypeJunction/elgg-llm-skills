<?php

declare(strict_types=1);

namespace ElggMigrate\Rules\V3ToV4;

use ElggMigrate\AbstractRule;
use ElggMigrate\FileChange;
use ElggMigrate\Finding;
use ElggMigrate\RuleAnalysis;
use ElggMigrate\RuleResult;
use PhpParser\Node;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitorAbstract;

/**
 * Handles functions deprecated in 3.x and removed in 4.0.
 *
 * Three categories:
 * - 'rename': function exists under a new name → rename the call
 * - 'warn': function removed, replacement requires refactoring → warn only
 */
final class RemovedFunctions extends AbstractRule
{
    public const MAP = [
        // Validation functions → OO equivalents
        'validate_email_address' => ['action' => 'warn', 'note' => 'Use elgg()->accounts->assertValidEmail()'],
        'validate_password' => ['action' => 'warn', 'note' => 'Use elgg()->accounts->assertValidPassword()'],
        'validate_username' => ['action' => 'warn', 'note' => 'Use elgg()->accounts->assertValidUsername()'],

        // forward() → response objects
        'forward' => ['action' => 'warn', 'note' => 'Use elgg_redirect_response() or throw Elgg\\Exceptions\\HttpException'],

        // Access functions
        'access_get_show_hidden_status' => ['action' => 'warn', 'note' => 'Use elgg()->session->getDisabledEntityVisibility()'],
        'group_access_options' => ['action' => 'warn', 'note' => 'Removed in 4.0 — use access collection APIs'],

        // Plugin settings (procedural → OO)
        'elgg_set_plugin_setting' => ['action' => 'warn', 'note' => 'Use $plugin->setSetting($name, $value)'],
        'elgg_unset_plugin_setting' => ['action' => 'warn', 'note' => 'Use $plugin->unsetSetting($name)'],
        'elgg_set_plugin_user_setting' => ['action' => 'warn', 'note' => 'Use ElggUser::setPluginSetting()'],
        'elgg_unset_plugin_user_setting' => ['action' => 'warn', 'note' => 'Use ElggUser::removePluginSetting()'],
        'elgg_get_all_plugin_user_settings' => ['action' => 'warn', 'note' => 'Removed in 4.0'],
        'elgg_get_entities_from_plugin_user_settings' => ['action' => 'warn', 'note' => 'Use elgg_get_entities() with private settings'],

        // Filter tabs
        'elgg_get_filter_tabs' => ['action' => 'warn', 'note' => "Use 'register', 'menu:filter:<filter_id>' hook"],

        // Tag metadata
        'elgg_register_tag_metadata_name' => ['action' => 'warn', 'note' => "Use 'search:fields' hook to add metadata fields"],
        'elgg_get_registered_tag_metadata_names' => ['action' => 'warn', 'note' => "Use 'search:fields' hook"],

        // Translation functions
        'get_language_completeness' => ['action' => 'warn', 'note' => 'Use elgg()->translator->getLanguageCompleteness()'],
        'get_installed_translations' => ['action' => 'warn', 'note' => 'Use elgg()->translator->getInstalledTranslations()'],
        'elgg_get_available_languages' => ['action' => 'warn', 'note' => 'Use elgg()->translator->getAvailableLanguages()'],

        // System messages
        'elgg_get_system_messages' => ['action' => 'warn', 'note' => 'Use elgg()->system_messages->loadRegisters()'],
        'elgg_set_system_messages' => ['action' => 'warn', 'note' => 'Use elgg()->system_messages->saveRegisters()'],

        // CSS/JS loaded files
        'elgg_get_loaded_css' => ['action' => 'warn', 'note' => "Use elgg_get_loaded_external_files('css', 'head')"],
        'elgg_get_loaded_js' => ['action' => 'warn', 'note' => "Use elgg_get_loaded_external_files('js', \$location)"],

        // Subscription functions → OO
        'elgg_add_subscription' => ['action' => 'warn', 'note' => 'Use ElggEntity::addSubscription()'],
        'elgg_remove_subscription' => ['action' => 'warn', 'note' => 'Use ElggEntity::removeSubscription()'],

        // Diagnostics
        'diagnostics_md5_dir' => ['action' => 'warn', 'note' => 'Removed in 4.0'],

        // Pages plugin
        'pages_is_page' => ['action' => 'warn', 'note' => 'Removed in 4.0'],

        // SQL/Database helpers
        'run_sql_script' => ['action' => 'warn', 'note' => 'Use elgg()->db->updateData() with inline SQL'],

        // Subtype registration (removed: subtypes are strings in 3.x+)
        'add_subtype' => ['action' => 'warn', 'note' => 'Subtypes are strings since 3.0 — remove this call'],
        'get_subtype_id' => ['action' => 'warn', 'note' => 'Subtypes are strings since 3.0 — remove this call'],

        // Entity type registration
        'elgg_register_entity_type' => ['action' => 'warn', 'note' => "Use 'entities' key in elgg-plugin.php"],
    ];

    public function getId(): string
    {
        return 'removed-functions-4x';
    }

    public function getDescription(): string
    {
        return 'Flag functions deprecated in 3.x and removed in 4.0';
    }

    public function canAutomate(): bool
    {
        return true;
    }

    public function analyze(string $pluginPath): RuleAnalysis
    {
        $findings = [];
        $targetNames = array_keys(self::MAP);

        foreach ($this->findPhpFiles($pluginPath) as $file) {
            $relativePath = $this->relativePath($pluginPath, $file);
            $code = file_get_contents($file);
            if ($code === false) continue;

            $ast = $this->parse($code);
            if ($ast === null) continue;

            $calls = $this->findFunctionCalls($ast, $targetNames);
            $printer = $this->printer();

            foreach ($calls as $call) {
                $funcName = $call->name->toString();
                $info = self::MAP[$funcName];

                $findings[] = new Finding(
                    file: $relativePath,
                    line: $call->getLine(),
                    description: "{$funcName}() removed in 4.0: {$info['note']}",
                    code: $printer->prettyPrintExpr($call),
                );
            }
        }

        $applicable = count($findings) > 0;

        return new RuleAnalysis(
            ruleId: $this->getId(),
            applicable: $applicable,
            findings: $findings,
            summary: $applicable
                ? sprintf('Found %d removed function call(s)', count($findings))
                : 'No removed function calls found',
        );
    }

    public function apply(string $pluginPath): RuleResult
    {
        // Warn-only — these all require manual refactoring (OO replacements)
        $analysis = $this->analyze($pluginPath);
        $warnings = [];

        foreach ($analysis->findings as $finding) {
            $warnings[] = "{$finding->file}:{$finding->line} — {$finding->description}";
        }

        return new RuleResult(
            ruleId: $this->getId(),
            success: true,
            changes: [],
            warnings: $warnings,
        );
    }
}
