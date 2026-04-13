<?php

declare(strict_types=1);

namespace ElggMigrate\Rules\V2ToV3;

use ElggMigrate\FileChange;
use ElggMigrate\Finding;
use ElggMigrate\MigrationRule;
use ElggMigrate\RuleAnalysis;
use ElggMigrate\RuleResult;
use PhpParser\Node;
use PhpParser\NodeFinder;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitorAbstract;
use PhpParser\ParserFactory;
use PhpParser\PrettyPrinter;

/**
 * Converts elgg_register_page_handler() calls to elgg_register_route().
 *
 * In Elgg 2.x, plugins register page handlers:
 *   elgg_register_page_handler('wall', 'wall_page_handler');
 *   elgg_register_page_handler('wall', [Router::class, 'handlePages']);
 *
 * In Elgg 3.x, these become named route registrations:
 *   elgg_register_route('wall', [
 *       'path' => '/wall/{segments}',
 *       'resource' => 'wall',
 *       'requirements' => ['segments' => '.+'],
 *   ]);
 *
 * This rule finds all elgg_register_page_handler calls and replaces them
 * with elgg_register_route calls. The actual page handler callback logic
 * must be manually migrated to a resource view.
 */
final class PageHandlerToRoute implements MigrationRule
{
    public function getId(): string
    {
        return 'page-handler-to-route';
    }

    public function getDescription(): string
    {
        return 'Convert elgg_register_page_handler() calls to elgg_register_route()';
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
            if ($code === false) {
                continue;
            }

            $calls = $this->findPageHandlerCalls($code);
            foreach ($calls as $call) {
                $findings[] = new Finding(
                    file: $relativePath,
                    line: $call['line'],
                    description: "elgg_register_page_handler('{$call['handler']}', ...) should become elgg_register_route()",
                    code: $call['code'],
                );
            }
        }

        $applicable = count($findings) > 0;

