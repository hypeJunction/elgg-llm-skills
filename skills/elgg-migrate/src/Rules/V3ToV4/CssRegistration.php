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
 * Rewrites CSS/JS registration functions removed in Elgg 4.x.
 *
 * Replacements (auto-fixed):
 *   elgg_register_css($name, $url, $priority)
 *     → elgg_register_external_file('css', $name, $url, $priority)
 *   elgg_load_css($name)
 *     → elgg_load_external_file('css', $name)
 *   elgg_register_js($name, $url, $priority, $location)
 *     → elgg_register_external_file('js', $name, $url, $priority, $location)
 *   elgg_load_js($name)
 *     → elgg_load_external_file('js', $name)
 *
 * Scope-only (warn, do not rewrite) — semantics + default-argument shift make
 * a blind rewrite unsafe:
 *   elgg_get_loaded_css()  → elgg_get_loaded_external_files('css', 'head')
 *   elgg_get_loaded_js()   → elgg_get_loaded_external_files('js', $location)
 */
final class CssRegistration extends AbstractRule
{
    /**
     * Auto-fixable: removed function → [replacement function, type literal to prepend].
     */
    private const REWRITES = [
        'elgg_register_css' => ['elgg_register_external_file', 'css'],
        'elgg_load_css'     => ['elgg_load_external_file', 'css'],
        'elgg_register_js'  => ['elgg_register_external_file', 'js'],
        'elgg_load_js'      => ['elgg_load_external_file', 'js'],
    ];

    /**
     * Warn-only: removed function → human-readable replacement note.
     */
    private const WARN_ONLY = [
        'elgg_get_loaded_css' => "elgg_get_loaded_external_files('css', 'head')",
        'elgg_get_loaded_js'  => "elgg_get_loaded_external_files('js', \$location)",
    ];

    public function getId(): string
    {
        return 'css-js-registration-4x';
    }

    public function getDescription(): string
    {
        return 'Rewrite CSS/JS registration functions removed in Elgg 4.x';
    }

    public function canAutomate(): bool
    {
        return true;
    }

    public function analyze(string $pluginPath): RuleAnalysis
    {
        $findings = [];
        $targets = [...array_keys(self::REWRITES), ...array_keys(self::WARN_ONLY)];

        foreach ($this->findPhpFiles($pluginPath) as $file) {
            $rel = $this->relativePath($pluginPath, $file);
            $code = file_get_contents($file);
            if ($code === false) continue;

            $ast = $this->parse($code);
            if ($ast === null) continue;

            $printer = $this->printer();
            foreach ($this->findFunctionCalls($ast, $targets) as $call) {
                $name = $call->name->toString();
                $description = isset(self::REWRITES[$name])
                    ? sprintf("%s() removed in 4.0: rewrite to %s('%s', …)", $name, self::REWRITES[$name][0], self::REWRITES[$name][1])
                    : sprintf("%s() removed in 4.0: replace with %s (manual — semantics differ)", $name, self::WARN_ONLY[$name]);

                $findings[] = new Finding(
                    file: $rel,
                    line: $call->getLine(),
                    description: $description,
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
                ? sprintf('Found %d CSS/JS registration call(s) to update', count($findings))
                : 'No removed CSS/JS registration calls found',
        );
    }

    public function apply(string $pluginPath): RuleResult
    {
        $changes = [];
        $warnings = [];

        foreach ($this->findPhpFiles($pluginPath) as $file) {
            $rel = $this->relativePath($pluginPath, $file);
            $code = file_get_contents($file);
            if ($code === false) continue;

            $parsed = $this->parsePreserving($code);
            if ($parsed === null) continue;

            $targets = array_keys(self::REWRITES);
            if (empty($this->findFunctionCalls($parsed['new'], $targets))) {
                // Still surface warn-only findings for elgg_get_loaded_* even if no rewrites apply.
                $warnings = array_merge($warnings, $this->collectWarnOnly($parsed['old'], $rel));
                continue;
            }

            $traverser = new NodeTraverser();
            $visitor = new class(self::REWRITES) extends NodeVisitorAbstract {
                public bool $changed = false;
                /** @var array<string,array{0:string,1:string}> */
                private array $rewrites;

                /** @param array<string,array{0:string,1:string}> $rewrites */
                public function __construct(array $rewrites)
                {
                    $this->rewrites = $rewrites;
                }

                public function leaveNode(Node $node): ?Node
                {
                    if (!$node instanceof Node\Expr\FuncCall) return null;
                    if (!$node->name instanceof Node\Name) return null;

                    $name = $node->name->toString();
                    if (!isset($this->rewrites[$name])) return null;

                    [$newName, $type] = $this->rewrites[$name];

                    // Prepend type literal as first positional arg.
                    $typeArg = new Node\Arg(new Node\Scalar\String_($type));
                    $node->args = [$typeArg, ...$node->args];
                    $node->name = new Node\Name($newName);

                    $this->changed = true;
                    return $node;
                }
            };

            $traverser->addVisitor($visitor);
            $parsed['new'] = $traverser->traverse($parsed['new']);

            if ($visitor->changed) {
                file_put_contents($file, $this->printPreserving($parsed['new'], $parsed['old'], $parsed['tokens']));
                $changes[] = new FileChange(
                    file: $rel,
                    type: 'modified',
                    description: 'Rewrote CSS/JS registration calls to elgg_register_external_file()/elgg_load_external_file()',
                );
            }

            // Warn-only calls live in the same files often — collect from the original AST.
            $warnings = array_merge($warnings, $this->collectWarnOnly($parsed['old'], $rel));
        }

        return new RuleResult(
            ruleId: $this->getId(),
            success: true,
            changes: $changes,
            warnings: $warnings,
        );
    }

    /**
     * Surface warnings for the warn-only calls in a given file.
     *
     * @param array<Node\Stmt> $ast
     * @return array<string>
     */
    private function collectWarnOnly(array $ast, string $rel): array
    {
        $warnings = [];
        $names = array_keys(self::WARN_ONLY);
        foreach ($this->findFunctionCalls($ast, $names) as $call) {
            $name = $call->name->toString();
            $warnings[] = sprintf(
                '%s:%d — %s() removed in 4.0: replace with %s (manual — semantics differ)',
                $rel,
                $call->getLine(),
                $name,
                self::WARN_ONLY[$name],
            );
        }
        return $warnings;
    }
}
