<?php

declare(strict_types=1);

namespace ElggMigrate\Rules\V3ToV4;

use ElggMigrate\AbstractRule;
use ElggMigrate\FileChange;
use ElggMigrate\Finding;
use ElggMigrate\RuleAnalysis;
use ElggMigrate\RuleResult;
use PhpParser\Node;
use PhpParser\NodeFinder;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitorAbstract;
use PhpParser\PrettyPrinter;

/**
 * Refactors paired elgg_set_ignore_access(true/false) calls into elgg_call() closures.
 *
 * Before:
 *   elgg_set_ignore_access(true);
 *   $entities = elgg_get_entities([...]);
 *   elgg_set_ignore_access(false);
 *
 * After:
 *   $entities = elgg_call(ELGG_IGNORE_ACCESS, function() use ($options) {
 *       return elgg_get_entities([...]);
 *   });
 *
 * Also handles elgg_show_disabled_entities() pairs → ELGG_SHOW_DISABLED_ENTITIES.
 *
 * Pairs spanning conditional branches are flagged as warnings but NOT transformed.
 */
final class ElggCallIgnoreAccess extends AbstractRule
{
    /**
     * Maps the setter function name to the constant used in elgg_call().
     */
    private const SETTER_TO_CONSTANT = [
        'elgg_set_ignore_access'       => 'ELGG_IGNORE_ACCESS',
        'elgg_show_disabled_entities'  => 'ELGG_SHOW_DISABLED_ENTITIES',
    ];

    public function getId(): string
    {
        return 'elgg-call-ignore-access';
    }

    public function getDescription(): string
    {
        return 'Refactor elgg_set_ignore_access(true/false) and elgg_show_disabled_entities(true/false) pairs into elgg_call() closures';
    }

    public function canAutomate(): bool
    {
        return true;
    }

    public function analyze(string $pluginPath): RuleAnalysis
    {
        $findings = [];

        foreach ($this->findPhpFiles($pluginPath) as $file) {
            if (str_contains($file, '/vendor/')) {
                continue;
            }
            $relativePath = $this->relativePath($pluginPath, $file);
            $code = file_get_contents($file);
            if ($code === false) {
                continue;
            }

            $ast = $this->parse($code);
            if ($ast === null) {
                continue;
            }

            $findings = array_merge($findings, $this->analyzeAst($ast, $relativePath));
        }

        $applicable = count($findings) > 0;

        return new RuleAnalysis(
            ruleId: $this->getId(),
            applicable: $applicable,
            findings: $findings,
            summary: $applicable
                ? sprintf('Found %d ignore-access/show-disabled pair(s) to refactor', count($findings))
                : 'No elgg_set_ignore_access / elgg_show_disabled_entities pairs found',
        );
    }

    public function apply(string $pluginPath): RuleResult
    {
        $changes = [];
        $warnings = [];

        foreach ($this->findPhpFiles($pluginPath) as $file) {
            if (str_contains($file, '/vendor/')) {
                continue;
            }
            $relativePath = $this->relativePath($pluginPath, $file);
            $code = file_get_contents($file);
            if ($code === false) {
                continue;
            }

            $parsed = $this->parsePreserving($code);
            if ($parsed === null) {
                continue;
            }

            [$newStmts, $fileWarnings, $didChange] = $this->transformAst($parsed['new']);

            foreach ($fileWarnings as $warning) {
                $warnings[] = $relativePath . ': ' . $warning;
            }

            if (!$didChange) {
                continue;
            }

            // @phpstan-ignore-next-line — suppresses PhpParser's "Undefined property" notice
            // when format-preserving printer accesses fixup-map sub-nodes on newly-created nodes;
            // the printer falls back to pretty-printing for the new nodes, output is correct.
            $printed = @$this->printPreserving($newStmts, $parsed['old'], $parsed['tokens']);
            file_put_contents($file, $printed);
            $changes[] = new FileChange(
                file: $relativePath,
                type: 'modified',
                description: 'Refactored ignore-access / show-disabled pairs into elgg_call() closures',
            );
        }

        return new RuleResult(
            ruleId: $this->getId(),
            success: true,
            changes: $changes,
            warnings: $warnings,
        );
    }

    // -------------------------------------------------------------------------
    // Analysis helpers
    // -------------------------------------------------------------------------

