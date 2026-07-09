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
 * Handle removed functions that appear in expressions (if conditions,
 * ternaries, assignments) — not standalone statements.
 *
 * The existing RemovedFunctions rule only removes standalone `function();`
 * statements and warns for expression context. This rule specifically
 * handles expression context by replacing with appropriate values.
 *
 * - is_memcache_available() -> false
 * - get_db_tables() -> []
 * - elgg_get_metastring_id() -> null
 */
final class RemovedFunctionsInExpressions extends AbstractRule
{
    /**
     * Map of function name -> replacement expression info.
     */
    public const MAP = [
        'is_memcache_available' => [
            'replace' => 'false',
            'note' => 'Memcache check removed in 3.0 — use elgg_get_system_cache()',
        ],
        'get_db_tables' => [
            'replace' => '[]',
            'note' => 'get_db_tables() removed in 3.0',
        ],
        'elgg_get_metastring_id' => [
            'replace' => 'null',
            'note' => 'Metastrings table removed in 3.0',
        ],
    ];

    public function getId(): string
    {
        return 'removed-functions-in-expressions';
    }

    public function getDescription(): string
    {
        return 'Replace removed function calls in expressions with appropriate default values';
    }

    public function canAutomate(): bool
    {
        return true;
    }

    public function analyze(string $pluginPath): RuleAnalysis
    {
        $findings = [];
        $targetNames = array_keys(self::MAP);

        foreach ($this->findPhpFiles($pluginPath) as $file) {
            $relativePath = $this->relativePath($pluginPath, $file);
            $code = file_get_contents($file);
            if ($code === false) continue;

            $ast = $this->parse($code);
            if ($ast === null) continue;

            $calls = $this->findFunctionCalls($ast, $targetNames);
            $printer = $this->printer();

            foreach ($calls as $call) {
                $funcName = $call->name->toString();
                $info = self::MAP[$funcName];

                $findings[] = new Finding(
                    file: $relativePath,
                    line: $call->getLine(),
                    description: "{$funcName}() in expression — will replace with {$info['replace']}",
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
                ? sprintf('Found %d removed function call(s) in expressions', count($findings))
                : 'No removed function calls in expressions found',
        );
    }

    public function apply(string $pluginPath): RuleResult
    {
        $changes = [];
        $warnings = [];
        $targetNames = array_keys(self::MAP);

        foreach ($this->findPhpFiles($pluginPath) as $file) {
            $relativePath = $this->relativePath($pluginPath, $file);
            $code = file_get_contents($file);
            if ($code === false) continue;

            $ast = $this->parse($code);
            if ($ast === null) continue;

            $calls = $this->findFunctionCalls($ast, $targetNames);
            if (empty($calls)) continue;

            $result = $this->transformFile($code);

            if ($result['transformed']) {
                file_put_contents($file, $result['code']);
                $changes[] = new FileChange(
                    file: $relativePath,
                    type: 'modified',
                    description: 'Replaced removed function calls in expressions',
                );

                foreach ($result['warnings'] as $w) {
                    $warnings[] = "{$relativePath}: {$w}";
                }
            }
        }

        return new RuleResult(
            ruleId: $this->getId(),
            success: true,
            changes: $changes,
            warnings: $warnings,
        );
    }

    /**
     * Build the replacement node for a given function.
     */
    private static function makeReplacement(string $funcName): Node\Expr
    {
        $info = self::MAP[$funcName];

        return match ($info['replace']) {
            'false' => new Node\Expr\ConstFetch(new Node\Name('false')),
            'null' => new Node\Expr\ConstFetch(new Node\Name('null')),
            '[]' => new Node\Expr\Array_(),
        };
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
            /** @var \SplObjectStorage If_ nodes marked for removal/replacement */
            private \SplObjectStorage $markedIfs;

            public function __construct(private array &$warnings)
            {
                $this->markedIfs = new \SplObjectStorage();
            }

            public function enterNode(Node $node): int|Node|null
            {
                // Mark if (removed_function()) for removal BEFORE children are visited
                // This prevents the child FuncCall from being replaced independently
                if ($node instanceof Node\Stmt\If_
                    && $node->cond instanceof Node\Expr\FuncCall
                    && $node->cond->name instanceof Node\Name
                    && isset(RemovedFunctionsInExpressions::MAP[$node->cond->name->toString()])
                ) {
                    $funcName = $node->cond->name->toString();
                    $info = RemovedFunctionsInExpressions::MAP[$funcName];

                    if ($info['replace'] === 'false') {
                        $this->markedIfs->attach($node);
                        // Don't traverse children — we're removing the whole if
                        return NodeTraverser::DONT_TRAVERSE_CHILDREN;
                    }
                }

                return null;
            }

            public function leaveNode(Node $node): int|Node|array|null
            {
                // Handle marked if statements
                if ($node instanceof Node\Stmt\If_ && $this->markedIfs->contains($node)) {
                    $funcName = $node->cond->name->toString();
                    $info = RemovedFunctionsInExpressions::MAP[$funcName];
                    $this->warnings[] = "{$funcName}() removed: {$info['note']}";
                    $this->changed = true;

                    if (!empty($node->else)) {
                        return $node->else->stmts;
                    }
                    return NodeTraverser::REMOVE_NODE;
                }

                // Handle ternary: removed_function() ? a : b -> b
                if ($node instanceof Node\Expr\Ternary
                    && $node->cond instanceof Node\Expr\FuncCall
                    && $node->cond->name instanceof Node\Name
                    && isset(RemovedFunctionsInExpressions::MAP[$node->cond->name->toString()])
                ) {
                    $funcName = $node->cond->name->toString();
                    $info = RemovedFunctionsInExpressions::MAP[$funcName];

                    if ($info['replace'] === 'false') {
                        $this->warnings[] = "{$funcName}() removed: {$info['note']}";
                        $this->changed = true;
                        return $node->else ?? new Node\Expr\ConstFetch(new Node\Name('null'));
                    }
                }

                // Handle any remaining function calls in expression context:
                // $x = removed_function() -> $x = replacement
                if ($node instanceof Node\Expr\FuncCall
                    && $node->name instanceof Node\Name
                    && isset(RemovedFunctionsInExpressions::MAP[$node->name->toString()])
                ) {
                    $funcName = $node->name->toString();
                    $info = RemovedFunctionsInExpressions::MAP[$funcName];
                    $this->warnings[] = "{$funcName}() removed: {$info['note']}";
                    $this->changed = true;
                    return RemovedFunctionsInExpressions::makeReplacementPublic($funcName);
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

    /**
     * Public wrapper so anonymous visitor class can call it.
     */
    public static function makeReplacementPublic(string $funcName): Node\Expr
    {
        return self::makeReplacement($funcName);
    }
}
