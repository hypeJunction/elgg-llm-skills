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
 * Modernize legacy logging to PSR-3 via elgg()->logger.
 *
 * Rewrites three patterns:
 * - error_log($msg)                       → elgg()->logger->error($msg)
 * - elgg_log($msg, 'LEVEL')               → elgg()->logger->{level}($msg)
 * - elgg_log($msg, Logger::LEVEL)         → elgg()->logger->{level}($msg)
 *
 * The 'LEVEL' second arg may be a string literal ('NOTICE', 'WARNING', 'ERROR',
 * 'DEBUG', 'INFO') or a Logger:: class constant. Defaults to 'notice' when the
 * second arg is missing — matches Elgg 2.x elgg_log() default.
 *
 * Warns (does not rewrite) on:
 * - var_dump() / print_r() called as standalone statements (debug residue)
 *
 * Does not touch print_r($x, true) (capture-to-string) or non-call uses.
 */
final class Psr3Logging extends AbstractRule
{
    /** Map elgg_log level (case-insensitive) → PSR-3 method name. */
    private const LEVEL_MAP = [
        'ERROR' => 'error',
        'WARNING' => 'warning',
        'NOTICE' => 'notice',
        'INFO' => 'info',
        'DEBUG' => 'debug',
    ];

    private const TARGET_FUNCS = ['error_log', 'elgg_log', 'var_dump', 'print_r'];

    public function getId(): string
    {
        return 'psr3-logging';
    }

    public function getDescription(): string
    {
        return 'Rewrite legacy error_log/elgg_log calls to PSR-3 via elgg()->logger; warn on debug residue';
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

            $calls = $this->findFunctionCalls($ast, self::TARGET_FUNCS);
            $printer = $this->printer();

            foreach ($calls as $call) {
                $name = $call->name->toString();
                $desc = match ($name) {
                    'error_log' => 'error_log() → elgg()->logger->error()',
                    'elgg_log' => 'elgg_log() → elgg()->logger->{level}()',
                    'var_dump' => 'var_dump() — debug residue, review or remove',
                    'print_r' => $this->isPrintRCapture($call)
                        ? null  // skip print_r($x, true)
                        : 'print_r() — debug residue, review or remove',
                    default => null,
                };
                if ($desc === null) continue;

                $findings[] = new Finding(
                    file: $relativePath,
                    line: $call->getLine(),
                    description: $desc,
                    code: $printer->prettyPrintExpr($call),
                );
            }
        }

        return new RuleAnalysis(
            ruleId: $this->getId(),
            applicable: count($findings) > 0,
            findings: $findings,
            summary: $findings
                ? sprintf('Found %d legacy logging call(s)', count($findings))
                : 'No legacy logging calls found',
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

            $calls = $this->findFunctionCalls($ast, self::TARGET_FUNCS);
            if (empty($calls)) continue;

            $result = $this->transformFile($code);

            if ($result['transformed'] || !empty($result['warnings'])) {
                if ($result['transformed']) {
                    file_put_contents($file, $result['code']);
                    $changes[] = new FileChange(
                        file: $relativePath,
                        type: 'modified',
                        description: 'Rewrote legacy logging to PSR-3',
                    );
                }
                foreach ($result['warnings'] as $w) {
                    $warnings[] = "{$relativePath}: {$w}";
                }
            }
        }

        if (empty($changes) && empty($warnings)) {
            return new RuleResult(
                ruleId: $this->getId(),
                success: true,
                changes: [],
                warnings: ['No legacy logging calls found'],
            );
        }

        return new RuleResult(
            ruleId: $this->getId(),
            success: true,
            changes: $changes,
            warnings: $warnings,
        );
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

            public function __construct(private array &$warnings) {}

            public function leaveNode(Node $node): int|Node|null
            {
                if (!($node instanceof Node\Expr\FuncCall) || !($node->name instanceof Node\Name)) {
                    return null;
                }

                $name = $node->name->toString();

                if ($name === 'error_log') {
                    return $this->rewriteErrorLog($node);
                }

                if ($name === 'elgg_log') {
                    return $this->rewriteElggLog($node);
                }

                if ($name === 'var_dump') {
                    $this->warnings[] = "var_dump() at line {$node->getLine()} — debug residue, review or remove";
                    return null;
                }

                if ($name === 'print_r' && !Psr3Logging::isPrintRCaptureStatic($node)) {
                    $this->warnings[] = "print_r() at line {$node->getLine()} — debug residue, review or remove";
                    return null;
                }

                return null;
            }

            private function rewriteErrorLog(Node\Expr\FuncCall $call): Node\Expr\MethodCall
            {
                $this->changed = true;
                return Psr3Logging::buildLoggerCall('error', $call->args);
            }

            private function rewriteElggLog(Node\Expr\FuncCall $call): ?Node\Expr\MethodCall
            {
                $args = $call->args;
                if (empty($args)) {
                    return null;
                }
                $level = 'notice';
                if (isset($args[1])) {
                    $resolved = Psr3Logging::resolveLevelArg($args[1]->value);
                    if ($resolved === null) {
                        // Dynamic/unknown level — leave the call untouched.
                        $this->warnings[] = "elgg_log() at line {$call->getLine()} — second arg not a known level constant; left untouched";
                        return null;
                    }
                    $level = $resolved;
                }
                $this->changed = true;
                return Psr3Logging::buildLoggerCall($level, [$args[0]]);
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
     * Build elgg()->logger->{level}(...$args) as a method call.
     *
     * @param array<Node\Arg> $args
     */
    public static function buildLoggerCall(string $level, array $args): Node\Expr\MethodCall
    {
        $elggCall = new Node\Expr\FuncCall(new Node\Name('elgg'));
        $loggerProp = new Node\Expr\PropertyFetch($elggCall, new Node\Identifier('logger'));
        return new Node\Expr\MethodCall($loggerProp, new Node\Identifier($level), $args);
    }

    /**
     * Resolve an elgg_log() second arg to a PSR-3 method name, or null if unrecognized.
     */
    public static function resolveLevelArg(Node\Expr $arg): ?string
    {
        if ($arg instanceof Node\Scalar\String_) {
            $upper = strtoupper(trim($arg->value));
            return self::LEVEL_MAP[$upper] ?? null;
        }
        if ($arg instanceof Node\Expr\ClassConstFetch
            && $arg->class instanceof Node\Name
            && $arg->class->toString() === 'Logger'
            && $arg->name instanceof Node\Identifier
        ) {
            $upper = strtoupper($arg->name->toString());
            return self::LEVEL_MAP[$upper] ?? null;
        }
        return null;
    }

    /** Static counterpart used inside the anonymous visitor. */
    public static function isPrintRCaptureStatic(Node\Expr\FuncCall $call): bool
    {
        if (!isset($call->args[1])) {
            return false;
        }
        $arg = $call->args[1]->value;
        return $arg instanceof Node\Expr\ConstFetch
            && $arg->name instanceof Node\Name
            && strtolower($arg->name->toString()) === 'true';
    }

    /** Instance variant for use in analyze(). */
    private function isPrintRCapture(Node\Expr\FuncCall $call): bool
    {
        return self::isPrintRCaptureStatic($call);
    }
}