    /**
     * Scan the AST for function/method bodies containing setter pairs and produce findings.
     *
     * @param array<Node\Stmt> $ast
     * @return array<Finding>
     */
    private function analyzeAst(array $ast, string $relPath): array
    {
        $findings = [];
        $finder = new NodeFinder();

        // Find all function/method bodies
        $bodies = $this->collectBodies($ast, $finder);

        foreach ($bodies as $stmts) {
            foreach (array_keys(self::SETTER_TO_CONSTANT) as $setter) {
                $pairs = $this->findPairs($stmts, $setter);
                foreach ($pairs as $pair) {
                    ['trueLine' => $trueLine, 'inBranch' => $inBranch] = $pair;
                    $findings[] = new Finding(
                        file: $relPath,
                        line: $trueLine,
                        description: $inBranch
                            ? sprintf(
                                '%s(true) / %s(false) pair spans a conditional branch — manual refactor required',
                                $setter,
                                $setter,
                            )
                            : sprintf(
                                '%s(true) / %s(false) pair can be refactored to elgg_call(%s, fn)',
                                $setter,
                                $setter,
                                self::SETTER_TO_CONSTANT[$setter],
                            ),
                        code: $setter . '(true) … ' . $setter . '(false)',
                    );
                }
            }
        }

        return $findings;
    }

    // -------------------------------------------------------------------------
    // Transform helpers
    // -------------------------------------------------------------------------

