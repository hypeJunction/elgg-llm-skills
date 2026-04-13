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
 * Strips removed interfaces from class `implements` clauses.
 *
 * In Elgg 3.x, these interfaces were removed:
 * - Elgg\Cache\Pool
 * - Exportable
 * - Importable
 */
final class RemoveImplementsInterface extends AbstractRule
{
    /**
     * Fully-qualified interface names removed in 3.0.
     */
    public const REMOVED_INTERFACES = [
        'Elgg\\Cache\\Pool',
        'Exportable',
        'Importable',
    ];

    /**
     * Short names used for matching in implements clauses.
     */
    private const SHORT_NAMES = [
        'Pool',
        'Exportable',
        'Importable',
    ];

    public function getId(): string
    {
        return 'remove-implements-interface';
    }

    public function getDescription(): string
    {
        return 'Strip removed interfaces (Elgg\Cache\Pool, Exportable, Importable) from class implements clauses';
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

            $ast = $this->parse($code);
            if ($ast === null) continue;

            $classes = $this->finder()->find($ast, fn(Node $node) => $node instanceof Node\Stmt\Class_);

            foreach ($classes as $class) {
                /** @var Node\Stmt\Class_ $class */
                foreach ($class->implements as $iface) {
                    $ifaceName = $iface->toString();
                    if ($this->isRemovedInterface($ifaceName)) {
                        $findings[] = new Finding(
                            file: $relativePath,
                            line: $iface->getLine(),
                            description: "Implements removed interface: {$ifaceName}",
                            code: $ifaceName,
                        );
                    }
                }
            }
        }

        $applicable = count($findings) > 0;

        return new RuleAnalysis(
            ruleId: $this->getId(),
            applicable: $applicable,
            findings: $findings,
            summary: $applicable
                ? sprintf('Found %d removed interface reference(s)', count($findings))
                : 'No removed interface references found',
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

            $ast = $this->parse($code);
            if ($ast === null) continue;

            $result = $this->transformFile($ast, $code);

            if ($result['transformed']) {
                file_put_contents($file, $result['code']);
                $changes[] = new FileChange(
                    file: $relativePath,
                    type: 'modified',
                    description: 'Removed implements of removed interfaces',
                );
            }

            foreach ($result['warnings'] as $w) {
                $warnings[] = "{$relativePath}: {$w}";
            }
        }

        return new RuleResult(
            ruleId: $this->getId(),
            success: true,
            changes: $changes,
            warnings: $warnings,
        );
    }

    private function isRemovedInterface(string $name): bool
    {
        if (in_array($name, self::REMOVED_INTERFACES, true)) {
            return true;
        }
        // Also match short names (e.g. Pool, Exportable, Importable)
        if (in_array($name, self::SHORT_NAMES, true)) {
            return true;
        }
        // Match partial qualifications like Cache\Pool
        foreach (self::REMOVED_INTERFACES as $fqn) {
            if (str_ends_with($fqn, '\\' . $name) || $fqn === $name) {
                return true;
            }
        }
        return false;
    }

    /**
     * @param array<Node\Stmt> $ast
     * @return array{transformed: bool, code: string, warnings: array<string>}
     */
    private function transformFile(array $ast, string $originalCode): array
    {
        $warnings = [];
        $rule = $this;

        $traverser = new NodeTraverser();
        $visitor = new class($rule, $warnings) extends NodeVisitorAbstract {
            private bool $changed = false;
            /** @var array<string> Removed use import FQNs to track */
            private array $removedUseNames = [];

            public function __construct(
                private readonly RemoveImplementsInterface $rule,
                private array &$warnings,
            ) {}

            public function leaveNode(Node $node): int|Node|null
            {
                // Remove use statements for removed interfaces
                if ($node instanceof Node\Stmt\Use_) {
                    $remaining = [];
                    foreach ($node->uses as $use) {
                        $useName = $use->name->toString();
                        if ($this->rule->isRemovedInterfacePublic($useName)) {
                            $this->changed = true;
                            $this->removedUseNames[] = $use->alias?->toString() ?? $use->name->getLast();
                        } else {
                            $remaining[] = $use;
                        }
                    }
                    if (empty($remaining)) {
                        return NodeTraverser::REMOVE_NODE;
                    }
                    if (count($remaining) < count($node->uses)) {
                        $node->uses = $remaining;
                        return $node;
                    }
                    return null;
                }

                // Strip removed interfaces from class implements
                if ($node instanceof Node\Stmt\Class_ && !empty($node->implements)) {
                    $remaining = [];
                    foreach ($node->implements as $iface) {
                        $ifaceName = $iface->toString();
                        if ($this->rule->isRemovedInterfacePublic($ifaceName)) {
                            $this->changed = true;
                        } else {
                            $remaining[] = $iface;
                        }
                    }
                    $node->implements = $remaining;
                    return $node;
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

    /**
     * Public wrapper so anonymous visitor class can access the check.
     */
    public function isRemovedInterfacePublic(string $name): bool
    {
        return $this->isRemovedInterface($name);
    }
}
