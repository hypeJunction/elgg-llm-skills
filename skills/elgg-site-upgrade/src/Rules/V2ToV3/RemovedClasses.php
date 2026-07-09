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
 * Replaces class names removed in Elgg 3.0.
 */
final class RemovedClasses extends AbstractRule
{
    /**
     * Map of old class name → new class name (or null if removed entirely).
     */
    public const MAP = [
        'FilePluginFile' => ['to' => 'ElggFile', 'note' => 'FilePluginFile → ElggFile'],
        'ElggDiscussionReply' => ['to' => 'ElggComment', 'note' => 'Discussion replies are now comments'],
        'ElggMemcache' => ['to' => null, 'note' => 'Use elgg_get_system_cache() instead'],
        'ElggFileCache' => ['to' => null, 'note' => 'Use elgg_get_system_cache() instead'],
        'ElggStaticVariableCache' => ['to' => null, 'note' => 'Cache pools removed in 3.0'],
        'ElggSharedMemoryCache' => ['to' => null, 'note' => 'Cache pools removed in 3.0'],
    ];

    public function getId(): string
    {
        return 'removed-classes';
    }

    public function getDescription(): string
    {
        return 'Replace class names removed in Elgg 3.0';
    }

    public function canAutomate(): bool
    {
        return true;
    }

    public function analyze(string $pluginPath): RuleAnalysis
    {
        $findings = [];
        $classNames = array_keys(self::MAP);

        foreach ($this->findPhpFiles($pluginPath) as $file) {
            $relativePath = $this->relativePath($pluginPath, $file);
            $code = file_get_contents($file);
            if ($code === false) continue;

            $ast = $this->parse($code);
            if ($ast === null) continue;

            $nodes = $this->finder()->find($ast, function (Node $node) use ($classNames) {
                // Check class names in: new X, X::, instanceof X, extends X, implements X, use X, type hints
                if ($node instanceof Node\Name) {
                    return in_array($node->toString(), $classNames, true);
                }
                return false;
            });

            $printer = $this->printer();
            foreach ($nodes as $n) {
                /** @var Node\Name $n */
                $className = $n->toString();
                $info = self::MAP[$className];
                $desc = $info['to']
                    ? "{$className} → {$info['to']}"
                    : "{$className} removed: {$info['note']}";

                $findings[] = new Finding(
                    file: $relativePath,
                    line: $n->getLine(),
                    description: $desc,
                    code: $className,
                );
            }
        }

        $applicable = count($findings) > 0;

        return new RuleAnalysis(
            ruleId: $this->getId(),
            applicable: $applicable,
            findings: $findings,
            summary: $applicable
                ? sprintf('Found %d removed class reference(s)', count($findings))
                : 'No removed class references found',
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
                    description: 'Replaced removed class names',
                );
            }

            foreach ($result['warnings'] as $w) {
                $warnings[] = "{$relativePath}: {$w}";
            }
        }

        if (empty($changes) && empty($warnings)) {
            return new RuleResult(ruleId: $this->getId(), success: true, changes: []);
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
        $classNames = array_keys(self::MAP);

        $parsed = $this->parsePreserving($originalCode);
        if ($parsed === null) {
            return ['transformed' => false, 'code' => $originalCode, 'warnings' => $warnings];
        }

        $traverser = new NodeTraverser();
        $visitor = new class($classNames, $warnings) extends NodeVisitorAbstract {
            private bool $changed = false;

            public function __construct(
                private readonly array $classNames,
                private array &$warnings,
            ) {}

            public function leaveNode(Node $node): ?Node
            {
                if (!$node instanceof Node\Name) return null;

                $className = $node->toString();
                if (!in_array($className, $this->classNames, true)) return null;

                $info = RemovedClasses::MAP[$className];

                if ($info['to'] !== null) {
                    $this->changed = true;
                    return new Node\Name($info['to']);
                }

                $this->warnings[] = "{$className} removed: {$info['note']}";
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