    /**
     * Walk the AST and replace setter pairs with elgg_call() closures.
     *
     * @param array<Node\Stmt> $stmts
     * @return array{0: array<Node\Stmt>, 1: array<string>, 2: bool}  [newStmts, warnings, changed]
     */
    private function transformAst(array $stmts): array
    {
        $warnings = [];
        $changed = false;

        // We process recursively, depth-first.
        // The visitor re-builds each statement list when it finds a transformable pair.
        $visitor = new class(self::SETTER_TO_CONSTANT) extends NodeVisitorAbstract {
            public bool $changed = false;
            /** @var array<string> */
            public array $warnings = [];

            /** @param array<string, string> $setterToConst */
            public function __construct(private readonly array $setterToConst) {}

            public function leaveNode(Node $node): ?Node
            {
                // We only care about nodes that own a statement list we can rewrite.
                $stmtsList = $this->getStmtList($node);
                if ($stmtsList === null) {
                    return null;
                }

                [$newList, $warnings, $didChange] = $this->processStmts($stmtsList, $node);

                // Always surface warnings (e.g. pairs skipped due to conditional branches)
                $this->warnings = array_merge($this->warnings, $warnings);

                if (!$didChange) {
                    return null;
                }

                $this->changed = true;
                $this->setStmtList($node, $newList);

                return $node;
            }

            /**
             * @param array<Node\Stmt> $stmts
             * @return array{0: array<Node\Stmt>, 1: array<string>, 2: bool}
             */
            private function processStmts(array $stmts, Node $ownerNode): array
            {
                $warnings = [];
                $changed = false;

                // Collect variables that are assigned in scope BEFORE each statement index,
                // so we can determine what needs to be captured via `use`.
                // We iterate over the flat statement list and look for the FIRST true-call
                // whose matching false-call is also at the TOP LEVEL of this same list.

                foreach (array_keys($this->setterToConst) as $setter) {
                    [$stmts, $w, $c] = $this->processSetterInList($stmts, $setter, $ownerNode);
                    $warnings = array_merge($warnings, $w);
                    if ($c) {
                        $changed = true;
                    }
                }

                return [$stmts, $warnings, $changed];
            }

            /**
             * @param array<Node\Stmt> $stmts
             * @return array{0: array<Node\Stmt>, 1: array<string>, 2: bool}
             */
            private function processSetterInList(array $stmts, string $setter, Node $ownerNode): array
            {
                $warnings = [];
                $changed = false;

                while (true) {
                    $trueIdx = null;

                    for ($i = 0, $n = count($stmts); $i < $n; $i++) {
                        if ($this->isSetterCall($stmts[$i], $setter, true)) {
                            $trueIdx = $i;
                            break;
                        }
                    }

                    if ($trueIdx === null) {
                        break;
                    }

                    // Find the matching false-call at the top level of this list
                    $falseIdx = null;
                    for ($j = $trueIdx + 1, $n = count($stmts); $j < $n; $j++) {
                        if ($this->isSetterCall($stmts[$j], $setter, false)) {
                            $falseIdx = $j;
                            break;
                        }
                        // If we hit another true-call before a false-call,
                        // stop — this true-call's pair is after a nested true-call
                        // which is unusual; leave it alone.
                        if ($this->isSetterCall($stmts[$j], $setter, true)) {
                            break;
                        }
                    }

                    if ($falseIdx === null) {
                        // No matching false-call at the top level — check if there's
                        // a setter inside a branch (conditional).
                        $inBranch = $this->pairInBranch($stmts, $trueIdx, $setter);
                        if ($inBranch) {
                            $line = $stmts[$trueIdx]->getLine();
                            $warnings[] = sprintf(
                                'Line %d: %s(true)/%s(false) pair spans a conditional branch — manual refactor required',
                                $line,
                                $setter,
                                $setter,
                            );
                        }
                        // Can't transform; remove this true-call from consideration to avoid infinite loop
                        break;
                    }

                    // Collect the intervening statements
                    $innerStmts = array_slice($stmts, $trueIdx + 1, $falseIdx - $trueIdx - 1);

                    // Collect variables that are defined in the outer scope before $trueIdx
                    $outerVars = $this->collectDefinedVars(array_slice($stmts, 0, $trueIdx), $ownerNode);

                    // Determine which outer vars are read inside $innerStmts
                    $usedOuterVars = $this->collectUsedOuterVars($innerStmts, $outerVars);

                    // Build the elgg_call() replacement
                    $callNode = $this->buildElggCall(
                        $setter,
                        $this->setterToConst[$setter],
                        $innerStmts,
                        $usedOuterVars,
                        $stmts[$trueIdx],
                    );

                    // Replace [true-call, ...inner..., false-call] with the elgg_call() expression statement
                    array_splice($stmts, $trueIdx, $falseIdx - $trueIdx + 1, [$callNode]);
                    $changed = true;
                }

                return [$stmts, $warnings, $changed];
            }

            /**
             * Check whether a statement is a call to $setter with a boolean argument.
             */
            private function isSetterCall(Node\Stmt $stmt, string $setter, bool $value): bool
            {
                if (!$stmt instanceof Node\Stmt\Expression) {
                    return false;
                }
                $expr = $stmt->expr;
                if (!$expr instanceof Node\Expr\FuncCall) {
                    return false;
                }
                if (!$expr->name instanceof Node\Name) {
                    return false;
                }
                if ($expr->name->toString() !== $setter) {
                    return false;
                }
                if (count($expr->args) !== 1) {
                    return false;
                }
                $arg = $expr->args[0];
                if (!$arg instanceof Node\Arg) {
                    return false;
                }
                $argVal = $arg->value;
                if ($value) {
                    return $argVal instanceof Node\Expr\ConstFetch
                        && strtolower($argVal->name->toString()) === 'true';
                }
                return $argVal instanceof Node\Expr\ConstFetch
                    && strtolower($argVal->name->toString()) === 'false';
            }

            /**
             * Check if the matching false-call appears inside a branch (if/else/switch/try etc.)
             * below the true-call at $trueIdx in $stmts.
             */
            private function pairInBranch(array $stmts, int $trueIdx, string $setter): bool
            {
                $finder = new NodeFinder();
                for ($j = $trueIdx + 1, $n = count($stmts); $j < $n; $j++) {
                    // Look for setter calls nested inside branches
                    $found = $finder->find([$stmts[$j]], function (Node $node) use ($setter): bool {
                        if (!$node instanceof Node\Expr\FuncCall) {
                            return false;
                        }
                        if (!$node->name instanceof Node\Name) {
                            return false;
                        }
                        return $node->name->toString() === $setter;
                    });
                    if (!empty($found)) {
                        return true;
                    }
                }
                return false;
            }

            /**
             * Collect the names of variables that are assigned/defined before a given statement index.
             * Also includes method/function parameters.
             *
             * @param array<Node\Stmt> $stmtsBefore
             * @return array<string> variable names (without $)
             */
            private function collectDefinedVars(array $stmtsBefore, Node $ownerNode): array
            {
                $vars = [];

                // Include function/method parameters
                if ($ownerNode instanceof Node\Stmt\Function_ || $ownerNode instanceof Node\Stmt\ClassMethod) {
                    foreach ($ownerNode->params as $param) {
                        if ($param->var instanceof Node\Expr\Variable && is_string($param->var->name)) {
                            $vars[] = $param->var->name;
                        }
                    }
                }

                // Include variables assigned before the true-call
                $finder = new NodeFinder();
                $assigns = $finder->find($stmtsBefore, function (Node $node): bool {
                    return $node instanceof Node\Expr\Assign
                        && $node->var instanceof Node\Expr\Variable
                        && is_string($node->var->name);
                });

                foreach ($assigns as $assign) {
                    /** @var Node\Expr\Assign $assign */
                    $vars[] = $assign->var->name;
                }

                // Include foreach/for loop variables
                $foreachNodes = $finder->find($stmtsBefore, fn(Node $n) => $n instanceof Node\Stmt\Foreach_);
                foreach ($foreachNodes as $fe) {
                    /** @var Node\Stmt\Foreach_ $fe */
                    if ($fe->valueVar instanceof Node\Expr\Variable && is_string($fe->valueVar->name)) {
                        $vars[] = $fe->valueVar->name;
                    }
                    if ($fe->keyVar instanceof Node\Expr\Variable && is_string($fe->keyVar->name)) {
                        $vars[] = $fe->keyVar->name;
                    }
                }

                return array_unique($vars);
            }

            /**
             * Determine which of the $outerVars are actually read inside $innerStmts.
             *
             * @param array<Node\Stmt> $innerStmts
             * @param array<string> $outerVars
             * @return array<string>
             */
            private function collectUsedOuterVars(array $innerStmts, array $outerVars): array
            {
                if (empty($outerVars) || empty($innerStmts)) {
                    return [];
                }

                $finder = new NodeFinder();
                $used = [];

                $varNodes = $finder->find($innerStmts, fn(Node $n): bool =>
                    $n instanceof Node\Expr\Variable && is_string($n->name)
                );

                foreach ($varNodes as $varNode) {
                    /** @var Node\Expr\Variable $varNode */
                    if (in_array($varNode->name, $outerVars, true)) {
                        $used[] = $varNode->name;
                    }
                }

                return array_unique($used);
            }

            /**
             * Build the elgg_call(CONST, function() use (...) { ... }) node.
             *
             * The inner statements are wrapped in a closure. If the closure
             * contains only a single expression statement we wrap it in a return
             * so the result can be assigned. For multiple statements the last
             * expression statement also gets a return.
             *
             * @param array<Node\Stmt> $innerStmts
             * @param array<string> $useVars
             */
            private function buildElggCall(
                string $setter,
                string $constant,
                array $innerStmts,
                array $useVars,
                Node\Stmt $trueCallStmt,
            ): Node\Stmt\Expression {
                // Wrap the last expression statement in return if it isn't already
                $closureStmts = $innerStmts;
                if (!empty($closureStmts)) {
                    $last = end($closureStmts);
                    $lastIdx = count($closureStmts) - 1;
                    if ($last instanceof Node\Stmt\Expression && !$last->expr instanceof Node\Expr\Assign) {
                        $closureStmts[$lastIdx] = new Node\Stmt\Return_($last->expr, $last->getAttributes());
                    }
                }

                // Build use list
                $useList = array_map(
                    fn(string $name) => new Node\Expr\ClosureUse(
                        new Node\Expr\Variable($name),
                    ),
                    $useVars,
                );

                $closure = new Node\Expr\Closure([
                    'uses' => $useList,
                    'stmts' => $closureStmts,
                ]);

                $constFetch = new Node\Expr\ConstFetch(new Node\Name($constant));

                $elggCall = new Node\Expr\FuncCall(
                    new Node\Name('elgg_call'),
                    [
                        new Node\Arg($constFetch),
                        new Node\Arg($closure),
                    ],
                );

                // Preserve original line attributes
                $elggCall->setAttributes($trueCallStmt->getAttributes());

                return new Node\Stmt\Expression($elggCall, $trueCallStmt->getAttributes());
            }

            /**
             * Get the statement list from a node that owns one (function/method/closure/namespace/file).
             *
             * @return array<Node\Stmt>|null
             */
            private function getStmtList(Node $node): ?array
            {
                if ($node instanceof Node\Stmt\Function_
                    || $node instanceof Node\Stmt\ClassMethod
                    || $node instanceof Node\Expr\Closure
                ) {
                    return $node->stmts;
                }
                return null;
            }

            /**
             * Set the statement list on a node.
             *
             * @param array<Node\Stmt> $stmts
             */
            private function setStmtList(Node $node, array $stmts): void
            {
                if ($node instanceof Node\Stmt\Function_
                    || $node instanceof Node\Stmt\ClassMethod
                    || $node instanceof Node\Expr\Closure
                ) {
                    $node->stmts = $stmts;
                }
            }
        };

        $traverser = new NodeTraverser();
        $traverser->addVisitor($visitor);
        $newStmts = $traverser->traverse($stmts);

        return [$newStmts, $visitor->warnings, $visitor->changed];
    }

