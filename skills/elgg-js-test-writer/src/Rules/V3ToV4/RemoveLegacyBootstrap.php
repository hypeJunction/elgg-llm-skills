<?php

declare(strict_types=1);

namespace ElggMigrate\Rules\V3ToV4;

use ElggMigrate\AbstractRule;
use ElggMigrate\FileChange;
use ElggMigrate\Finding;
use ElggMigrate\RuleAnalysis;
use ElggMigrate\RuleResult;

/**
 * Removes legacy bootstrap files after elgg-plugin.php has been generated.
 *
 * In Elgg 4.0, start.php, activate.php, deactivate.php, and views.php
 * are no longer loaded. Their registrations should be in elgg-plugin.php.
 *
 * Safe to auto-remove:
 * - activate.php: if only contains subtype registration (elgg_set_entity_class / add_subtype / update_subtype)
 * - deactivate.php: if only contains subtype cleanup or comments
 * - views.php: always (view locations go in elgg-plugin.php 'views' key)
 *
 * Warn only:
 * - start.php: always warn — may contain complex logic needing a Bootstrap class
 */
final class RemoveLegacyBootstrap extends AbstractRule
{
    /**
     * Functions that are purely subtype registration (safe to remove from activate.php).
     */
    private const SUBTYPE_FUNCTIONS = [
        'elgg_set_entity_class',
        'add_subtype',
        'update_subtype',
    ];

    public function getId(): string
    {
        return 'remove-legacy-bootstrap';
    }

    public function getDescription(): string
    {
        return 'Remove start.php, activate.php, deactivate.php, views.php after elgg-plugin.php migration';
    }

    public function canAutomate(): bool
    {
        return true;
    }

    public function analyze(string $pluginPath): RuleAnalysis
    {
        $findings = [];

        // Prerequisite: elgg-plugin.php must exist
        if (!is_file($pluginPath . '/elgg-plugin.php')) {
            return new RuleAnalysis(
                ruleId: $this->getId(),
                applicable: false,
                findings: [],
                summary: 'No elgg-plugin.php found — run GenerateElggPluginPhp first',
            );
        }

        if (is_file($pluginPath . '/activate.php')) {
            $safe = $this->isActivateSafeToRemove($pluginPath . '/activate.php');
            $findings[] = new Finding(
                file: 'activate.php',
                line: 0,
                description: $safe
                    ? 'activate.php contains only subtype registration — safe to remove'
                    : 'activate.php has non-subtype logic — review before removing',
                code: '',
            );
        }

        if (is_file($pluginPath . '/deactivate.php')) {
            $safe = $this->isDeactivateSafeToRemove($pluginPath . '/deactivate.php');
            $findings[] = new Finding(
                file: 'deactivate.php',
                line: 0,
                description: $safe
                    ? 'deactivate.php is empty or contains only subtype cleanup — safe to remove'
                    : 'deactivate.php has custom logic — review before removing',
                code: '',
            );
        }

        if (is_file($pluginPath . '/views.php')) {
            $findings[] = new Finding(
                file: 'views.php',
                line: 0,
                description: 'views.php — view locations should be in elgg-plugin.php \'views\' key',
                code: '',
            );
        }

        if (is_file($pluginPath . '/start.php')) {
            $findings[] = new Finding(
                file: 'start.php',
                line: 0,
                description: 'start.php still exists — registrations should be in elgg-plugin.php; complex logic needs Bootstrap class',
                code: '',
            );
        }

        $applicable = count($findings) > 0;

        return new RuleAnalysis(
            ruleId: $this->getId(),
            applicable: $applicable,
            findings: $findings,
            summary: $applicable
                ? sprintf('Found %d legacy bootstrap file(s) to review', count($findings))
                : 'No legacy bootstrap files found',
        );
    }

