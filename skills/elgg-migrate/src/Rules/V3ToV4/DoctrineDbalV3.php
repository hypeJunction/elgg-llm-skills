<?php

declare(strict_types=1);

namespace ElggMigrate\Rules\V3ToV4;

use ElggMigrate\AbstractRule;
use ElggMigrate\Finding;
use ElggMigrate\RuleAnalysis;
use ElggMigrate\RuleResult;
use PhpParser\Node;
use PhpParser\NodeFinder;

/**
 * Doctrine DBAL was upgraded from v2 to v3 in Elgg 4.0.
 * Key breaking change: $qb->fetch() now returns array instead of object.
 * Only files that reference Doctrine DBAL or Elgg QueryBuilder are scanned
 * to minimise false positives on other ->fetch() method calls.
 */
final class DoctrineDbalV3 extends AbstractRule
{
    private const DBAL_MARKERS = [
        'Doctrine\\DBAL',
        'Elgg\\Database\\QueryBuilder',
        'QueryBuilder',
    ];

    private const FLAGGED_METHODS = [
        'fetch'        => '$qb->fetch() returns array in DBAL v3, not object — use array access or elgg()->db->getData($qb)',
        'fetchAll'     => '$qb->fetchAll() returns array[] in DBAL v3 — use elgg()->db->getData($qb) for object rows',
        'fetchColumn'  => '$qb->fetchColumn() renamed to fetchOne() in DBAL v3',
    ];

    public function getId(): string
    {
        return 'doctrine-dbal-v3-4x';
    }

    public function getDescription(): string
    {
        return 'Flag Doctrine DBAL v2 method calls that changed in v3 (fetch/fetchAll/fetchColumn)';
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

            if (!$this->fileReferencesDbal($code)) continue;

            $ast = $this->parse($code);
            if ($ast === null) continue;

            $fileFindings = $this->findDbalMethodCalls($ast, $relativePath);
            $findings = array_merge($findings, $fileFindings);
        }

        $applicable = count($findings) > 0;

        return new RuleAnalysis(
            ruleId: $this->getId(),
            applicable: $applicable,
            findings: $findings,
            summary: $applicable
                ? sprintf('Found %d Doctrine DBAL v2 method call(s) to update for v3', count($findings))
                : 'No Doctrine DBAL v2 method calls found',
        );
    }

    public function apply(string $pluginPath): RuleResult
    {
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

    private function fileReferencesDbal(string $code): bool
    {
        foreach (self::DBAL_MARKERS as $marker) {
            if (str_contains($code, $marker)) {
                return true;
            }
        }
        return false;
    }

    /** @return Finding[] */
    private function findDbalMethodCalls(array $ast, string $relativePath): array
    {
        $findings = [];
        $printer = $this->printer();

        $methodCalls = $this->finder()->find($ast, function (Node $node) {
            return $node instanceof Node\Expr\MethodCall
                && $node->name instanceof Node\Identifier
                && isset(self::FLAGGED_METHODS[$node->name->toString()]);
        });

        foreach ($methodCalls as $call) {
            /** @var Node\Expr\MethodCall $call */
            $method = $call->name->toString();
            $findings[] = new Finding(
                file: $relativePath,
                line: $call->getLine(),
                description: self::FLAGGED_METHODS[$method],
                code: $printer->prettyPrintExpr($call),
            );
        }

        return $findings;
    }
}