    // -------------------------------------------------------------------------
    // Shared utilities
    // -------------------------------------------------------------------------

    /**
     * Find setter pairs inside a flat statement list.
     *
     * @param array<Node\Stmt> $stmts
     * @param string $setter
     * @return array<array{trueLine: int, inBranch: bool}>
     */
    private function findPairs(array $stmts, string $setter): array
    {
        $pairs = [];

        for ($i = 0, $n = count($stmts); $i < $n; $i++) {
            if (!$this->stmtIsSetterCall($stmts[$i], $setter, true)) {
                continue;
            }

            $trueLine = $stmts[$i]->getLine();
            $falseFound = false;

            for ($j = $i + 1; $j < $n; $j++) {
                if ($this->stmtIsSetterCall($stmts[$j], $setter, false)) {
                    $falseFound = true;
                    $pairs[] = ['trueLine' => $trueLine, 'inBranch' => false];
                    $i = $j; // skip past the pair
                    break;
                }
                if ($this->stmtIsSetterCall($stmts[$j], $setter, true)) {
                    break; // nested true before false — unusual
                }
            }

            if (!$falseFound) {
                // Check if false-call is inside a branch
                $inBranch = $this->checkInBranch($stmts, $i, $setter);
                if ($inBranch) {
                    $pairs[] = ['trueLine' => $trueLine, 'inBranch' => true];
                }
            }
        }

        return $pairs;
    }

