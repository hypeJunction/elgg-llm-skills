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
 * Strip debug residue left in plugins after migration.
 *
 * Automated actions:
 * - elgg_dump($x) as a standalone statement → remove the statement
 * - Commented-out var_dump / print_r / error_log lines → remove via regex
 * - Logger::INFO|ERROR|WARNING|NOTICE|DEBUG class-constant refs → replace with
 *   the equivalent PSR-3 string literal ('info', 'error', …)
 *   (The psr3-logging rule handles the method-call rename; this handles the constant.)
 *
 * Warn-only (no auto-fix):
 * - echo / print that outputs $_REQUEST or $_SESSION — security-sensitive residue
 * - elgg_dump() used in a non-statement context (e.g. as a value) — remove manually
 */
final class DebugSurfaceCleanup extends AbstractRule
{
    public const ID = 'debug-surface-cleanup';

    /**
     * Maps Logger:: class constant names (upper-case) → PSR-3 string level.
     */
    public const LOGGER_CONSTANT_MAP = [
        'INFO'    => 'info',
        'ERROR'   => 'error',
        'WARNING' => 'warning',
        'NOTICE'  => 'notice',
        'DEBUG'   => 'debug',
    ];

    /**
     * Regex that matches entire PHP comment lines containing debug calls.
     * Handles // and # single-line comments. Multiline /* ... *‌/ blocks are
     * handled by a separate pass so we don't strip non-debug block comments.
     */
    private const COMMENTED_DEBUG_PATTERN = '/^\h*(\/\/|#)[^\r\n]*(var_dump|print_r|error_log)\s*\([^\r\n]*$/m';

    public function getId(): string
    {
        return self::ID;
    }

    public function getDescription(): string
    {
        return 'Strip debug residue: remove elgg_dump(), commented var_dump/print_r/error_log, replace Logger:: constants with PSR-3 strings; warn on echo/print of superglobals';
    }

    public function canAutomate(): bool
    {
        return true;
    }

    // -------------------------------------------------------------------------
    // analyze()
    // -------------------------------------------------------------------------

    public function analyze(string $pluginPath): RuleAnalysis
    {
        $findings = [];

        foreach ($this->findPhpFiles($pluginPath) as $file) {
            if (str_contains($file, '/vendor/')) {
                continue;
            }

            $rel  = $this->relativePath($pluginPath, $file);
            $code = file_get_contents($file);
            if ($code === false) {
                continue;
            }

            // 1. AST: elgg_dump() calls
            $ast = $this->parse($code);
            if ($ast !== null) {
                $printer = $this->printer();

                $elggDumpCalls = $this->findFunctionCalls($ast, ['elgg_dump']);
                foreach ($elggDumpCalls as $call) {
                    $findings[] = new Finding(
                        file: $rel,
                        line: $call->getLine(),
                        description: 'elgg_dump() — debug residue, will be removed (standalone) or flagged (expression)',
                        code: $printer->prettyPrintExpr($call),
                    );
                }

                // 2. AST: Logger:: class constant references
                $loggerConstRefs = $this->findLoggerConstantRefs($ast);
                foreach ($loggerConstRefs as $node) {
                    /** @var Node\Expr\ClassConstFetch $node */
                    $constName  = $node->name instanceof Node\Identifier ? $node->name->toString() : '';
                    $psrLevel   = self::LOGGER_CONSTANT_MAP[strtoupper($constName)] ?? null;
                    if ($psrLevel === null) {
                        continue;
                    }
                    $findings[] = new Finding(
                        file: $rel,
                        line: $node->getLine(),
                        description: "Logger::{$constName} → '{$psrLevel}' (PSR-3 string level)",
                        code: $printer->prettyPrint([$node]),
                    );
                }

                // 3. AST: echo/print of $_REQUEST/$_SESSION
                $dangerousEchoes = $this->findDangerousEchoes($ast);
                foreach ($dangerousEchoes as $node) {
                    $findings[] = new Finding(
                        file: $rel,
                        line: $node->getLine(),
                        description: 'echo/print of $_REQUEST or $_SESSION — security-sensitive debug residue; review manually',
                        code: $printer->prettyPrint([$node]),
                    );
                }
            }

            // 4. Raw source: commented-out debug calls
            $commentedLines = $this->findCommentedDebugLines($code);
            foreach ($commentedLines as $lineNo => $lineText) {
                $findings[] = new Finding(
                    file: $rel,
                    line: $lineNo,
                    description: 'Commented-out debug call (var_dump/print_r/error_log) — will be stripped',
                    code: trim($lineText),
                );
            }
        }

        $applicable = count($findings) > 0;

        return new RuleAnalysis(
            ruleId: $this->getId(),
            applicable: $applicable,
            findings: $findings,
            summary: $applicable
                ? sprintf('Found %d debug residue occurrence(s)', count($findings))
                : 'No debug residue found',
        );
    }

