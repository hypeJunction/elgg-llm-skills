<?php

declare(strict_types=1);

namespace ElggMigrate\Rules\V3ToV4;

use ElggMigrate\AbstractRule;
use ElggMigrate\Finding;
use ElggMigrate\RuleAnalysis;
use ElggMigrate\RuleResult;
use PhpParser\Node;

/**
 * Identifies action files that are candidates for Controller class extraction.
 *
 * This is an analysis-only rule (canAutomate() = false). It scans the plugin's
 * registered actions (from elgg-plugin.php 'actions' array or start.php
 * elgg_register_action() calls) and measures each action file's complexity:
 *
 * - Line count (LOC)
 * - Number of if/elseif/else branches
 * - Number of loops (foreach/while/for)
 * - Number of function calls (complexity proxy)
 *
 * Candidate threshold: LOC > 30 OR branches > 3 OR loops > 1
 * Trivial exemption: < 15 LOC with a single meaningful statement
 *
 * For each candidate the rule emits a Finding suggesting the target Controller
 * class path: classes/<PluginNamespace>/Actions/<ActionName>.php
 */
final class ActionControllerAnalyzer extends AbstractRule
{
    /** Actions with fewer lines than this are always exempted. */
    private const TRIVIAL_LOC = 15;

    /** Actions with more lines than this are always candidates. */
    private const LOC_THRESHOLD = 30;

    /** Branch count (if/elseif/else) that triggers candidate status. */
    private const BRANCH_THRESHOLD = 3;

    /** Loop count (foreach/while/for) that triggers candidate status. */
    private const LOOP_THRESHOLD = 1;

    public function getId(): string
    {
        return 'action-controller-analyzer';
    }

    public function getDescription(): string
    {
        return 'Identify action files that are candidates for Controller class extraction';
    }

    public function canAutomate(): bool
    {
        return false;
    }

    public function analyze(string $pluginPath): RuleAnalysis
    {
        $actions = $this->discoverActions($pluginPath);

        if (empty($actions)) {
            return new RuleAnalysis(
                ruleId: $this->getId(),
                applicable: false,
                findings: [],
                summary: 'No registered actions found',
            );
        }

        $pluginNamespace = $this->guessPluginNamespace($pluginPath);
        $findings = [];

        foreach ($actions as $actionName => $actionFile) {
            $fullPath = $pluginPath . '/' . $actionFile;
            if (!is_file($fullPath)) {
                continue;
            }

            $metrics = $this->measureComplexity($fullPath);

            // Skip trivial actions
            if ($metrics['loc'] < self::TRIVIAL_LOC) {
                continue;
            }

            // Check candidate thresholds
            $isCandidate = $metrics['loc'] > self::LOC_THRESHOLD
                || $metrics['branches'] > self::BRANCH_THRESHOLD
                || $metrics['loops'] > self::LOOP_THRESHOLD;

            if (!$isCandidate) {
                continue;
            }

            $targetClass = $this->suggestControllerClass($actionName, $pluginNamespace);

            $findings[] = new Finding(
                file: $actionFile,
                line: 1,
                description: sprintf(
                    'Action candidate for Controller extraction: %d lines, %d branches, %d loops. Suggested class: %s',
                    $metrics['loc'],
                    $metrics['branches'],
                    $metrics['loops'],
                    $targetClass,
                ),
                code: '',
            );
        }

        $applicable = count($findings) > 0;

        return new RuleAnalysis(
            ruleId: $this->getId(),
            applicable: $applicable,
            findings: $findings,
            summary: $applicable
                ? sprintf('Found %d action file(s) that are candidates for Controller extraction', count($findings))
                : 'No action files meet the Controller extraction threshold',
        );
    }

    /**
     * No-op: this rule is LLM-guided — the actual refactor is done by an agent.
     */
    public function apply(string $pluginPath): RuleResult
    {
        return new RuleResult(
            ruleId: $this->getId(),
            success: true,
            changes: [],
            warnings: ['action-controller-analyzer is analysis-only; run the LLM-guided refactor step to convert action files to Controller classes'],
        );
    }

    // -------------------------------------------------------------------------
    // Action discovery
    // -------------------------------------------------------------------------

    /**
     * Returns an array of [actionName => relative-file-path] pairs.
     *
     * Sources (in order of preference):
     * 1. elgg-plugin.php 'actions' array
     * 2. start.php elgg_register_action() calls
     */
    private function discoverActions(string $pluginPath): array
    {
        $actions = $this->readActionsFromElggPluginPhp($pluginPath);

        if (empty($actions)) {
            $actions = $this->readActionsFromStartPhp($pluginPath);
        }

        return $actions;
    }

