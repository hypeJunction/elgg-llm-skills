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
 * Lowercases string-literal plugin IDs in known plugin API callsites.
 *
 * Elgg 4.x requires plugin IDs to be lowercase (matches the installer-name in
 * composer.json extra section). Passing a camelCase ID like 'hypeDirectory' to
 * elgg_get_plugin_from_id() silently returns false instead of the default value.
 *
 * Targets:
 *   elgg_get_plugin_from_id($plugin_id)            → arg 0
 *   elgg_get_plugin_setting($name, $plugin_id)     → arg 1
 *   elgg_get_plugin_user_setting($n, $uid, $id)    → arg 2
 */
final class LowercasePluginIdCallsites extends AbstractRule
{
    /**
     * Maps function name → zero-based index of the plugin_id argument.
     */
    private const PLUGIN_ID_ARG = [
        'elgg_get_plugin_from_id'       => 0,
        'elgg_get_plugin_setting'       => 1,
        'elgg_get_plugin_user_setting'  => 2,
    ];

    public function getId(): string
    {
        return 'lowercase-plugin-id-callsites';
    }

    public function getDescription(): string
    {
        return 'Lowercase string-literal plugin IDs in elgg_get_plugin_from_id / elgg_get_plugin_setting / elgg_get_plugin_user_setting';
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

            foreach ($this->findTargetCalls($ast) as [$call, $argIndex]) {
                $finding = $this->checkCall($call, $argIndex, $relativePath);
                if ($finding !== null) {
                    $findings[] = $finding;
                }
            }
        }

        $applicable = count($findings) > 0;

        return new RuleAnalysis(
            ruleId: $this->getId(),
            applicable: $applicable,
            findings: $findings,
            summary: $applicable
                ? sprintf('Found %d plugin ID callsite(s) with uppercase characters', count($findings))
                : 'No uppercase plugin ID callsites found',
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

            $targets = $this->findTargetCalls($parsed['new']);
            $affected = array_filter(
                $targets,
                fn ($pair) => $this->checkCall($pair[0], $pair[1], $relativePath) !== null,
            );

            if (empty($affected)) continue;

            $traverser = new NodeTraverser();
            $visitor = new class(self::PLUGIN_ID_ARG) extends NodeVisitorAbstract {
                public bool $changed = false;

                /** @param array<string, int> $pluginIdArg */
                public function __construct(private readonly array $pluginIdArg) {}

                public function leaveNode(Node $node): ?Node
                {
                    if (!$node instanceof Node\Expr\FuncCall) return null;
                    if (!$node->name instanceof Node\Name) return null;

                    $funcName = $node->name->toString();
                    if (!array_key_exists($funcName, $this->pluginIdArg)) return null;

                    $argIndex = $this->pluginIdArg[$funcName];
                    if (!isset($node->args[$argIndex])) return null;

                    $arg = $node->args[$argIndex];
                    if (!$arg instanceof Node\Arg) return null;
                    if (!$arg->value instanceof Node\Scalar\String_) return null;

                    $original = $arg->value->value;
                    $lowered = strtolower($original);

                    if ($lowered === $original) return null;

                    $arg->value->value = $lowered;
                    $this->changed = true;

                    return $node;
                }
            };

            $traverser->addVisitor($visitor);
            $parsed['new'] = $traverser->traverse($parsed['new']);

            if ($visitor->changed) {
                file_put_contents($file, $this->printPreserving($parsed['new'], $parsed['old'], $parsed['tokens']));
                $changes[] = new FileChange(
                    file: $relativePath,
                    type: 'modified',
                    description: 'Lowercased plugin ID string argument(s)',
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
     * Returns all matching function calls as [FuncCall, argIndex] pairs.
     *
     * @param array<Node\Stmt> $ast
     * @return array<array{Node\Expr\FuncCall, int}>
     */
    private function findTargetCalls(array $ast): array
    {
        $targets = [];
        $calls = $this->findFunctionCalls($ast, array_keys(self::PLUGIN_ID_ARG));

        foreach ($calls as $call) {
            $funcName = $call->name->toString();
            $targets[] = [$call, self::PLUGIN_ID_ARG[$funcName]];
        }

        return $targets;
    }

    private function checkCall(Node\Expr\FuncCall $call, int $argIndex, string $file): ?Finding
    {
        if (!isset($call->args[$argIndex])) return null;

        $arg = $call->args[$argIndex];
        if (!$arg instanceof Node\Arg) return null;
        if (!$arg->value instanceof Node\Scalar\String_) return null;

        $value = $arg->value->value;
        if (strtolower($value) === $value) return null;

        $funcName = $call->name->toString();

        return new Finding(
            file: $file,
            line: $call->getLine(),
            description: sprintf(
                "%s() called with mixed-case plugin ID '%s' — must be '%s' in Elgg 4.x",
                $funcName,
                $value,
                strtolower($value),
            ),
            code: $this->printer()->prettyPrintExpr($call),
        );
    }
}