    // -------------------------------------------------------------------------
    // apply()
    // -------------------------------------------------------------------------

    public function apply(string $pluginPath): RuleResult
    {
        $changes  = [];
        $warnings = [];

        foreach ($this->findPhpFiles($pluginPath) as $file) {
            if (str_contains($file, '/vendor/')) {
                continue;
            }

            $rel  = $this->relativePath($pluginPath, $file);
            $code = file_get_contents($file);
            if ($code === false) {
                continue;
            }

            $result = $this->transformFile($code, $rel);

            if ($result['transformed']) {
                file_put_contents($file, $result['code']);
                $changes[] = new FileChange(
                    file: $rel,
                    type: 'modified',
                    description: 'Removed debug residue (elgg_dump, commented debug calls, Logger:: constants)',
                );
            }

            foreach ($result['warnings'] as $w) {
                $warnings[] = "{$rel}: {$w}";
            }
        }

        if (empty($changes) && empty($warnings)) {
            return new RuleResult(
                ruleId: $this->getId(),
                success: true,
                changes: [],
                warnings: ['No debug residue found'],
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
    // Internal helpers
    // -------------------------------------------------------------------------

    /**
     * @return array{transformed: bool, code: string, warnings: array<string>}
     */
    private function transformFile(string $originalCode, string $relPath): array
    {
        $warnings = [];
        $code     = $originalCode;

        // Pass 1: strip commented-out debug lines (raw string manipulation — before AST parse
        // because comments are not in the AST).
        $strippedCode = preg_replace(self::COMMENTED_DEBUG_PATTERN, '', $code);
        if ($strippedCode !== null && $strippedCode !== $code) {
            // Clean up blank lines that result from stripping (collapse 3+ consecutive newlines to 2)
            $strippedCode = preg_replace('/(\r?\n){3,}/', "\n\n", $strippedCode);
            $code = $strippedCode ?? $code;
        }

        // Pass 2: AST transforms (elgg_dump removal, Logger:: constant replacement).
        $parsed = $this->parsePreserving($code);
        if ($parsed === null) {
            // If the file is not parseable, still return any comment-stripped version.
            $transformed = $code !== $originalCode;
            return ['transformed' => $transformed, 'code' => $code, 'warnings' => $warnings];
        }

        $traverser = new NodeTraverser();
        $visitor   = new class($warnings) extends NodeVisitorAbstract {
            private bool $changed = false;

            public function __construct(private array &$warnings) {}

            public function leaveNode(Node $node): int|Node|null
            {
                // --- elgg_dump() as a standalone statement → remove ---
                if ($node instanceof Node\Stmt\Expression
                    && $node->expr instanceof Node\Expr\FuncCall
                    && $node->expr->name instanceof Node\Name
                    && $node->expr->name->toString() === 'elgg_dump'
                ) {
                    $this->changed = true;
                    return NodeTraverser::REMOVE_NODE;
                }

                // --- elgg_dump() in expression context → warn (cannot safely remove) ---
                if ($node instanceof Node\Expr\FuncCall
                    && $node->name instanceof Node\Name
                    && $node->name->toString() === 'elgg_dump'
                ) {
                    $this->warnings[] = "elgg_dump() at line {$node->getLine()} used in expression context — remove manually";
                    return null;
                }

                // --- Logger::LEVEL → 'level' string literal ---
                if ($node instanceof Node\Expr\ClassConstFetch
                    && $node->class instanceof Node\Name
                    && $node->class->toString() === 'Logger'
                    && $node->name instanceof Node\Identifier
                ) {
                    $constName = strtoupper($node->name->toString());
                    $psrLevel  = DebugSurfaceCleanup::LOGGER_CONSTANT_MAP[$constName] ?? null;
                    if ($psrLevel !== null) {
                        $this->changed = true;
                        return new Node\Scalar\String_($psrLevel);
                    }
                }

                // --- echo/print of $_REQUEST/$_SESSION → warn only ---
                if ($node instanceof Node\Stmt\Echo_) {
                    foreach ($node->exprs as $expr) {
                        if ($this->isSuperGlobalAccess($expr)) {
                            $this->warnings[] = "echo of \$_REQUEST/\$_SESSION at line {$node->getLine()} — security-sensitive debug residue; remove manually";
                            return null;
                        }
                    }
                }

                if ($node instanceof Node\Expr\Print_) {
                    if ($this->isSuperGlobalAccess($node->expr)) {
                        $this->warnings[] = "print of \$_REQUEST/\$_SESSION at line {$node->getLine()} — security-sensitive debug residue; remove manually";
                    }
                    return null;
                }

                return null;
            }

            private function isSuperGlobalAccess(Node\Expr $expr): bool
            {
                // Direct: echo $_REQUEST or echo $_SESSION
                if ($expr instanceof Node\Expr\Variable
                    && is_string($expr->name)
                    && in_array($expr->name, ['_REQUEST', '_SESSION'], true)
                ) {
                    return true;
                }
                // Array access: echo $_REQUEST['foo']
                if ($expr instanceof Node\Expr\ArrayDimFetch
                    && $expr->var instanceof Node\Expr\Variable
                    && is_string($expr->var->name)
                    && in_array($expr->var->name, ['_REQUEST', '_SESSION'], true)
                ) {
                    return true;
                }
                return false;
            }

            public function hasChanged(): bool
            {
                return $this->changed;
            }
        };

        $traverser->addVisitor($visitor);
        $parsed['new'] = $traverser->traverse($parsed['new']);

        $astChanged  = $visitor->hasChanged();
        $codeChanged = $code !== $originalCode; // from the comment-stripping pass

        if (!$astChanged && !$codeChanged) {
            return ['transformed' => false, 'code' => $originalCode, 'warnings' => $warnings];
        }

        $finalCode = $astChanged
            ? $this->printPreserving($parsed['new'], $parsed['old'], $parsed['tokens'])
            : $code;

        return [
            'transformed' => true,
            'code'        => $finalCode,
            'warnings'    => $warnings,
        ];
    }

    /**
     * Find all Logger:: class-constant fetches that match our level map.
     *
     * @param array<Node\Stmt> $ast
     * @return array<Node\Expr\ClassConstFetch>
     */
    private function findLoggerConstantRefs(array $ast): array
    {
        return $this->finder()->find($ast, function (Node $node): bool {
            if (!($node instanceof Node\Expr\ClassConstFetch)) {
                return false;
            }
            if (!($node->class instanceof Node\Name)) {
                return false;
            }
            if ($node->class->toString() !== 'Logger') {
                return false;
            }
            if (!($node->name instanceof Node\Identifier)) {
                return false;
            }
            return isset(self::LOGGER_CONSTANT_MAP[strtoupper($node->name->toString())]);
        });
    }

    /**
     * Find echo/print statements that output $_REQUEST or $_SESSION values.
     *
     * @param array<Node\Stmt> $ast
     * @return array<Node\Stmt>
     */
    private function findDangerousEchoes(array $ast): array
    {
        return $this->finder()->find($ast, function (Node $node): bool {
            if ($node instanceof Node\Stmt\Echo_) {
                foreach ($node->exprs as $expr) {
                    if ($this->isSuperGlobalExpr($expr)) {
                        return true;
                    }
                }
            }
            if ($node instanceof Node\Expr\Print_) {
                return $this->isSuperGlobalExpr($node->expr);
            }
            return false;
        });
    }

    private function isSuperGlobalExpr(Node\Expr $expr): bool
    {
        if ($expr instanceof Node\Expr\Variable
            && is_string($expr->name)
            && in_array($expr->name, ['_REQUEST', '_SESSION'], true)
        ) {
            return true;
        }
        if ($expr instanceof Node\Expr\ArrayDimFetch
            && $expr->var instanceof Node\Expr\Variable
            && is_string($expr->var->name)
            && in_array($expr->var->name, ['_REQUEST', '_SESSION'], true)
        ) {
            return true;
        }
        return false;
    }

    /**
     * Find commented-out debug calls in raw source.
     *
     * @return array<int, string> line number (1-based) → line text
     */
    private function findCommentedDebugLines(string $code): array
    {
        $results = [];
        $lines   = explode("\n", $code);

        foreach ($lines as $idx => $line) {
            // Match // or # style comments containing debug functions
            if (preg_match('/^\h*(\/\/|#).*\b(var_dump|print_r|error_log)\s*\(/', $line)) {
                $results[$idx + 1] = $line;
            }
        }

        return $results;
    }
}