    /**
     * Parse the 'actions' key from elgg-plugin.php using the PHP AST.
     *
     * elgg-plugin.php returns an array literal, so we eval the return value
     * safely by requiring the file in an isolated scope. However, since the
     * file may have side-effects we use AST inspection instead — we walk the
     * return statement and extract 'actions' array keys.
     *
     * @return array<string, string> actionName → relative path (e.g. actions/foo/bar.php)
     */
    private function readActionsFromElggPluginPhp(string $pluginPath): array
    {
        $filePath = $pluginPath . '/elgg-plugin.php';
        if (!is_file($filePath)) {
            return [];
        }

        $code = file_get_contents($filePath);
        $ast = $this->parse($code);
        if ($ast === null) {
            return [];
        }

        $finder = $this->finder();

        // Find the top-level return statement
        $returns = $finder->find($ast, fn(Node $n) => $n instanceof Node\Stmt\Return_);
        if (empty($returns)) {
            return [];
        }

        /** @var Node\Stmt\Return_ $return */
        $return = $returns[0];
        if (!$return->expr instanceof Node\Expr\Array_) {
            return [];
        }

        // Find the 'actions' key inside the returned array
        $actionsNode = $this->findArrayKey($return->expr, 'actions');
        if ($actionsNode === null || !$actionsNode instanceof Node\Expr\Array_) {
            return [];
        }

        $actions = [];
        foreach ($actionsNode->items as $item) {
            if ($item === null) {
                continue;
            }
            if (!$item->key instanceof Node\Scalar\String_) {
                continue;
            }

            $actionName = $item->key->value;
            // Standard Elgg convention: action name → actions/<name>.php
            $actions[$actionName] = 'actions/' . $actionName . '.php';
        }

        return $actions;
    }

    /**
     * Parse elgg_register_action() calls from start.php.
     *
     * @return array<string, string> actionName → relative path
     */
    private function readActionsFromStartPhp(string $pluginPath): array
    {
        $filePath = $pluginPath . '/start.php';
        if (!is_file($filePath)) {
            return [];
        }

        $code = file_get_contents($filePath);
        $ast = $this->parse($code);
        if ($ast === null) {
            return [];
        }

        $calls = $this->findFunctionCalls($ast, ['elgg_register_action']);
        $actions = [];

        foreach ($calls as $call) {
            /** @var Node\Expr\FuncCall $call */
            if (!isset($call->args[0]) || !$call->args[0]->value instanceof Node\Scalar\String_) {
                continue;
            }

            $actionName = $call->args[0]->value->value;

            // Derive the relative path from the 2nd argument if present
            if (isset($call->args[1]) && $call->args[1]->value instanceof Node\Scalar\String_) {
                // Absolute path — make it relative to plugin root
                $absPath = $call->args[1]->value->value;
                $relPath = $this->makeRelativePath($pluginPath, $absPath);
            } elseif (isset($call->args[1]) && $call->args[1]->value instanceof Node\Expr\Concat) {
                // __DIR__ . '/actions/...' concat — try to extract the suffix
                $relPath = $this->extractConcatSuffix($call->args[1]->value, $pluginPath);
                if ($relPath === null) {
                    $relPath = 'actions/' . $actionName . '.php';
                }
            } else {
                // No explicit file path — use standard convention
                $relPath = 'actions/' . $actionName . '.php';
            }

            $actions[$actionName] = $relPath;
        }

        return $actions;
    }

    // -------------------------------------------------------------------------
    // Complexity measurement
    // -------------------------------------------------------------------------

    /**
     * Parse a PHP file and count LOC, branches, and loops.
     *
     * @return array{loc: int, branches: int, loops: int, calls: int}
     */
    private function measureComplexity(string $filePath): array
    {
        $code = file_get_contents($filePath);
        $loc = $this->countMeaningfulLines($code);

        $ast = $this->parse($code);
        if ($ast === null) {
            return ['loc' => $loc, 'branches' => 0, 'loops' => 0, 'calls' => 0];
        }

        $finder = $this->finder();

        // Count branches: if, elseif (represented as ElseIf_ nodes), and else
        $branches = count($finder->find($ast, fn(Node $n) =>
            $n instanceof Node\Stmt\If_
            || $n instanceof Node\Stmt\ElseIf_
            || $n instanceof Node\Stmt\Else_
        ));

        // Count loops
        $loops = count($finder->find($ast, fn(Node $n) =>
            $n instanceof Node\Stmt\Foreach_
            || $n instanceof Node\Stmt\While_
            || $n instanceof Node\Stmt\For_
            || $n instanceof Node\Stmt\Do_
        ));

        // Count function calls (as a complexity proxy)
        $calls = count($finder->find($ast, fn(Node $n) =>
            $n instanceof Node\Expr\FuncCall
            || $n instanceof Node\Expr\MethodCall
            || $n instanceof Node\Expr\StaticCall
        ));

        return [
            'loc' => $loc,
            'branches' => $branches,
            'loops' => $loops,
            'calls' => $calls,
        ];
    }

