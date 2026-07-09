<?php

declare(strict_types=1);

namespace ElggMigrate\Rules\V6ToV7;

use ElggMigrate\AbstractRule;
use ElggMigrate\FileChange;
use ElggMigrate\Finding;
use ElggMigrate\RuleAnalysis;
use ElggMigrate\RuleResult;
use PhpParser\Node;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitorAbstract;

/**
 * Replaces elgg_reset_system_cache() with _elgg_services()->systemCache->clear().
 *
 * In Elgg 7.x the global function elgg_reset_system_cache() was removed.
 * The equivalent operation is to call clear() on the systemCache service.
 */
final class ResetSystemCache extends AbstractRule
{
    public function getId(): string
    {
        return 'reset-system-cache-7x';
    }

    public function getDescription(): string
    {
        return 'Replace elgg_reset_system_cache() with _elgg_services()->systemCache->clear()';
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
            if ($code === false) {
                continue;
            }

            $ast = $this->parse($code);
            if ($ast === null) {
                continue;
            }

            $calls = $this->findFunctionCalls($ast, ['elgg_reset_system_cache']);
            $printer = $this->printer();

            foreach ($calls as $call) {
                $findings[] = new Finding(
                    file: $relativePath,
                    line: $call->getLine(),
                    description: 'elgg_reset_system_cache() removed in 7.x — replace with _elgg_services()->systemCache->clear()',
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
                ? sprintf('Found %d elgg_reset_system_cache() call(s) to replace', count($findings))
                : 'No elgg_reset_system_cache() calls found',
        );
    }

    public function apply(string $pluginPath): RuleResult
    {
        $changes = [];

        foreach ($this->findPhpFiles($pluginPath) as $file) {
            $relativePath = $this->relativePath($pluginPath, $file);
            $code = file_get_contents($file);
            if ($code === false) {
                continue;
            }

            $parsed = $this->parsePreserving($code);
            if ($parsed === null) {
                continue;
            }

            $traverser = new NodeTraverser();
            $visitor = new class extends NodeVisitorAbstract {
                public bool $changed = false;

                public function leaveNode(Node $node): ?Node
                {
                    if (!$node instanceof Node\Expr\FuncCall) {
                        return null;
                    }
                    if (!$node->name instanceof Node\Name) {
                        return null;
                    }
                    if ($node->name->toString() !== 'elgg_reset_system_cache') {
                        return null;
                    }

                    // Build: _elgg_services()->systemCache->clear()
                    $servicesCall = new Node\Expr\FuncCall(
                        new Node\Name('_elgg_services'),
                        [],
                    );

                    $systemCacheFetch = new Node\Expr\PropertyFetch(
                        $servicesCall,
                        new Node\Identifier('systemCache'),
                    );

                    $clearCall = new Node\Expr\MethodCall(
                        $systemCacheFetch,
                        new Node\Identifier('clear'),
                        [],
                    );

                    $this->changed = true;

                    return $clearCall;
                }
            };

            $traverser->addVisitor($visitor);
            $parsed['new'] = $traverser->traverse($parsed['new']);

            if (!$visitor->changed) {
                continue;
            }

            $newCode = $this->printPreserving($parsed['new'], $parsed['old'], $parsed['tokens']);
            file_put_contents($file, $newCode);

            $changes[] = new FileChange(
                file: $relativePath,
                type: 'modified',
                description: 'Replaced elgg_reset_system_cache() with _elgg_services()->systemCache->clear()',
            );
        }

        return new RuleResult(
            ruleId: $this->getId(),
            success: true,
            changes: $changes,
            warnings: [],
        );
    }
}