    public function apply(string $pluginPath): RuleResult
    {
        if (!is_file($pluginPath . '/elgg-plugin.php')) {
            return new RuleResult(
                ruleId: $this->getId(),
                success: true,
                changes: [],
                warnings: ['No elgg-plugin.php found — run GenerateElggPluginPhp first'],
            );
        }

        $changes = [];
        $warnings = [];

        // activate.php — remove if only subtype registration
        if (is_file($pluginPath . '/activate.php')) {
            if ($this->isActivateSafeToRemove($pluginPath . '/activate.php')) {
                unlink($pluginPath . '/activate.php');
                $changes[] = new FileChange('activate.php', 'deleted', 'Removed — subtype registration moved to elgg-plugin.php entities key');
            } else {
                $warnings[] = 'activate.php has non-subtype logic — manual review needed';
            }
        }

        // deactivate.php — remove if empty/subtype-only
        if (is_file($pluginPath . '/deactivate.php')) {
            if ($this->isDeactivateSafeToRemove($pluginPath . '/deactivate.php')) {
                unlink($pluginPath . '/deactivate.php');
                $changes[] = new FileChange('deactivate.php', 'deleted', 'Removed — empty or subtype cleanup only');
            } else {
                $warnings[] = 'deactivate.php has custom logic — manual review needed';
            }
        }

        // views.php — always remove
        if (is_file($pluginPath . '/views.php')) {
            unlink($pluginPath . '/views.php');
            $changes[] = new FileChange('views.php', 'deleted', 'Removed — view locations should be in elgg-plugin.php views key');
        }

        // start.php — warn only, never auto-delete
        if (is_file($pluginPath . '/start.php')) {
            if ($this->isStartSafeToRemove($pluginPath . '/start.php')) {
                unlink($pluginPath . '/start.php');
                $changes[] = new FileChange('start.php', 'deleted', 'Removed — all registrations are in elgg-plugin.php');
            } else {
                $warnings[] = 'start.php still exists — move remaining logic to a Bootstrap class or elgg-plugin.php, then delete';
            }
        }

        return new RuleResult(
            ruleId: $this->getId(),
            success: true,
            changes: $changes,
            warnings: $warnings,
        );
    }

    /**
     * Check if activate.php only contains subtype registration calls.
     *
     * Safe patterns:
     * - elgg_set_entity_class('object', 'subtype', Class::class)
     * - add_subtype('object', 'subtype', 'ClassName')
     * - update_subtype('object', 'subtype', 'ClassName')
     * - if/foreach wrapping the above
     * - require_once autoloader
     * - namespace declaration
     * - use statements
     */
    private function isActivateSafeToRemove(string $file): bool
    {
        $code = file_get_contents($file);
        if ($code === false) return false;

        $ast = $this->parse($code);
        if ($ast === null) return false;

        return $this->astContainsOnlySubtypeRegistration($ast);
    }

    /**
     * Check if deactivate.php is effectively empty.
     *
     * Safe to remove if it contains only:
     * - Comments
     * - Namespace/use declarations
     * - update_subtype() with 2 args (clearing subtype class)
     * - Empty foreach loops
     */
    private function isDeactivateSafeToRemove(string $file): bool
    {
        $code = file_get_contents($file);
        if ($code === false) return false;

        $ast = $this->parse($code);
        if ($ast === null) return false;

        return $this->astIsEffectivelyEmpty($ast);
    }