    private function stmtIsSetterCall(Node\Stmt $stmt, string $setter, bool $value): bool
    {
        if (!$stmt instanceof Node\Stmt\Expression) {
            return false;
        }
        $expr = $stmt->expr;
        if (!$expr instanceof Node\Expr\FuncCall) {
            return false;
        }
        if (!$expr->name instanceof Node\Name) {
            return false;
        }
        if ($expr->name->toString() !== $setter) {
            return false;
        }
        if (count($expr->args) !== 1) {
            return false;
        }
        $arg = $expr->args[0];
        if (!$arg instanceof Node\Arg) {
            return false;
        }
        $argVal = $arg->value;
        if ($value) {
            return $argVal instanceof Node\Expr\ConstFetch
                && strtolower($argVal->name->toString()) === 'true';
        }
        return $argVal instanceof Node\Expr\ConstFetch
            && strtolower($argVal->name->toString()) === 'false';
    }

    private function checkInBranch(array $stmts, int $trueIdx, string $setter): bool
    {
        $finder = new NodeFinder();
        for ($j = $trueIdx + 1, $n = count($stmts); $j < $n; $j++) {
            $found = $finder->find([$stmts[$j]], function (Node $node) use ($setter): bool {
                return $node instanceof Node\Expr\FuncCall
                    && $node->name instanceof Node\Name
                    && $node->name->toString() === $setter;
            });
            if (!empty($found)) {
                return true;
            }
        }
        return false;
    }

    /**
     * Collect all function/method/closure bodies from the AST.
     *
     * @param array<Node\Stmt> $ast
     * @return array<array<Node\Stmt>>
     */
    private function collectBodies(array $ast, NodeFinder $finder): array
    {
        $bodies = [];

        $nodes = $finder->find($ast, fn(Node $n) =>
            ($n instanceof Node\Stmt\Function_ || $n instanceof Node\Stmt\ClassMethod || $n instanceof Node\Expr\Closure)
            && $n->stmts !== null
        );

        foreach ($nodes as $node) {
            $bodies[] = $node->stmts;
        }

        return $bodies;
    }
}
