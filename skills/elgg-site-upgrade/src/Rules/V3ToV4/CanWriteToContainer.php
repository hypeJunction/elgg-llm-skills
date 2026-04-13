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
 * Flags canWriteToContainer() calls missing required type/subtype args.
 *
 * In Elgg 4.0, canWriteToContainer() requires $type and $subtype parameters.
 * This is a warn-only rule — it cannot determine the correct values automatically.
 */
final class CanWriteToContainer extends AbstractRule
{
    public function getId(): string
    {
        return 'can-write-to-container';
    }

    public function getDescription(): string
    {
        return 'Flag canWriteToContainer() calls missing type/subtype arguments';
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

            $calls = $this->findUnderSpecifiedCalls($ast);
            $printer = $this->printer();

            foreach ($calls as $call) {
                $argCount = count($call->args);
                $findings[] = new Finding(
                    file: $relativePath,
                    line: $call->getLine(),
                    description: "canWriteToContainer() has {$argCount} arg(s), needs 3 (user_guid, type, subtype)",
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
                ? sprintf('Found %d canWriteToContainer() call(s) missing type/subtype', count($findings))
                : 'All canWriteToContainer() calls have correct arguments',
        );
    }

    public function apply(string $pluginPath): RuleResult
    {
        // Warn-only — we can't determine correct type/subtype automatically
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

    /**
     * Find canWriteToContainer() method calls with fewer than 3 arguments.
     *
     * @param array<Node\Stmt> $ast
     * @return array<Node\Expr\MethodCall>
     */
    private function findUnderSpecifiedCalls(array $ast): array
    {
        return $this->finder()->find($ast, function (Node $node) {
            return $node instanceof Node\Expr\MethodCall
                && $node->name instanceof Node\Identifier
                && $node->name->name === 'canWriteToContainer'
                && count($node->args) < 3;
        });
    }
}
