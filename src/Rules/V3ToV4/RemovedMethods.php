<?php

declare(strict_types=1);

namespace ElggMigrate\Rules\V3ToV4;

use ElggMigrate\AbstractRule;
use ElggMigrate\FileChange;
use ElggMigrate\Finding;
use ElggMigrate\RuleAnalysis;
use ElggMigrate\RuleResult;
use PhpParser\Node;

/**
 * Flags class methods deprecated in 3.x and removed in 4.0.
 *
 * Warn-only: method replacements require understanding calling context.
 */
final class RemovedMethods extends AbstractRule
{
    public const MAP = [
        // ElggPlugin methods
        'getUserSetting' => ['class' => 'ElggPlugin', 'note' => 'Use ElggUser::getPluginSetting()'],
        'setUserSetting' => ['class' => 'ElggPlugin', 'note' => 'Use ElggUser::setPluginSetting()'],
        'getAllUserSettings' => ['class' => 'ElggPlugin', 'note' => 'Removed in 4.0'],
        'unsetAllUserSettings' => ['class' => 'ElggPlugin', 'note' => 'Removed in 4.0'],
        'unsetAllUserAndPluginSettings' => ['class' => 'ElggPlugin', 'note' => 'Use unsetAllEntityAndPluginSettings()'],
        'getDependencyReport' => ['class' => 'ElggPlugin', 'note' => 'Removed in 4.0'],
        'getError' => ['class' => 'ElggPlugin', 'note' => 'Removed in 4.0'],

        // ElggGroup methods
        'addObjectToGroup' => ['class' => 'ElggGroup', 'note' => 'Removed in 4.0'],
        'removeObjectFromGroup' => ['class' => 'ElggGroup', 'note' => 'Removed in 4.0'],

        // ElggWidget methods
        'getContext' => ['class' => 'ElggWidget', 'note' => 'Use $entity->context property'],
        'setContext' => ['class' => 'ElggWidget', 'note' => 'Use $entity->context = $value'],

        // ElggEntity location methods
        'setLocation' => ['class' => 'ElggEntity', 'note' => 'Use $entity->location = $value'],
        'getLocation' => ['class' => 'ElggEntity', 'note' => 'Use $entity->location property'],

        // Elgg\Email methods
        'getRecipient' => ['class' => 'Elgg\\Email', 'note' => 'Use getTo()'],
        'setRecipient' => ['class' => 'Elgg\\Email', 'note' => 'Removed — use constructor or setTo()'],

        // Notification methods
        'getDeprecatedHandler' => ['class' => 'NotificationsService', 'note' => 'Removed in 4.0'],
        'getMethodsAsDeprecatedGlobal' => ['class' => 'NotificationsService', 'note' => 'Use elgg_get_notification_methods()'],
        'registerDeprecatedHandler' => ['class' => 'NotificationsService', 'note' => 'Removed in 4.0'],
        'setDeprecatedNotificationSubject' => ['class' => 'NotificationsService', 'note' => 'Removed in 4.0'],

        // Config methods
        'getEntityTypes' => ['class' => 'Elgg\\Config', 'note' => 'Use Elgg\\Config::ENTITY_TYPES constant'],
    ];

    public function getId(): string
    {
        return 'removed-methods-4x';
    }

    public function getDescription(): string
    {
        return 'Flag class methods deprecated in 3.x and removed in 4.0';
    }

    public function canAutomate(): bool
    {
        return true;
    }

    public function analyze(string $pluginPath): RuleAnalysis
    {
        $findings = [];
        $methodNames = array_keys(self::MAP);

        foreach ($this->findPhpFiles($pluginPath) as $file) {
            $relativePath = $this->relativePath($pluginPath, $file);
            $code = file_get_contents($file);
            if ($code === false) continue;

            $ast = $this->parse($code);
            if ($ast === null) continue;

            $calls = $this->finder()->find($ast, function (Node $node) use ($methodNames) {
                return $node instanceof Node\Expr\MethodCall
                    && $node->name instanceof Node\Identifier
                    && in_array($node->name->name, $methodNames, true);
            });

            $printer = $this->printer();

            foreach ($calls as $call) {
                /** @var Node\Expr\MethodCall $call */
                $methodName = $call->name->name;
                $info = self::MAP[$methodName];

                $findings[] = new Finding(
                    file: $relativePath,
                    line: $call->getLine(),
                    description: "{$info['class']}::{$methodName}() removed in 4.0: {$info['note']}",
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
                ? sprintf('Found %d removed method call(s)', count($findings))
                : 'No removed method calls found',
        );
    }

    public function apply(string $pluginPath): RuleResult
    {
        // Warn-only — replacements require understanding calling context
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