    /**
     * Check if start.php only contains registrations that are now in elgg-plugin.php.
     *
     * Safe if all statements are:
     * - require_once autoloader
     * - use statements
     * - elgg_register_event_handler('init', 'system', ...) wrapping only
     *   registration calls (actions, routes, hooks, events, etc.)
     * - return function() { ... } wrapping same
     */
    private function isStartSafeToRemove(string $file): bool
    {
        $code = file_get_contents($file);
        if ($code === false) return false;

        $ast = $this->parse($code);
        if ($ast === null) return false;

        foreach ($ast as $stmt) {
            if (!$this->isRemovableStartStatement($stmt)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Check if a start.php top-level statement is safe to remove.
     */
    private function isRemovableStartStatement(\PhpParser\Node\Stmt $stmt): bool
    {
        // use statements, namespace
        if ($stmt instanceof \PhpParser\Node\Stmt\Use_
            || $stmt instanceof \PhpParser\Node\Stmt\Namespace_
            || $stmt instanceof \PhpParser\Node\Stmt\Nop
        ) {
            return true;
        }

        // require_once __DIR__ . '/autoloader.php'
        if ($stmt instanceof \PhpParser\Node\Stmt\Expression
            && $stmt->expr instanceof \PhpParser\Node\Expr\Include_
        ) {
            return true;
        }

        // elgg_register_event_handler('init', 'system', ...)
        if ($stmt instanceof \PhpParser\Node\Stmt\Expression
            && $stmt->expr instanceof \PhpParser\Node\Expr\FuncCall
            && $stmt->expr->name instanceof \PhpParser\Node\Name
        ) {
            $name = $stmt->expr->name->toString();
            return in_array($name, [
                'elgg_register_event_handler',
                'elgg_register_plugin_hook_handler',
                'elgg_register_action',
                'elgg_register_route',
                'elgg_register_ajax_view',
                'elgg_extend_view',
                'elgg_unextend_view',
                'elgg_register_widget_type',
                'elgg_register_entity_type',
                'elgg_register_notification_event',
                'elgg_set_entity_class',
            ], true);
        }

        // return function() { ... }; (Elgg 3.x start.php pattern)
        if ($stmt instanceof \PhpParser\Node\Stmt\Return_
            && $stmt->expr instanceof \PhpParser\Node\Expr\Closure
        ) {
            // Check inner statements are all registrations
            foreach ($stmt->expr->stmts as $inner) {
                if (!$this->isRemovableStartStatement($inner)) {
                    return false;
                }
            }
            return true;
        }

        return false;
    }

    /**
     * @param array<\PhpParser\Node\Stmt> $ast
     */
    private function astContainsOnlySubtypeRegistration(array $ast): bool
    {
        foreach ($ast as $stmt) {
            if ($stmt instanceof \PhpParser\Node\Stmt\Use_
                || $stmt instanceof \PhpParser\Node\Stmt\Namespace_
                || $stmt instanceof \PhpParser\Node\Stmt\Nop
            ) {
                continue;
            }

            // require_once autoloader
            if ($stmt instanceof \PhpParser\Node\Stmt\Expression
                && $stmt->expr instanceof \PhpParser\Node\Expr\Include_
            ) {
                continue;
            }

            // Direct call to subtype function
            if ($stmt instanceof \PhpParser\Node\Stmt\Expression
                && $stmt->expr instanceof \PhpParser\Node\Expr\FuncCall
                && $stmt->expr->name instanceof \PhpParser\Node\Name
                && in_array($stmt->expr->name->toString(), self::SUBTYPE_FUNCTIONS, true)
            ) {
                continue;
            }

            // foreach/if wrapping subtype calls
            if ($stmt instanceof \PhpParser\Node\Stmt\Foreach_
                || $stmt instanceof \PhpParser\Node\Stmt\If_
            ) {
                $innerCalls = $this->findFunctionCalls([$stmt], self::SUBTYPE_FUNCTIONS);
                $allCalls = $this->finder()->find([$stmt], function ($node) {
                    return $node instanceof \PhpParser\Node\Expr\FuncCall
                        && $node->name instanceof \PhpParser\Node\Name;
                });
                // Safe if every function call inside is a subtype function
                if (count($allCalls) > 0 && count($innerCalls) === count($allCalls)) {
                    continue;
                }
            }

            // Anything else → not safe
            return false;
        }

        return true;
    }

    /**
     * @param array<\PhpParser\Node\Stmt> $ast
     */
    private function astIsEffectivelyEmpty(array $ast): bool
    {
        foreach ($ast as $stmt) {
            if ($stmt instanceof \PhpParser\Node\Stmt\Use_
                || $stmt instanceof \PhpParser\Node\Stmt\Namespace_
                || $stmt instanceof \PhpParser\Node\Stmt\Nop
            ) {
                continue;
            }

            // require_once autoloader
            if ($stmt instanceof \PhpParser\Node\Stmt\Expression
                && $stmt->expr instanceof \PhpParser\Node\Expr\Include_
            ) {
                continue;
            }

            // update_subtype with 2 args (clearing) or subtype functions
            if ($stmt instanceof \PhpParser\Node\Stmt\Expression
                && $stmt->expr instanceof \PhpParser\Node\Expr\FuncCall
                && $stmt->expr->name instanceof \PhpParser\Node\Name
                && in_array($stmt->expr->name->toString(), self::SUBTYPE_FUNCTIONS, true)
            ) {
                continue;
            }

            // foreach with subtype cleanup or empty body
            if ($stmt instanceof \PhpParser\Node\Stmt\Foreach_) {
                $allCalls = $this->finder()->find([$stmt], function ($node) {
                    return $node instanceof \PhpParser\Node\Expr\FuncCall
                        && $node->name instanceof \PhpParser\Node\Name;
                });
                $subtypeCalls = $this->findFunctionCalls([$stmt], self::SUBTYPE_FUNCTIONS);
                // Empty body or only subtype calls
                if (count($allCalls) === 0 || count($subtypeCalls) === count($allCalls)) {
                    continue;
                }
            }

            return false;
        }

        return true;
    }
}