    /**
     * Count non-blank, non-comment lines (meaningful LOC).
     */
    private function countMeaningfulLines(string $code): int
    {
        $lines = explode("\n", $code);
        $count = 0;
        $inBlockComment = false;

        foreach ($lines as $line) {
            $trimmed = trim($line);

            if ($inBlockComment) {
                if (str_contains($trimmed, '*/')) {
                    $inBlockComment = false;
                }
                continue;
            }

            if ($trimmed === '' || $trimmed === '<?php' || $trimmed === '?>') {
                continue;
            }

            if (str_starts_with($trimmed, '//') || str_starts_with($trimmed, '#')) {
                continue;
            }

            if (str_starts_with($trimmed, '/*') || str_starts_with($trimmed, '*')) {
                if (str_starts_with($trimmed, '/*') && !str_contains($trimmed, '*/')) {
                    $inBlockComment = true;
                }
                continue;
            }

            $count++;
        }

        return $count;
    }

    // -------------------------------------------------------------------------
    // Controller class path suggestion
    // -------------------------------------------------------------------------

    /**
     * Suggest a Controller class name from an action name.
     *
     * Example: 'myplugin/save' → 'classes/MyPlugin/Actions/Save.php'
     */
    private function suggestControllerClass(string $actionName, string $pluginNamespace): string
    {
        $parts = explode('/', $actionName);

        // The last segment is the action verb (e.g. 'save', 'delete')
        $verb = array_pop($parts);
        $className = ucfirst($verb);

        if ($pluginNamespace !== '') {
            return 'classes/' . str_replace('\\', '/', $pluginNamespace) . '/Actions/' . $className . '.php';
        }

        return 'classes/Actions/' . $className . '.php';
    }

    /**
     * Attempt to derive the plugin's root PHP namespace from composer.json
     * autoload PSR-4 config, or fall back to scanning class files.
     */
    private function guessPluginNamespace(string $pluginPath): string
    {
        $composerPath = $pluginPath . '/composer.json';
        if (is_file($composerPath)) {
            $composer = json_decode(file_get_contents($composerPath), true);
            $psr4 = $composer['autoload']['psr-4'] ?? [];
            foreach ($psr4 as $namespace => $src) {
                // Return the first namespace prefix (strip trailing backslash)
                return rtrim($namespace, '\\');
            }
        }

        return '';
    }

    // -------------------------------------------------------------------------
    // AST helpers
    // -------------------------------------------------------------------------

    /**
     * Find a string-keyed item value inside an Array_ node.
     */
    private function findArrayKey(Node\Expr\Array_ $array, string $key): ?Node\Expr
    {
        foreach ($array->items as $item) {
            if ($item === null) {
                continue;
            }
            if ($item->key instanceof Node\Scalar\String_ && $item->key->value === $key) {
                return $item->value;
            }
        }

        return null;
    }

    /**
     * Make a plugin-root-relative path from an absolute path string literal.
     */
    private function makeRelativePath(string $pluginPath, string $absPath): string
    {
        $pluginPath = rtrim($pluginPath, '/') . '/';
        if (str_starts_with($absPath, $pluginPath)) {
            return substr($absPath, strlen($pluginPath));
        }
        // Can't relativize — fall back to basename
        return ltrim($absPath, '/');
    }

    /**
     * Try to extract the string suffix from a __DIR__ . '/...' concatenation node.
     */
    private function extractConcatSuffix(Node\Expr\Concat $concat, string $pluginPath): ?string
    {
        // Pattern: __DIR__ . '/actions/foo/bar.php'
        if ($concat->left instanceof Node\Scalar\MagicConst\Dir
            && $concat->right instanceof Node\Scalar\String_
        ) {
            $suffix = ltrim($concat->right->value, '/');
            return $suffix;
        }

        // Pattern: __DIR__ . '/subdir' . '/...' — not handled, give up
        return null;
    }
}
