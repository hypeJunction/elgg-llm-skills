<?php

declare(strict_types=1);

namespace ElggMigrate\Rules\V2ToV3;

use ElggMigrate\AbstractRule;
use ElggMigrate\FileChange;
use ElggMigrate\Finding;
use ElggMigrate\RuleAnalysis;
use ElggMigrate\RuleResult;
use PhpParser\Node;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitorAbstract;

/**
 * Handles ALL functions removed or renamed in the 2.x→3.0 transition.
 *
 * Three categories:
 * - 'rename': function exists under a new name → rename the call
 * - 'remove': function removed with no drop-in replacement → remove + warn
 * - 'warn': function removed, replacement requires refactoring → warn only
 */
final class RemovedFunctions extends AbstractRule
{
    /**
     * Map of removed function → action.
     *
     * Format: 'old_name' => ['action' => 'rename|remove|warn', 'to' => 'new_name', 'note' => '...']
     */
    public const MAP = [
        // Direct renames
        'datalist_get' => ['action' => 'rename', 'to' => 'elgg_get_config'],
        'datalist_set' => ['action' => 'rename', 'to' => 'elgg_save_config'],
        'get_subtype_class' => ['action' => 'rename', 'to' => 'elgg_get_entity_class'],
        'elgg_group_gatekeeper' => ['action' => 'rename', 'to' => 'elgg_entity_gatekeeper'],
        'get_entity_dates' => ['action' => 'rename', 'to' => 'elgg_get_entity_dates'],

        // Removals (no drop-in replacement)
        'create_metadata_from_array' => ['action' => 'remove', 'note' => 'Use $entity->setMetadata() for each key-value pair'],
        'metadata_array_to_values' => ['action' => 'remove', 'note' => 'No longer needed — metadata values stored directly'],
        'detect_extender_valuetype' => ['action' => 'remove', 'note' => 'No longer needed — value types detected automatically'],
        'elgg_disable_metadata' => ['action' => 'remove', 'note' => 'Metadata can no longer be enabled/disabled in 3.0'],
        'elgg_enable_metadata' => ['action' => 'remove', 'note' => 'Metadata can no longer be enabled/disabled in 3.0'],
        'elgg_get_metastring_id' => ['action' => 'remove', 'note' => 'Metastrings table removed in 3.0'],
        'elgg_get_metastring_map' => ['action' => 'remove', 'note' => 'Metastrings table removed in 3.0'],
        'elgg_format_url' => ['action' => 'remove', 'note' => 'No replacement — URLs no longer formatted this way'],
        'elgg_override_permissions' => ['action' => 'remove', 'note' => 'Use elgg_call(ELGG_IGNORE_ACCESS, ...) instead'],
        'elgg_check_access_overrides' => ['action' => 'remove', 'note' => 'No replacement in 3.0'],
        'elgg_register_viewtype' => ['action' => 'remove', 'note' => 'Custom viewtypes handled differently in 3.0'],
        'elgg_is_registered_viewtype' => ['action' => 'remove', 'note' => 'Custom viewtypes handled differently in 3.0'],
        'elgg_get_class_loader' => ['action' => 'remove', 'note' => 'Use Composer autoloading'],
        'elgg_register_class' => ['action' => 'remove', 'note' => 'Use Composer autoloading'],
        'elgg_register_classes' => ['action' => 'remove', 'note' => 'Use Composer autoloading'],
        'get_default_filestore' => ['action' => 'remove', 'note' => 'Custom filestores no longer supported'],
        'set_default_filestore' => ['action' => 'remove', 'note' => 'Custom filestores no longer supported'],
        'get_site_by_url' => ['action' => 'remove', 'note' => 'Multi-site support removed in 3.0'],
        'get_site_entity_as_row' => ['action' => 'remove', 'note' => 'Subtables removed in 3.0'],
        'get_group_entity_as_row' => ['action' => 'remove', 'note' => 'Subtables removed in 3.0'],
        'get_object_entity_as_row' => ['action' => 'remove', 'note' => 'Subtables removed in 3.0'],
        'get_user_entity_as_row' => ['action' => 'remove', 'note' => 'Subtables removed in 3.0'],
        'update_river_access_by_object' => ['action' => 'remove', 'note' => 'River access handled automatically in 3.0'],
        'garbagecollector_orphaned_metastrings' => ['action' => 'remove', 'note' => 'Metastrings removed in 3.0'],
        'is_memcache_available' => ['action' => 'remove', 'note' => 'Use elgg_get_system_cache() instead'],
        '_elgg_get_memcache' => ['action' => 'remove', 'note' => 'Use elgg_get_system_cache() instead'],
        '_elgg_invalidate_memcache_for_entity' => ['action' => 'remove', 'note' => 'Cache invalidation handled automatically'],
        'get_missing_language_keys' => ['action' => 'remove', 'note' => 'Developer tool only — not available in 3.0'],
        'row_to_elggrelationship' => ['action' => 'remove', 'note' => 'Use ElggRelationship constructor'],
        'groups_setup_sidebar_menus' => ['action' => 'remove', 'note' => 'Sidebar menus registered differently in 3.0'],
        'groups_set_icon_url' => ['action' => 'remove', 'note' => 'Use entity:icon:url hook instead'],
        'activity_profile_menu' => ['action' => 'remove', 'note' => 'Profile menu handled differently in 3.0'],
        'developers_setup_menu' => ['action' => 'remove', 'note' => 'Internal Elgg function — should not be in plugins'],
        'file_get_type_cloud' => ['action' => 'remove', 'note' => 'File type cloud removed in 3.0'],
        'file_type_cloud_get_url' => ['action' => 'remove', 'note' => 'File type cloud removed in 3.0'],
        'messages_notification_msg' => ['action' => 'remove', 'note' => 'Use notification event system'],
        'notifications_plugin_pagesetup' => ['action' => 'remove', 'note' => 'Notification UI handled differently in 3.0'],
        'profile_pagesetup' => ['action' => 'remove', 'note' => 'Use init, system and menu hooks'],
        'uservalidationbyemail_generate_code' => ['action' => 'remove', 'note' => 'Validation handled by core in 3.0'],

        // Warn only (replacement requires OO refactoring)
        'can_write_to_container' => ['action' => 'warn', 'note' => 'Use $entity->canWriteToContainer($type, $subtype)'],
        'run_function_once' => ['action' => 'warn', 'note' => 'Use Elgg\\Upgrade\\Batch for upgrade scripts'],
        'system_messages' => ['action' => 'warn', 'note' => 'Use elgg_register_success_message() or elgg_register_error_message()'],
        'generate_user_password' => ['action' => 'warn', 'note' => 'Use ElggUser::setPassword() with generated password'],
        'file_delete' => ['action' => 'warn', 'note' => 'Use $entity->deleteIcon() instead'],
        'groups_get_group_tool_options' => ['action' => 'warn', 'note' => 'Use elgg()->group_tools->all()'],
        'groups_join_group' => ['action' => 'warn', 'note' => 'Use $group->join($user)'],
        'groups_prepare_profile_buttons' => ['action' => 'warn', 'note' => 'Use register, menu:title hook'],
        'groups_register_profile_buttons' => ['action' => 'warn', 'note' => 'Use register, menu:title hook'],
        'groups_access_collection_override' => ['action' => 'warn', 'note' => 'Access collection handling changed in 3.0'],

        // Search functions (all removed — search rewritten in 3.0)
        'search_get_where_sql' => ['action' => 'remove', 'note' => 'Search system rewritten in 3.0 — use search hooks'],
        'search_get_ft_min_max' => ['action' => 'remove', 'note' => 'Search system rewritten in 3.0'],
        'search_get_order_by_sql' => ['action' => 'remove', 'note' => 'Search system rewritten in 3.0'],
        'search_consolidate_substrings' => ['action' => 'remove', 'note' => 'Search system rewritten in 3.0'],
        'search_remove_ignored_words' => ['action' => 'remove', 'note' => 'Search system rewritten in 3.0'],
        'search_get_highlighted_relevant_substrings' => ['action' => 'remove', 'note' => 'Search system rewritten in 3.0'],
        'search_highlight_words' => ['action' => 'remove', 'note' => 'Search system rewritten in 3.0'],
        'search_get_search_view' => ['action' => 'remove', 'note' => 'Search system rewritten in 3.0'],
    ];

