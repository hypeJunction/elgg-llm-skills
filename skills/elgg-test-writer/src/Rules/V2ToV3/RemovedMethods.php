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
 * Renames methods removed in Elgg 3.0.
 *
 * Only renames methods that are unambiguous (unique to Elgg classes).
 * Ambiguous methods (like ->get(), ->set()) are warned about, not renamed.
 */
final class RemovedMethods extends AbstractRule
{
    /**
     * Unambiguous method renames (method name unlikely to exist elsewhere).
     */
    public const RENAMES = [
        'getFriendlyName' => ['to' => 'getDisplayName', 'note' => 'ElggPlugin::getFriendlyName() → getDisplayName()'],
        'getWeight' => ['to' => 'getPriority', 'note' => 'ElggMenuItem::getWeight() → getPriority()'],
        'setWeight' => ['to' => 'setPriority', 'note' => 'ElggMenuItem::setWeight() → setPriority()'],
        'compareByWeight' => ['to' => 'compareByPriority', 'note' => 'ElggMenuBuilder::compareByWeight() → compareByPriority()'],
        'getPostedTime' => ['to' => 'getTimePosted', 'note' => 'ElggRiverItem::getPostedTime() → getTimePosted()'],
        'makeFileMatrix' => ['to' => null, 'note' => 'ElggDiskFilestore::makeFileMatrix() removed — no replacement'],
        'isFullyLoaded' => ['to' => null, 'note' => 'ElggEntity::isFullyLoaded() removed — entities are always fully loaded'],
        'clearAllFiles' => ['to' => null, 'note' => 'ElggEntity::clearAllFiles() removed'],
        'getExportableValues' => ['to' => null, 'note' => 'Use ->toObject() instead'],
        'isPublicPage' => ['to' => null, 'note' => 'ElggSite::isPublicPage() removed'],
        'checkWalledGarden' => ['to' => null, 'note' => 'ElggSite::checkWalledGarden() removed'],
        'getClassName' => ['to' => null, 'note' => 'Use get_class() instead'],
        'setURL' => ['to' => null, 'note' => 'ElggEntity::setURL() removed — use entity:url hook'],
        'setFilestore' => ['to' => null, 'note' => 'ElggFile::setFilestore() removed — custom filestores not supported'],
        'unsetAllUsersSettings' => ['to' => null, 'note' => 'ElggPlugin::unsetAllUsersSettings() removed'],
        'getContent' => ['to' => null, 'note' => 'ElggMenuItem::getContent() removed — use elgg_view_menu_item()'],
        'countObjects' => ['to' => null, 'note' => 'ElggUser::countObjects() removed — use elgg_count_entities()'],
        'listFriends' => ['to' => null, 'note' => 'ElggUser::listFriends() removed — use elgg_list_entities()'],
        'listGroups' => ['to' => null, 'note' => 'ElggUser::listGroups() removed — use elgg_list_entities()'],
    ];

    /**
     * Ambiguous methods that might collide with userland code.
     * Only flag these, don't auto-rename.
     */
    public const AMBIGUOUS = [
        'size' => 'ElggFile::size() → getSize(). Only rename if called on an ElggFile instance.',
        'get' => 'ElggData::get() removed. Only applies to ElggData subclasses.',
        'set' => 'ElggData::set() removed. Only applies to ElggData subclasses.',
        'addToSite' => 'ElggEntity::addToSite() removed — multi-site support dropped.',
        'getSites' => 'ElggEntity::getSites() removed — multi-site support dropped.',
        'removeFromSite' => 'ElggEntity::removeFromSite() removed — multi-site support dropped.',
        'disableMetadata' => 'ElggEntity::disableMetadata() removed — metadata cannot be disabled.',
        'enableMetadata' => 'ElggEntity::enableMetadata() removed — metadata cannot be disabled.',
    ];

    public function getId(): string
    {
        return 'removed-methods';
    }

    public function getDescription(): string
    {
        return 'Rename or flag methods removed in Elgg 3.0';
    }

    public function canAutomate(): bool
    {
        return true;
    }

