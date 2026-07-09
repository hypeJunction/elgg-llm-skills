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
 * Removes elgg_register_ajax_view() calls.
 * In 3.x, all views are inherently ajax-capable.
 */
final class ElggRegisterAjaxView extends AbstractRule
{
    public function getId(): string
    {
        return 'elgg-register-ajax-view';
    }

    public function getDescription(): string
    {
        return 'Remove elgg_register_ajax_view() calls (views are ajax-capable by default in 3.0)';
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

            $calls = $this->findFunctionCalls($ast, ['elgg_register_ajax_view']);
            $printer = $this->printer();

            foreach ($calls as $call) {
                $findings[] = new Finding(
                    file: $relativePath,
                    line: $call->getLine(),
                    description: "elgg_register_ajax_view() no longer needed — views are ajax-capable by default",
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
                ? sprintf('Found %d elgg_register_ajax_view() call(s) to remove', count($findings))
                : 'No ajax view registrations found',
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

            $parsed = $this->parsePreserving($code);
            if ($parsed === null) continue;

            if (empty($this->findFunctionCalls($parsed['new'], ['elgg_register_ajax_view']))) continue;

            $traverser = new NodeTraverser();
            $visitor = new class() extends NodeVisitorAbstract {
                public bool $changed = false;

                public function leaveNode(Node $node): int|Node|null
                {
                    if (!$node instanceof Node\Stmt\Expression) return null;
                    if (!$node->expr instanceof Node\Expr\FuncCall) return null;
                    if (!$node->expr->name instanceof Node\Name) return null;
                    if ($node->expr->name->toString() !== 'elgg_register_ajax_view') return null;

                    $this->changed = true;
                    return NodeTraverser::REMOVE_NODE;
                }
            };

            $traverser->addVisitor($visitor);
            $parsed['new'] = $traverser->traverse($parsed['new']);

            if ($visitor->changed) {
                file_put_contents($file, $this->printPreserving($parsed['new'], $parsed['old'], $parsed['tokens']));
                $changes[] = new FileChange(
                    file: $relativePath,
                    type: 'modified',
                    description: 'Removed elgg_register_ajax_view() calls',
                );
            }
        }

        if (empty($changes)) {
            return new RuleResult(ruleId: $this->getId(), success: true, changes: []);
        }

        return new RuleResult(
            ruleId: $this->getId(),
            success: true,
            changes: $changes,
            warnings: $warnings,
        );
    }
}
