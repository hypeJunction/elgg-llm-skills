<?php

declare(strict_types=1);

namespace ElggMigrate;

use PhpParser\Node;
use PhpParser\NodeFinder;
use PhpParser\ParserFactory;
use PhpParser\PrettyPrinter;

/**
 * Base class providing common utilities for migration rules.
 */
abstract class AbstractRule implements MigrationRule
{
    protected function parser(): \PhpParser\Parser
    {
        return (new ParserFactory())->createForHostVersion();
    }

    protected function printer(): PrettyPrinter\Standard
    {
        return new PrettyPrinter\Standard();
    }

    protected function finder(): NodeFinder
    {
        return new NodeFinder();
    }

    /**
     * Parse PHP code into an AST, returning null on failure.
     *
     * @return array<Node\Stmt>|null
     */
    protected function parse(string $code): ?array
    {
        try {
            return $this->parser()->parse($code);
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * Pretty-print an AST back to PHP code.
     *
     * @param array<Node\Stmt> $ast
     */
    protected function print(array $ast): string
    {
        return $this->printer()->prettyPrintFile($ast);
    }

    /**
     * Yield all .php files recursively under a directory.
     *
     * @return \Generator<string>
     */
    protected function findPhpFiles(string $dir): \Generator
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

    /**
     * Get a path relative to a base directory.
     */
    protected function relativePath(string $base, string $path): string
    {
        $base = rtrim($base, '/') . '/';
        if (str_starts_with($path, $base)) {
            return substr($path, strlen($base));
        }
        return $path;
    }

    /**
     * Find all function calls matching given names in an AST.
     *
     * @param array<Node\Stmt> $ast
     * @param array<string> $functionNames
     * @return array<Node\Expr\FuncCall>
     */
    protected function findFunctionCalls(array $ast, array $functionNames): array
    {
        return $this->finder()->find($ast, function (Node $node) use ($functionNames) {
            return $node instanceof Node\Expr\FuncCall
                && $node->name instanceof Node\Name
                && in_array($node->name->toString(), $functionNames, true);
        });
    }
}
