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
 * Handles functions deprecated in 3.x and removed (or deprecated again) in 4.0.
 *
 * Each entry has:
 * - 'status': 'removed' (hard removed in 4.0 → activation fatal in 4.x) or
 *             'deprecated' (still ships in deprecated-4.x.php → works through 4.x but
 *             will be removed in a later major; fixing now avoids a 5.x landmine).
 * - 'note':   human-readable replacement hint.
 *
 * Functions in `deprecated-4.x.php` (verified against Elgg 4.3.6) MUST be marked
 * 'deprecated' so the gate does not raise a false positive (e.g. forward() was
 * mis-flagged 'removed in 4.0' and blocked activation-gate triage; it actually
 * ships in deprecated-4.0.php through 4.3).
 */
final class RemovedFunctions extends AbstractRule
{
    public const MAP = [
        // Validation functions → OO equivalents
        'validate_email_address' => ['status' => 'removed', 'note' => 'Use elgg()->accounts->assertValidEmail()'],
        'validate_password' => ['status' => 'removed', 'note' => 'Use elgg()->accounts->assertValidPassword()'],
        'validate_username' => ['status' => 'removed', 'note' => 'Use elgg()->accounts->assertValidUsername()'],

        // forward() → still ships in deprecated-4.0.php; removed in 5.x
        'forward' => ['status' => 'deprecated', 'note' => 'Deprecated in 4.0 (still works through 4.x via deprecated-4.0.php; REMOVED in 5.x). Use elgg_redirect_response() or throw \\Elgg\\Exceptions\\HttpException.'],

        // Access functions
        'access_get_show_hidden_status' => ['status' => 'removed', 'note' => 'Use elgg()->session->getDisabledEntityVisibility()'],
        'group_access_options' => ['status' => 'removed', 'note' => 'Use access collection APIs'],

        // Plugin settings (procedural → OO)
        'elgg_set_plugin_setting' => ['status' => 'removed', 'note' => 'Use $plugin->setSetting($name, $value)'],
        'elgg_unset_plugin_setting' => ['status' => 'removed', 'note' => 'Use $plugin->unsetSetting($name)'],
        'elgg_set_plugin_user_setting' => ['status' => 'removed', 'note' => 'Use ElggUser::setPluginSetting()'],
        'elgg_unset_plugin_user_setting' => ['status' => 'removed', 'note' => 'Use ElggUser::removePluginSetting()'],
        'elgg_get_all_plugin_user_settings' => ['status' => 'removed', 'note' => 'Removed in 4.0'],
        'elgg_get_entities_from_plugin_user_settings' => ['status' => 'removed', 'note' => 'Use elgg_get_entities() with private settings'],

        // Filter tabs
        'elgg_get_filter_tabs' => ['status' => 'removed', 'note' => "Use 'register', 'menu:filter:<filter_id>' event"],

        // Tag metadata
        'elgg_register_tag_metadata_name' => ['status' => 'removed', 'note' => "Use 'search:fields' event to add metadata fields"],
        'elgg_get_registered_tag_metadata_names' => ['status' => 'removed', 'note' => "Use 'search:fields' event"],

        // Translation functions
        'get_language_completeness' => ['status' => 'removed', 'note' => 'Use elgg()->translator->getLanguageCompleteness()'],
        'get_installed_translations' => ['status' => 'removed', 'note' => 'Use elgg()->translator->getInstalledTranslations()'],
        'elgg_get_available_languages' => ['status' => 'removed', 'note' => 'Use elgg()->translator->getAvailableLanguages()'],

        // add_translation() — still ships in deprecated-4.3.php; REMOVED in 5.x.
        // Plugins must rewrite languages/<lang>.php from
        //     add_translation('en', ['key' => 'value', ...]);
        // to
        //     return ['key' => 'value', ...];
        // Leaving it deprecated-only is a latent landmine that explodes at the 5.x boundary.
        'add_translation' => ['status' => 'deprecated', 'note' => 'Deprecated in 4.3 (still works through 4.x via deprecated-4.3.php; REMOVED in 5.x). Rewrite languages/<lang>.php to "return [...]" at the top of the file instead of calling add_translation($code, [...]).'],

        // System messages
        'elgg_get_system_messages' => ['status' => 'removed', 'note' => 'Use elgg()->system_messages->loadRegisters()'],
        'elgg_set_system_messages' => ['status' => 'removed', 'note' => 'Use elgg()->system_messages->saveRegisters()'],

        // CSS/JS loaded files
        'elgg_get_loaded_css' => ['status' => 'removed', 'note' => "Use elgg_get_loaded_external_files('css', 'head')"],
        'elgg_get_loaded_js' => ['status' => 'removed', 'note' => "Use elgg_get_loaded_external_files('js', \$location)"],

        // Subscription functions → OO
        'elgg_add_subscription' => ['status' => 'removed', 'note' => 'Use ElggEntity::addSubscription()'],
        'elgg_remove_subscription' => ['status' => 'removed', 'note' => 'Use ElggEntity::removeSubscription()'],

        // Diagnostics
        'diagnostics_md5_dir' => ['status' => 'removed', 'note' => 'Removed in 4.0'],

        // Pages plugin
        'pages_is_page' => ['status' => 'removed', 'note' => 'Removed in 4.0'],

        // SQL/Database helpers
        'run_sql_script' => ['status' => 'removed', 'note' => 'Use elgg()->db->updateData() with inline SQL'],
        'get_data' => ['status' => 'removed', 'note' => 'Use elgg()->db->getData()'],
        'get_data_row' => ['status' => 'removed', 'note' => 'Use elgg()->db->getDataRow()'],
        'insert_data' => ['status' => 'removed', 'note' => 'Use elgg()->db->insertData()'],
        'update_data' => ['status' => 'removed', 'note' => 'Use elgg()->db->updateData()'],
        'delete_data' => ['status' => 'removed', 'note' => 'Use elgg()->db->deleteData()'],

        // Subtype registration (removed: subtypes are strings in 3.x+)
        'add_subtype' => ['status' => 'removed', 'note' => 'Subtypes are strings since 3.0 — remove this call'],
        'get_subtype_id' => ['status' => 'removed', 'note' => 'Subtypes are strings since 3.0 — remove this call'],

        // Entity type registration — still ships in deprecated-4.1.php; removed in 5.x
        'elgg_register_entity_type' => ['status' => 'deprecated', 'note' => "Deprecated in 4.1 (still works through 4.x via deprecated-4.1.php; REMOVED in 5.x). Use the 'entities' key in elgg-plugin.php."],

        // Admin menu registration — hard removed in 4.0 (no deprecation shim).
        // Caused activation fatal during csv_process 3→4 migration (bd elgg-migrate-4pye6).
        'elgg_register_admin_menu_item' => ['status' => 'removed', 'note' => "Use a declarative 'menus.page.<plugin_id>' block in elgg-plugin.php (see core admin plugin for reference)."],
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
                $verb = $info['status'] === 'removed' ? 'removed in 4.0' : 'deprecated in 4.x';

                $findings[] = new Finding(
                    file: $relativePath,
                    line: $call->getLine(),
                    description: "{$funcName}() {$verb}: {$info['note']}",
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