        return new RuleAnalysis(
            ruleId: $this->getId(),
            applicable: $applicable,
            findings: $findings,
            summary: $applicable
                ? sprintf('Found %d page handler registration(s) to convert', count($findings))
                : 'No page handler registrations found',
        );
    }

    public function apply(string $pluginPath): RuleResult
    {
        $changes = [];
        $warnings = [];
        $errors = [];

        foreach ($this->findPhpFiles($pluginPath) as $file) {
            $relativePath = $this->relativePath($pluginPath, $file);
            $code = file_get_contents($file);
            if ($code === false) {
                continue;
            }

            $calls = $this->findPageHandlerCalls($code);
            if (empty($calls)) {
                continue;
            }

            $result = $this->transformFile($code, $calls);

            if ($result['transformed']) {
                file_put_contents($file, $result['code']);
                $changes[] = new FileChange(
                    file: $relativePath,
                    type: 'modified',
                    description: sprintf(
                        'Replaced %d elgg_register_page_handler() call(s) with elgg_register_route()',
                        count($calls)
                    ),
                );

                foreach ($result['warnings'] as $warning) {
                    $warnings[] = "{$relativePath}: {$warning}";
                }
            }
        }

        if (empty($changes) && empty($errors)) {
            return new RuleResult(
                ruleId: $this->getId(),
                success: true,
                changes: [],
                warnings: ['No page handler registrations found — nothing to transform'],
            );
        }

        return new RuleResult(
            ruleId: $this->getId(),
            success: empty($errors),
            changes: $changes,
            warnings: $warnings,
            errors: $errors,
        );
    }

    /**
     * Find all elgg_register_page_handler() calls in PHP code.
     *
     * @return array<array{handler: string, line: int, code: string, callback: string}>
     */
    private function findPageHandlerCalls(string $code): array
    {
        $parser = (new ParserFactory())->createForHostVersion();

        try {
            $ast = $parser->parse($code);
        } catch (\Throwable) {
            return [];
        }

        if ($ast === null) {
            return [];
        }

        $finder = new NodeFinder();
        $calls = $finder->find($ast, function (Node $node) {
            return $node instanceof Node\Expr\FuncCall
                && $node->name instanceof Node\Name
                && $node->name->toString() === 'elgg_register_page_handler';
        });

        $results = [];
        $printer = new PrettyPrinter\Standard();

        foreach ($calls as $call) {
            /** @var Node\Expr\FuncCall $call */
            $handler = '';
            if (isset($call->args[0]) && $call->args[0]->value instanceof Node\Scalar\String_) {
                $handler = $call->args[0]->value->value;
            }

            $callback = '';
            if (isset($call->args[1])) {
                $callback = $printer->prettyPrintExpr($call->args[1]->value);
            }

            $results[] = [
                'handler' => $handler,
                'line' => $call->getLine(),
                'code' => $printer->prettyPrintExpr($call),
                'callback' => $callback,
            ];
        }

        return $results;
    }

    /**
     * Transform elgg_register_page_handler calls to elgg_register_route.
     *
     * @return array{transformed: bool, code: string, warnings: array<string>}
     */
    private function transformFile(string $code, array $calls): array
    {
        $parser = (new ParserFactory())->createForHostVersion();

        try {
            $ast = $parser->parse($code);
        } catch (\Throwable) {
            return ['transformed' => false, 'code' => $code, 'warnings' => []];
        }

        if ($ast === null) {
            return ['transformed' => false, 'code' => $code, 'warnings' => []];
        }

        $warnings = [];
        $handlerNames = array_column($calls, 'handler');

        $traverser = new NodeTraverser();
        $visitor = new class($handlerNames, $warnings) extends NodeVisitorAbstract {
            private bool $changed = false;

            public function __construct(
                private readonly array $handlerNames,
                private array &$warnings,
            ) {}

            public function leaveNode(Node $node): ?Node
            {
                if (!$node instanceof Node\Expr\FuncCall) {
                    return null;
                }

                if (!$node->name instanceof Node\Name) {
                    return null;
                }

                if ($node->name->toString() !== 'elgg_register_page_handler') {
                    return null;
                }

                // Extract handler name
                $handler = '';
                if (isset($node->args[0]) && $node->args[0]->value instanceof Node\Scalar\String_) {
                    $handler = $node->args[0]->value->value;
                }

                if ($handler === '') {
                    $this->warnings[] = 'Could not extract handler name from elgg_register_page_handler call';
                    return null;
                }

                // Build the route config array
                $items = [
                    new Node\ArrayItem(
                        new Node\Scalar\String_("/{$handler}/{segments}"),
                        new Node\Scalar\String_('path'),
                    ),
                    new Node\ArrayItem(
                        new Node\Scalar\String_($handler),
                        new Node\Scalar\String_('resource'),
                    ),
                    new Node\ArrayItem(
                        new Node\Expr\Array_([
                            new Node\ArrayItem(
                                new Node\Scalar\String_('.+'),
                                new Node\Scalar\String_('segments'),
                            ),
                        ], ['kind' => Node\Expr\Array_::KIND_SHORT]),
                        new Node\Scalar\String_('requirements'),
                    ),
                    new Node\ArrayItem(
                        new Node\Expr\Array_([
                            new Node\ArrayItem(
                                new Node\Scalar\String_(''),
                                new Node\Scalar\String_('segments'),
                            ),
                        ], ['kind' => Node\Expr\Array_::KIND_SHORT]),
                        new Node\Scalar\String_('defaults'),
                    ),
                ];

                $routeConfig = new Node\Expr\Array_($items, ['kind' => Node\Expr\Array_::KIND_SHORT]);

                // Create elgg_register_route('handler', [...])
                $newCall = new Node\Expr\FuncCall(
                    new Node\Name('elgg_register_route'),
                    [
                        new Node\Arg(new Node\Scalar\String_($handler)),
                        new Node\Arg($routeConfig),
                    ],
                );

                // Preserve original callback as a comment for manual migration
                if (isset($node->args[1])) {
                    $printer = new PrettyPrinter\Standard();
                    $callbackCode = $printer->prettyPrintExpr($node->args[1]->value);
                    $this->warnings[] = "Page handler callback for '{$handler}' was: {$callbackCode} — migrate this logic to a resource view at views/default/resources/{$handler}.php";
                }

                $this->changed = true;

                return $newCall;
            }

            public function hasChanged(): bool
            {
                return $this->changed;
            }
        };

        $traverser->addVisitor($visitor);
        $newAst = $traverser->traverse($ast);

        if (!$visitor->hasChanged()) {
            return ['transformed' => false, 'code' => $code, 'warnings' => $warnings];
        }

        $printer = new PrettyPrinter\Standard();
        $newCode = $printer->prettyPrintFile($newAst);

        return ['transformed' => true, 'code' => $newCode, 'warnings' => $warnings];
    }

    /**
     * @return \Generator<string>
     */
    private function findPhpFiles(string $dir): \Generator
    {
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
        );

        foreach ($iterator as $file) {
            /** @var \SplFileInfo $file */
            if ($file->getExtension() === 'php') {
                yield $file->getPathname();
            }
        }
    }

    private function relativePath(string $base, string $path): string
    {
        $base = rtrim($base, '/') . '/';
        if (str_starts_with($path, $base)) {
            return substr($path, strlen($base));
        }
        return $path;
    }
}
