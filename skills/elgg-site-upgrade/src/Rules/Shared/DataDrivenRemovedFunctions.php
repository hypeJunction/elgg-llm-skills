<?php

declare(strict_types=1);

namespace ElggMigrate\Rules\Shared;

use ElggMigrate\AbstractRule;
use ElggMigrate\FileChange;
use ElggMigrate\Finding;
use ElggMigrate\RuleAnalysis;
use ElggMigrate\RuleResult;
use PhpParser\Node;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitorAbstract;

/**
 * Data-driven AST rewrite for global functions removed at a major version that
 * have an exact 1:1 global-function replacement. The map is loaded per-target
 * from references/removed-function-renames.json — the curated SAFE subset of
 * removed-functions.json — so adding a rename is a data edit, not new code, and
 * there is one source of truth for auto-rewritable renames across every step.
 *
 * Only unqualified global FuncCall names are rewritten (method calls, namespaced
 * calls, definitions and string occurrences are left untouched). Removals whose
 * replacement is prose / needs new arguments / changes semantics stay
 * LLM-guided in the manifest and are intentionally absent from the data file.
 *
 * Concrete per-step subclasses supply only their target major.
 */
abstract class DataDrivenRemovedFunctions extends AbstractRule
{
    /**
     * The Elgg major this rule targets, e.g. '6.x' or '7.x'. Must match a
     * top-level key in references/removed-function-renames.json.
     */
    abstract protected function targetMajor(): string;

    /**
     * @return array<string, string> old global function name → new name
     */
    protected function renameMap(): array
    {
        $path = __DIR__ . '/../../../references/removed-function-renames.json';
        if (!is_file($path)) {
            return [];
        }
        $data = json_decode((string) file_get_contents($path), true);
        $major = $this->targetMajor();
        if (!is_array($data) || !isset($data[$major]) || !is_array($data[$major])) {
            return [];
        }
        $map = [];
        foreach ($data[$major] as $old => $new) {
            $map[(string) $old] = (string) $new;
        }
        return $map;
    }

    public function getId(): string
    {
        return 'removed-functions-' . str_replace('.x', 'x', $this->targetMajor());
    }

    public function getDescription(): string
    {
        return sprintf(
            'Rename global functions removed in Elgg %s to their 1:1 equivalents (data-driven from removed-function-renames.json)',
            $this->targetMajor(),
        );
    }

    public function canAutomate(): bool
    {
        return true;
    }

    public function analyze(string $pluginPath): RuleAnalysis
    {
        $map = $this->renameMap();
        $findings = [];

        if (!empty($map)) {
            $targetNames = array_keys($map);
            foreach ($this->findPhpFiles($pluginPath) as $file) {
                $relativePath = $this->relativePath($pluginPath, $file);
                $code = file_get_contents($file);
                if ($code === false) {
                    continue;
                }
                $ast = $this->parse($code);
                if ($ast === null) {
                    continue;
                }
                $printer = $this->printer();
                foreach ($this->findFunctionCalls($ast, $targetNames) as $call) {
                    $funcName = $call->name->toString();
                    $findings[] = new Finding(
                        file: $relativePath,
                        line: $call->getLine(),
                        description: sprintf('%s() removed in %s — rename to %s()', $funcName, $this->targetMajor(), $map[$funcName]),
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
                ? sprintf('Found %d removed function call(s) to rename', count($findings))
                : 'No removed function calls found',
        );
    }

    public function apply(string $pluginPath): RuleResult
    {
        $map = $this->renameMap();
        $changes = [];
        $warnings = [];

        if (!empty($map)) {
            $targetNames = array_keys($map);
            foreach ($this->findPhpFiles($pluginPath) as $file) {
                $relativePath = $this->relativePath($pluginPath, $file);
                $code = file_get_contents($file);
                if ($code === false) {
                    continue;
                }
                $ast = $this->parse($code);
                if ($ast === null) {
                    continue;
                }
                if (empty($this->findFunctionCalls($ast, $targetNames))) {
                    continue;
                }

                $result = $this->transformFile($code, $map);
                if ($result['transformed']) {
                    file_put_contents($file, $result['code']);
                    $changes[] = new FileChange(
                        file: $relativePath,
                        type: 'modified',
                        description: sprintf('Renamed removed function calls to %s equivalents', $this->targetMajor()),
                    );
                }
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
     * @param array<string, string> $map
     * @return array{transformed: bool, code: string, warnings: array<string>}
     */
    private function transformFile(string $originalCode, array $map): array
    {
        $warnings = [];

        $parsed = $this->parsePreserving($originalCode);
        if ($parsed === null) {
            return ['transformed' => false, 'code' => $originalCode, 'warnings' => $warnings];
        }

        $traverser = new NodeTraverser();
        $visitor = new class($map, $warnings) extends NodeVisitorAbstract {
            private bool $changed = false;

            /** @param array<string, string> $map */
            public function __construct(private array $map, private array &$warnings) {}

            public function leaveNode(Node $node): int|Node|null
            {
                if (
                    $node instanceof Node\Expr\FuncCall
                    && $node->name instanceof Node\Name
                    && isset($this->map[$node->name->toString()])
                ) {
                    $oldName = $node->name->toString();
                    $newName = $this->map[$oldName];
                    $this->warnings[] = "{$oldName}() → {$newName}()";
                    $this->changed = true;
                    $node->name = new Node\Name($newName);
                    return $node;
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
