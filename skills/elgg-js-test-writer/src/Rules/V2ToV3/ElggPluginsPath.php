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
 * Replaces elgg_get_plugins_path() with __DIR__-based paths.
 *
 * In 2.x: elgg_get_plugins_path() . 'myplugin/lib/helpers.php'
 * In 3.x: __DIR__ . '/lib/helpers.php' (or warn if pattern unclear)
 */
final class ElggPluginsPath extends AbstractRule
{
    public function getId(): string
    {
        return 'elgg-plugins-path';
    }

    public function getDescription(): string
    {
        return 'Replace elgg_get_plugins_path() with __DIR__-based paths';
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

            $calls = $this->findFunctionCalls($ast, ['elgg_get_plugins_path']);
            $printer = $this->printer();

            foreach ($calls as $call) {
                $findings[] = new Finding(
                    file: $relativePath,
                    line: $call->getLine(),
                    description: "elgg_get_plugins_path() deprecated — use __DIR__-based paths",
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
                ? sprintf('Found %d elgg_get_plugins_path() call(s)', count($findings))
                : 'No elgg_get_plugins_path() calls found',
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

            $ast = $this->parse($code);
            if ($ast === null) continue;

            if (empty($this->findFunctionCalls($ast, ['elgg_get_plugins_path']))) continue;

            $result = $this->transformFile($ast, $code);

            if ($result['transformed']) {
                file_put_contents($file, $result['code']);
                $changes[] = new FileChange(
                    file: $relativePath,
                    type: 'modified',
                    description: 'Replaced elgg_get_plugins_path() with __DIR__',
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

    /**
     * @param array<Node\Stmt> $ast
     * @return array{transformed: bool, code: string, warnings: array<string>}
     */
    private function transformFile(array $ast, string $originalCode): array
    {
        $warnings = [];

        $traverser = new NodeTraverser();
        $visitor = new class($warnings) extends NodeVisitorAbstract {
            private bool $changed = false;

            public function __construct(private array &$warnings) {}

            public function leaveNode(Node $node): ?Node
            {
                if (!$node instanceof Node\Expr\FuncCall) return null;
                if (!$node->name instanceof Node\Name) return null;
                if ($node->name->toString() !== 'elgg_get_plugins_path') return null;

                // Replace with __DIR__ . '/../'
                // elgg_get_plugins_path() returns /path/to/mod/ so plugin files
                // inside mod/myplugin/ should use __DIR__ to reference themselves
                $this->changed = true;
                $this->warnings[] = "Replaced elgg_get_plugins_path() with __DIR__ . '/../'. Verify the resulting path is correct for this file's location.";

                return new Node\Expr\BinaryOp\Concat(
                    new Node\Scalar\MagicConst\Dir(),
                    new Node\Scalar\String_('/../'),
                );
            }

            public function hasChanged(): bool
            {
                return $this->changed;
            }
        };

        $traverser->addVisitor($visitor);
        $newAst = $traverser->traverse($ast);

        if (!$visitor->hasChanged()) {
            return ['transformed' => false, 'code' => $originalCode, 'warnings' => $warnings];
        }

        return ['transformed' => true, 'code' => $this->print($newAst), 'warnings' => $warnings];
    }
}
