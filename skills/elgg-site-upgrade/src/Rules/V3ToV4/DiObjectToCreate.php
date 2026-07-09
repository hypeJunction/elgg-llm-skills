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
 * Replaces \DI\object() calls with \DI\create() in elgg-services.php.
 *
 * PHP-DI renamed object() to create() — Elgg 4.x ships with the new version.
 */
final class DiObjectToCreate extends AbstractRule
{
    public function getId(): string
    {
        return 'di-object-to-create';
    }

    public function getDescription(): string
    {
        return 'Replace \\DI\\object() with \\DI\\create() in elgg-services.php';
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

            $calls = $this->findDiObjectCalls($ast);
            $printer = $this->printer();

            foreach ($calls as $call) {
                $findings[] = new Finding(
                    file: $relativePath,
                    line: $call->getLine(),
                    description: '\\DI\\object() → \\DI\\create()',
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
                ? sprintf('Found %d \\DI\\object() call(s) to replace', count($findings))
                : 'No \\DI\\object() calls found',
        );
    }

    public function apply(string $pluginPath): RuleResult
    {
        $changes = [];

        foreach ($this->findPhpFiles($pluginPath) as $file) {
            $relativePath = $this->relativePath($pluginPath, $file);
            $code = file_get_contents($file);
            if ($code === false) continue;

            $parsed = $this->parsePreserving($code);
            if ($parsed === null) continue;

            $calls = $this->findDiObjectCalls($parsed['new']);
            if (empty($calls)) continue;

            $traverser = new NodeTraverser();
            $visitor = new class extends NodeVisitorAbstract {
                public bool $changed = false;

                public function leaveNode(Node $node): ?Node
                {
                    if ($node instanceof Node\Expr\FuncCall
                        && $node->name instanceof Node\Name
                        && $this->isDiObject($node->name)
                    ) {
                        $node->name = new Node\Name\FullyQualified('DI\\create');
                        $this->changed = true;
                        return $node;
                    }
                    return null;
                }

                private function isDiObject(Node\Name $name): bool
                {
                    $str = $name->toString();
                    return $str === 'DI\\object' || $str === 'DI\object';
                }
            };

            $traverser->addVisitor($visitor);
            $parsed['new'] = $traverser->traverse($parsed['new']);

            if ($visitor->changed) {
                file_put_contents($file, $this->printPreserving($parsed['new'], $parsed['old'], $parsed['tokens']));
                $changes[] = new FileChange(
                    file: $relativePath,
                    type: 'modified',
                    description: 'Replaced \\DI\\object() with \\DI\\create()',
                );
            }
        }

        return new RuleResult(
            ruleId: $this->getId(),
            success: true,
            changes: $changes,
        );
    }

    /**
     * @param array<Node\Stmt> $ast
     * @return array<Node\Expr\FuncCall>
     */
    private function findDiObjectCalls(array $ast): array
    {
        return $this->finder()->find($ast, function (Node $node) {
            return $node instanceof Node\Expr\FuncCall
                && $node->name instanceof Node\Name
                && ($node->name->toString() === 'DI\\object' || $node->name->toString() === 'DI\object');
        });
    }
}
