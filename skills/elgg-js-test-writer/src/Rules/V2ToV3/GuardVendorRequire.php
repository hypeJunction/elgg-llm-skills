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
 * Adds file_exists() guard to plugin-local vendor/autoload.php requires.
 *
 * Some plugins have:
 *   require_once __DIR__ . '/vendor/autoload.php';
 *
 * This crashes if composer install hasn't been run. This rule wraps
 * unguarded requires in a file_exists() check.
 *
 * Does NOT touch requires that navigate UP with dirname() — those are
 * handled by RemoveVendorAutoload.
 */
final class GuardVendorRequire extends AbstractRule
{
    public function getId(): string
    {
        return 'guard-vendor-require';
    }

    public function getDescription(): string
    {
        return 'Add file_exists() guard to plugin-local vendor/autoload.php requires';
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

            $this->findUnguardedRequires($ast, $relativePath, $findings);
        }

        $applicable = count($findings) > 0;

        return new RuleAnalysis(
            ruleId: $this->getId(),
            applicable: $applicable,
            findings: $findings,
            summary: $applicable
                ? sprintf('Found %d unguarded vendor require(s)', count($findings))
                : 'No unguarded vendor requires found',
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
                    description: 'Added file_exists() guard to vendor require',
                );
            }

            foreach ($result['warnings'] as $w) {
                $warnings[] = "{$relativePath}: {$w}";
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
     * @param array<Node\Stmt> $ast
     * @param array<Finding> $findings
     */
    private function findUnguardedRequires(array $ast, string $relativePath, array &$findings): void
    {
        $printer = $this->printer();

        // Collect vendor require statements that are guarded (inside if(file_exists(...)))
        $guardedLines = [];
        $ifNodes = $this->finder()->find($ast, fn(Node $n) => $n instanceof Node\Stmt\If_);
        foreach ($ifNodes as $ifNode) {
            /** @var Node\Stmt\If_ $ifNode */
            $condCode = $printer->prettyPrintExpr($ifNode->cond);
            if (!str_contains($condCode, 'file_exists')) {
                continue;
            }
            // Check if this if block contains a vendor require
            foreach ($ifNode->stmts as $stmt) {
                if ($this->isVendorRequireStatement($stmt)) {
                    $guardedLines[$stmt->getLine()] = true;
                }
            }
        }

        // Find all vendor require statements
        $this->finder()->find($ast, function (Node $node) use ($relativePath, &$findings, $printer, $guardedLines) {
            if (!$this->isVendorRequireStatement($node)) {
                return false;
            }

            // Skip if already guarded
            if (isset($guardedLines[$node->getLine()])) {
                return false;
            }

            /** @var Node\Stmt\Expression $node */
            $findings[] = new Finding(
                file: $relativePath,
                line: $node->getLine(),
                description: 'Unguarded vendor/autoload.php require',
                code: $printer->prettyPrint([$node]),
            );

            return false;
        });
    }

    private function isVendorRequireStatement(Node $node): bool
    {
        if (!$node instanceof Node\Stmt\Expression) return false;
        if (!$node->expr instanceof Node\Expr\Include_) return false;

        $include = $node->expr;
        if ($include->type !== Node\Expr\Include_::TYPE_REQUIRE_ONCE
            && $include->type !== Node\Expr\Include_::TYPE_REQUIRE
        ) {
            return false;
        }

        $printer = $this->printer();
        $exprCode = $printer->prettyPrintExpr($include->expr);

        // Must reference vendor/autoload.php or vendors/autoload.php
        if (!str_contains($exprCode, 'vendor/autoload.php') && !str_contains($exprCode, 'vendors/autoload.php')) {
            return false;
        }

        // Must use __DIR__ (plugin-local), NOT dirname() (navigating up)
        if (str_contains($exprCode, 'dirname')) {
            return false;
        }

        if (!str_contains($exprCode, '__DIR__')) {
            return false;
        }

        return true;
    }

    /**
     * @return array{transformed: bool, code: string, warnings: array<string>}
     */
    private function transformFile(string $originalCode): array
    {
        $warnings = [];
        $rule = $this;

        $parsed = $this->parsePreserving($originalCode);
        if ($parsed === null) {
            return ['transformed' => false, 'code' => $originalCode, 'warnings' => $warnings];
        }

        // First pass: add parent attributes (attribute-only, no AST mutation)
        $parentTraverser = new NodeTraverser();
        $parentVisitor = new class extends NodeVisitorAbstract {
            /** @var array<Node> */
            private array $stack = [];

            public function enterNode(Node $node): ?Node
            {
                if (!empty($this->stack)) {
                    $node->setAttribute('parent', end($this->stack));
                }
                $this->stack[] = $node;
                return null;
            }

            public function leaveNode(Node $node): ?Node
            {
                array_pop($this->stack);
                return null;
            }
        };
        $parentTraverser->addVisitor($parentVisitor);
        $parentTraverser->traverse($parsed['new']);

        $traverser = new NodeTraverser();
        $visitor = new class($rule, $warnings) extends NodeVisitorAbstract {
            private bool $changed = false;

            public function __construct(
                private readonly GuardVendorRequire $rule,
                private array &$warnings,
            ) {}

            public function leaveNode(Node $node): int|Node|null
            {
                if (!$this->rule->isVendorRequireStatementPublic($node)) {
                    return null;
                }

                // Check if already inside an if(file_exists(...))
                $parent = $node->getAttribute('parent');
                if ($parent instanceof Node\Stmt\If_) {
                    $printer = new \PhpParser\PrettyPrinter\Standard();
                    $condCode = $printer->prettyPrintExpr($parent->cond);
                    if (str_contains($condCode, 'file_exists')) {
                        return null;
                    }
                }

                /** @var Node\Stmt\Expression $node */
                $include = $node->expr;

                // Wrap in if (file_exists(...)) { ... }
                $ifNode = new Node\Stmt\If_(
                    new Node\Expr\FuncCall(
                        new Node\Name('file_exists'),
                        [new Node\Arg($include->expr)],
                    ),
                    [
                        'stmts' => [$node],
                    ],
                );

                $this->changed = true;
                return $ifNode;
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
     * Public wrapper so anonymous visitor class can access the check.
     */
    public function isVendorRequireStatementPublic(Node $node): bool
    {
        return $this->isVendorRequireStatement($node);
    }
}
