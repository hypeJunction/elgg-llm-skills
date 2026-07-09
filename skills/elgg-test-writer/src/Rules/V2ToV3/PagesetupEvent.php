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
 * Flags `pagesetup, system` event registrations removed in 3.0.
 *
 * The `pagesetup` event no longer fires. Logic must move to `init, system`
 * or to specific view/menu hooks.
 */
final class PagesetupEvent extends AbstractRule
{
    public function getId(): string
    {
        return 'pagesetup-event';
    }

    public function getDescription(): string
    {
        return 'Flag pagesetup event registrations (removed in 3.0)';
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

            $calls = $this->findFunctionCalls($ast, ['elgg_register_event_handler']);
            $printer = $this->printer();

            foreach ($calls as $call) {
                if ($this->isPagesetupRegistration($call)) {
                    $findings[] = new Finding(
                        file: $relativePath,
                        line: $call->getLine(),
                        description: "pagesetup event removed — move logic to init, system or menu/view hooks",
                        code: $printer->prettyPrintExpr($call),
                    );
                }
            }
        }

        $applicable = count($findings) > 0;

        return new RuleAnalysis(
            ruleId: $this->getId(),
            applicable: $applicable,
            findings: $findings,
            summary: $applicable
                ? sprintf('Found %d pagesetup event registration(s)', count($findings))
                : 'No pagesetup registrations found',
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
                    description: 'Removed pagesetup event registrations',
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

    private function isPagesetupRegistration(Node\Expr\FuncCall $call): bool
    {
        return isset($call->args[0])
            && $call->args[0]->value instanceof Node\Scalar\String_
            && $call->args[0]->value->value === 'pagesetup';
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

            public function leaveNode(Node $node): int|Node|null
            {
                if (!$node instanceof Node\Stmt\Expression) return null;
                if (!$node->expr instanceof Node\Expr\FuncCall) return null;
                if (!$node->expr->name instanceof Node\Name) return null;
                if ($node->expr->name->toString() !== 'elgg_register_event_handler') return null;

                $call = $node->expr;
                if (isset($call->args[0])
                    && $call->args[0]->value instanceof Node\Scalar\String_
                    && $call->args[0]->value->value === 'pagesetup'
                ) {
                    $printer = new \PhpParser\PrettyPrinter\Standard();
                    $callback = isset($call->args[2]) ? $printer->prettyPrintExpr($call->args[2]->value) : 'unknown';
                    $this->warnings[] = "Removed pagesetup registration (callback: {$callback}). Move this logic to init, system or a menu/view hook.";
                    $this->changed = true;
                    return NodeTraverser::REMOVE_NODE;
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
