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
use PhpParser\NodeVisitor\CloningVisitor;
use PhpParser\NodeVisitorAbstract;
use PhpParser\ParserFactory;
use PhpParser\PrettyPrinter;

/**
 * Replaces elgg_register_library()/elgg_load_library() with require_once.
 *
 * In Elgg 2.x:
 *   elgg_register_library('my_lib', elgg_get_plugins_path() . 'myplugin/lib/helpers.php');
 *   elgg_load_library('my_lib');
 *
 * In Elgg 3.x these are removed. Replace with:
 *   require_once __DIR__ . '/lib/helpers.php';
 *
 * For class-only libraries, prefer PSR-4 autoloading (manual step — warned).
 */
final class LibraryToAutoload implements MigrationRule
{
    public function getId(): string
    {
        return 'library-to-autoload';
    }

    public function getDescription(): string
    {
        return 'Replace elgg_register_library()/elgg_load_library() with require_once';
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

            foreach ($this->findLibraryCalls($code) as $call) {
                $findings[] = new Finding(
                    file: $relativePath,
                    line: $call['line'],
                    description: "{$call['function']}('{$call['name']}') should be replaced",
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
                ? sprintf('Found %d library registration/load call(s) to replace', count($findings))
                : 'No library calls found',
        );
    }

    public function apply(string $pluginPath): RuleResult
    {
        // First pass: collect all library registrations to build name→path map
        $libraryMap = $this->buildLibraryMap($pluginPath);

        $changes = [];
        $warnings = [];

        foreach ($this->findPhpFiles($pluginPath) as $file) {
            $relativePath = $this->relativePath($pluginPath, $file);
            $code = file_get_contents($file);
            if ($code === false) {
                continue;
            }

            $calls = $this->findLibraryCalls($code);
            if (empty($calls)) {
                continue;
            }

            $result = $this->transformFile($code, $libraryMap);

            if ($result['transformed']) {
                file_put_contents($file, $result['code']);
                $changes[] = new FileChange(
                    file: $relativePath,
                    type: 'modified',
                    description: 'Replaced library calls with require_once',
                );

                foreach ($result['warnings'] as $w) {
                    $warnings[] = "{$relativePath}: {$w}";
                }
            }
        }

        if (empty($changes)) {
            return new RuleResult(
                ruleId: $this->getId(),
                success: true,
                changes: [],
                warnings: ['No library calls found — nothing to transform'],
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
     * Build a map of library name → file path from elgg_register_library calls.
     *
     * @return array<string, string> name => path expression code
     */
    private function buildLibraryMap(string $pluginPath): array
    {
        $map = [];

        foreach ($this->findPhpFiles($pluginPath) as $file) {
            $code = file_get_contents($file);
            if ($code === false) {
                continue;
            }

            foreach ($this->findLibraryCalls($code) as $call) {
                if ($call['function'] === 'elgg_register_library' && $call['name'] !== '') {
                    $map[$call['name']] = $call['path_expr'] ?? '';
                }
            }
        }

        return $map;
    }

    /**
     * @return array<array{function: string, name: string, line: int, code: string, path_expr?: string}>
     */
    private function findLibraryCalls(string $code): array
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
                && in_array($node->name->toString(), ['elgg_register_library', 'elgg_load_library'], true);
        });

        $results = [];
        $printer = new PrettyPrinter\Standard();

        foreach ($calls as $call) {
            /** @var Node\Expr\FuncCall $call */
            $funcName = $call->name->toString();

            $libName = '';
            if (isset($call->args[0]) && $call->args[0]->value instanceof Node\Scalar\String_) {
                $libName = $call->args[0]->value->value;
            }

            $entry = [
                'function' => $funcName,
                'name' => $libName,
                'line' => $call->getLine(),
                'code' => $printer->prettyPrintExpr($call),
            ];

            // For register calls, capture the path expression
            if ($funcName === 'elgg_register_library' && isset($call->args[1])) {
                $entry['path_expr'] = $printer->prettyPrintExpr($call->args[1]->value);
            }

            $results[] = $entry;
        }

        return $results;
    }

    /**
     * @return array{transformed: bool, code: string, warnings: array<string>}
     */
    private function transformFile(string $code, array $libraryMap): array
    {
        $parser = (new ParserFactory())->createForHostVersion();

        try {
            $oldAst = $parser->parse($code);
        } catch (\Throwable) {
            return ['transformed' => false, 'code' => $code, 'warnings' => []];
        }

        if ($oldAst === null) {
            return ['transformed' => false, 'code' => $code, 'warnings' => []];
        }
        $tokens = $parser->getTokens();

        $cloneTraverser = new NodeTraverser();
        $cloneTraverser->addVisitor(new CloningVisitor());
        $newAst = $cloneTraverser->traverse($oldAst);

        $warnings = [];

        $traverser = new NodeTraverser();
        $visitor = new class($libraryMap, $warnings) extends NodeVisitorAbstract {
            private bool $changed = false;

            public function __construct(
                private readonly array $libraryMap,
                private array &$warnings,
            ) {}

            public function leaveNode(Node $node): int|Node|null
            {
                // We must operate on Expression statements, not the FuncCall inside them,
                // because REMOVE_NODE and node replacement only work at statement level.
                if (!$node instanceof Node\Stmt\Expression) {
                    return null;
                }

                if (!$node->expr instanceof Node\Expr\FuncCall) {
                    return null;
                }

                $call = $node->expr;

                if (!$call->name instanceof Node\Name) {
                    return null;
                }

                $funcName = $call->name->toString();

                // Remove elgg_register_library entirely
                if ($funcName === 'elgg_register_library') {
                    $this->changed = true;
                    return NodeTraverser::REMOVE_NODE;
                }

                // Replace elgg_load_library with require_once
                if ($funcName === 'elgg_load_library') {
                    $libName = '';
                    if (isset($call->args[0]) && $call->args[0]->value instanceof Node\Scalar\String_) {
                        $libName = $call->args[0]->value->value;
                    }

                    $pathExpr = $this->libraryMap[$libName] ?? null;

                    if ($pathExpr !== null && $pathExpr !== '') {
                        // We have the registered path — use it in require_once
                        $parser = (new ParserFactory())->createForHostVersion();
                        try {
                            $pathAst = $parser->parse("<?php {$pathExpr};");
                            if ($pathAst && isset($pathAst[0]) && $pathAst[0] instanceof Node\Stmt\Expression) {
                                $pathNode = $pathAst[0]->expr;
                                $this->changed = true;
                                $node->expr = new Node\Expr\Include_(
                                    $pathNode,
                                    Node\Expr\Include_::TYPE_REQUIRE_ONCE,
                                );
                                return $node;
                            }
                        } catch (\Throwable) {
                            // Fall through to warning
                        }
                    }

                    // Couldn't resolve path — remove and warn
                    $this->warnings[] = "Could not resolve path for library '{$libName}' — replace elgg_load_library('{$libName}') manually with require_once";
                    $this->changed = true;
                    return NodeTraverser::REMOVE_NODE;
                }

                return null;
            }

            public function hasChanged(): bool
            {
                return $this->changed;
            }
        };

        $traverser->addVisitor($visitor);
        $newAst = $traverser->traverse($newAst);

        if (!$visitor->hasChanged()) {
            return ['transformed' => false, 'code' => $code, 'warnings' => $warnings];
        }

        $printer = new PrettyPrinter\Standard();
        $newCode = $printer->printFormatPreserving($newAst, $oldAst, $tokens);

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