    public function analyze(string $pluginPath): RuleAnalysis
    {
        $findings = [];
        $allMethods = array_merge(array_keys(self::RENAMES), array_keys(self::AMBIGUOUS));

        foreach ($this->findPhpFiles($pluginPath) as $file) {
            $relativePath = $this->relativePath($pluginPath, $file);
            $code = file_get_contents($file);
            if ($code === false) continue;

            $ast = $this->parse($code);
            if ($ast === null) continue;

            $printer = $this->printer();
            $methodCalls = $this->finder()->find($ast, function (Node $node) use ($allMethods) {
                return $node instanceof Node\Expr\MethodCall
                    && $node->name instanceof Node\Identifier
                    && in_array($node->name->name, $allMethods, true);
            });

            foreach ($methodCalls as $call) {
                /** @var Node\Expr\MethodCall $call */
                $methodName = $call->name->name;

                if (isset(self::RENAMES[$methodName])) {
                    $info = self::RENAMES[$methodName];
                    $desc = $info['to']
                        ? "->{$methodName}() → ->{$info['to']}()"
                        : "->{$methodName}() removed: {$info['note']}";
                } else {
                    $desc = "->{$methodName}() possibly removed: " . self::AMBIGUOUS[$methodName];
                }

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
                ? sprintf('Found %d removed/renamed method call(s)', count($findings))
                : 'No removed method calls found',
        );
    }

    public function apply(string $pluginPath): RuleResult
    {
        $changes = [];
        $warnings = [];

        foreach ($this->findPhpFiles($pluginPath) as $file) {
            $relativePath = $this->relativePath($pluginPath, $file);
            $code = file_get_contents($file);
            if ($code === false) continue;

            $result = $this->transformFile($code);

            if ($result['transformed']) {
                file_put_contents($file, $result['code']);
                $changes[] = new FileChange(
                    file: $relativePath,
                    type: 'modified',
                    description: 'Renamed removed methods',
                );
            }

            foreach ($result['warnings'] as $w) {
                $warnings[] = "{$relativePath}: {$w}";
            }
        }

        if (empty($changes) && empty($warnings)) {
            return new RuleResult(
                ruleId: $this->getId(),
                success: true,
                changes: [],
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
     * @return array{transformed: bool, code: string, warnings: array<string>}
     */
    private function transformFile(string $originalCode): array
    {
        $warnings = [];

        $parsed = $this->parsePreserving($originalCode);
        if ($parsed === null) {
            return ['transformed' => false, 'code' => $originalCode, 'warnings' => $warnings];
        }

        $traverser = new NodeTraverser();
        $visitor = new class($warnings) extends NodeVisitorAbstract {
            private bool $changed = false;

            public function __construct(private array &$warnings) {}

            public function leaveNode(Node $node): ?Node
            {
                if (!$node instanceof Node\Expr\MethodCall) return null;
                if (!$node->name instanceof Node\Identifier) return null;

                $methodName = $node->name->name;

                // Unambiguous renames
                if (isset(RemovedMethods::RENAMES[$methodName])) {
                    $info = RemovedMethods::RENAMES[$methodName];

                    if ($info['to'] !== null) {
                        $node->name = new Node\Identifier($info['to']);
                        $this->changed = true;
                        return $node;
                    }

                    // No replacement — warn
                    $this->warnings[] = "->{$methodName}() removed: {$info['note']}";
                    return null;
                }

                // Ambiguous — warn only
                if (isset(RemovedMethods::AMBIGUOUS[$methodName])) {
                    $this->warnings[] = "->{$methodName}() possibly removed: " . RemovedMethods::AMBIGUOUS[$methodName];
                }

                return null;
            }

            public function hasChanged(): bool
            {
                return $this->changed;
            }
        };

        $traverser->addVisitor($visitor);
        $parsed['new'] = $traverser->traverse($parsed['new']);

        if (!$visitor->hasChanged()) {
            return ['transformed' => false, 'code' => $originalCode, 'warnings' => $warnings];
        }

        return [
            'transformed' => true,
            'code' => $this->printPreserving($parsed['new'], $parsed['old'], $parsed['tokens']),
            'warnings' => $warnings,
        ];
    }
}