    public function getId(): string
    {
        return 'removed-functions';
    }

    public function getDescription(): string
    {
        return 'Replace or remove functions that were removed in Elgg 3.0';
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
                $action = $info['action'];
                $desc = match ($action) {
                    'rename' => "{$funcName}() → {$info['to']}()",
                    'remove' => "{$funcName}() removed: {$info['note']}",
                    'warn' => "{$funcName}() removed: {$info['note']}",
                };

                $findings[] = new Finding(
                    file: $relativePath,
                    line: $call->getLine(),
                    description: $desc,
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
        $targetNames = array_keys(self::MAP);
        $changes = [];
        $warnings = [];

        foreach ($this->findPhpFiles($pluginPath) as $file) {
            $relativePath = $this->relativePath($pluginPath, $file);
            $code = file_get_contents($file);
            if ($code === false) continue;

            $ast = $this->parse($code);
            if ($ast === null) continue;

            $calls = $this->findFunctionCalls($ast, $targetNames);
            if (empty($calls)) continue;

            $result = $this->transformFile($ast, $code);

            if ($result['transformed']) {
                file_put_contents($file, $result['code']);
                $changes[] = new FileChange(
                    file: $relativePath,
                    type: 'modified',
                    description: 'Replaced/removed deprecated function calls',
                );

                foreach ($result['warnings'] as $w) {
                    $warnings[] = "{$relativePath}: {$w}";
                }
            }
        }

        if (empty($changes)) {
            return new RuleResult(
                ruleId: $this->getId(),
                success: true,
                changes: [],
                warnings: ['No removed function calls found'],
            );
        }

        return new RuleResult(
            ruleId: $this->getId(),
            success: true,
            changes: $changes,
            warnings: $warnings,
        );
    }

    /**
     * @param array<Node\Stmt> $ast
     * @return array{transformed: bool, code: string, warnings: array<string>}
     */
    private function transformFile(array $ast, string $originalCode): array
    {
        $warnings = [];

        $traverser = new NodeTraverser();
        $visitor = new class($warnings) extends NodeVisitorAbstract {
            private bool $changed = false;

            public function __construct(private array &$warnings) {}

            public function leaveNode(Node $node): int|Node|null
            {
                // Handle standalone expression statements (for REMOVE_NODE)
                if ($node instanceof Node\Stmt\Expression
                    && $node->expr instanceof Node\Expr\FuncCall
                    && $node->expr->name instanceof Node\Name
                    && isset(RemovedFunctions::MAP[$node->expr->name->toString()])
                ) {
                    $funcName = $node->expr->name->toString();
                    $info = RemovedFunctions::MAP[$funcName];

                    if ($info['action'] === 'rename') {
                        $node->expr->name = new Node\Name($info['to']);
                        $this->changed = true;
                        return $node;
                    }

                    if ($info['action'] === 'remove') {
                        $this->warnings[] = "{$funcName}() removed: {$info['note']}";
                        $this->changed = true;
                        return NodeTraverser::REMOVE_NODE;
                    }

                    if ($info['action'] === 'warn') {
                        $this->warnings[] = "{$funcName}() removed — manual migration needed: {$info['note']}";
                        // Don't remove warn-only calls; user must review
                        return null;
                    }
                }

                // Handle function calls in any context (renames work everywhere)
                if ($node instanceof Node\Expr\FuncCall
                    && $node->name instanceof Node\Name
                    && isset(RemovedFunctions::MAP[$node->name->toString()])
                ) {
                    $funcName = $node->name->toString();
                    $info = RemovedFunctions::MAP[$funcName];

                    if ($info['action'] === 'rename') {
                        $node->name = new Node\Name($info['to']);
                        $this->changed = true;
                        return $node;
                    }

                    // For remove/warn in non-statement context, just warn
                    if ($info['action'] === 'remove' || $info['action'] === 'warn') {
                        $this->warnings[] = "{$funcName}() removed — manual migration needed: {$info['note']}";
                    }
                }

                return null;
            }

            public function hasChanged(): bool
            {
                return $this->changed;
            }
        };

        $traverser->addVisitor($visitor);
        $newAst = $traverser->traverse($ast);

        if (!$visitor->hasChanged()) {
            return ['transformed' => false, 'code' => $originalCode, 'warnings' => $warnings];
        }

        return ['transformed' => true, 'code' => $this->print($newAst), 'warnings' => $warnings];
    }
}
