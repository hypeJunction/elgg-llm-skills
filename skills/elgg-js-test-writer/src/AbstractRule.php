<?php

declare(strict_types=1);

namespace ElggMigrate;

use PhpParser\Node;
use PhpParser\NodeFinder;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitor\CloningVisitor;
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
     * Use this for analyze passes that only inspect code. For apply passes
     * that mutate and rewrite the file, use parsePreserving() so the
     * original formatting of untouched regions survives.
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
     * Parse a file's contents for format-preserving editing.
     *
     * Returns the original AST (for the printer's diff), a deep clone
     * (to mutate), and the original token stream. Pass all three to
     * printPreserving() after mutating the clone — unchanged regions
     * round-trip byte-for-byte from the tokens.
     *
     * @return array{old: array<Node\Stmt>, new: array<Node\Stmt>, tokens: array<\PhpParser\Token>}|null
     */
    protected function parsePreserving(string $code): ?array
    {
        $parser = $this->parser();
        try {
            $oldStmts = $parser->parse($code);
        } catch (\Throwable) {
            return null;
        }
        if ($oldStmts === null) {
            return null;
        }
        $tokens = $parser->getTokens();

        $cloneTraverser = new NodeTraverser();
        $cloneTraverser->addVisitor(new CloningVisitor());
        $newStmts = $cloneTraverser->traverse($oldStmts);

        return ['old' => $oldStmts, 'new' => $newStmts, 'tokens' => $tokens];
    }

    /**
     * Print a mutated AST while preserving the original formatting of
     * untouched regions (whitespace, blank lines, comments, multi-line
     * array layouts). Pair with parsePreserving().
     *
     * @param array<Node\Stmt> $newStmts mutated clone
     * @param array<Node\Stmt> $oldStmts original AST returned by parsePreserving()
     * @param array<\PhpParser\Token> $tokens original tokens returned by parsePreserving()
     */
    protected function printPreserving(array $newStmts, array $oldStmts, array $tokens): string
    {
        return $this->printer()->printFormatPreserving($newStmts, $oldStmts, $tokens);
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
