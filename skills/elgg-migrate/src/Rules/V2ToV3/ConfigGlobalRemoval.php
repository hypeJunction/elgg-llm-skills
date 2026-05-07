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
 * Replaces legacy global config access patterns with proper API calls.
 *
 * Patterns replaced:
 *   global $CONFIG; $CONFIG->dbprefix → elgg_get_config('dbprefix')
 *   $vars['url']    → elgg_get_site_url()
 *   $vars['user']   → elgg_get_logged_in_user_entity()
 *   $vars['config'] → (removed, use elgg_get_config())
 */
final class ConfigGlobalRemoval implements MigrationRule
{
    public function getId(): string
    {
        return 'config-global-removal';
    }

    public function getDescription(): string
    {
        return "Replace \$CONFIG global and legacy \$vars['url']/\$vars['user'] with API calls";
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

            foreach ($this->findLegacyAccess($code) as $item) {
                $findings[] = new Finding(
                    file: $relativePath,
                    line: $item['line'],
                    description: $item['description'],
                    code: $item['code'],
                );
            }
        }

        $applicable = count($findings) > 0;

        return new RuleAnalysis(
            ruleId: $this->getId(),
            applicable: $applicable,
            findings: $findings,
            summary: $applicable
                ? sprintf('Found %d legacy config/vars access pattern(s)', count($findings))
                : 'No legacy config access patterns found',
        );
    }

    public function apply(string $pluginPath): RuleResult
    {
        $changes = [];
        $warnings = [];

        foreach ($this->findPhpFiles($pluginPath) as $file) {
            $relativePath = $this->relativePath($pluginPath, $file);
            $code = file_get_contents($file);
            if ($code === false) {
                continue;
            }

            if (empty($this->findLegacyAccess($code))) {
                continue;
            }

            $result = $this->transformFile($code);

            if ($result['transformed']) {
                file_put_contents($file, $result['code']);
                $changes[] = new FileChange(
                    file: $relativePath,
                    type: 'modified',
                    description: 'Replaced legacy config/vars access with API calls',
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
                warnings: ['No legacy config access patterns found'],
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
     * @return array<array{line: int, description: string, code: string}>
     */
    private function findLegacyAccess(string $code): array
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

        $results = [];
        $printer = new PrettyPrinter\Standard();
        $finder = new NodeFinder();

        // Find `global $CONFIG` statements
        $globals = $finder->find($ast, function (Node $node) {
            return $node instanceof Node\Stmt\Global_
                && $this->hasConfigVar($node);
        });

        foreach ($globals as $global) {
            $results[] = [
                'line' => $global->getLine(),
                'description' => 'global $CONFIG statement — remove and replace $CONFIG-> access with elgg_get_config()',
                'code' => 'global $CONFIG',
            ];
        }

        // Find $CONFIG->property access
        $configAccess = $finder->find($ast, function (Node $node) {
            return $node instanceof Node\Expr\PropertyFetch
                && $node->var instanceof Node\Expr\Variable
                && $node->var->name === 'CONFIG';
        });

        foreach ($configAccess as $access) {
            $results[] = [
                'line' => $access->getLine(),
                'description' => '$CONFIG->... access — replace with elgg_get_config()',
                'code' => $printer->prettyPrintExpr($access),
            ];
        }

        // Find $vars['url'], $vars['user'], $vars['config']
        $legacyVarKeys = ['url', 'user', 'config'];
        $varsAccess = $finder->find($ast, function (Node $node) use ($legacyVarKeys) {
            return $node instanceof Node\Expr\ArrayDimFetch
                && $node->var instanceof Node\Expr\Variable
                && $node->var->name === 'vars'
                && $node->dim instanceof Node\Scalar\String_
                && in_array($node->dim->value, $legacyVarKeys, true);
        });

        foreach ($varsAccess as $access) {
            /** @var Node\Expr\ArrayDimFetch $access */
            $key = $access->dim->value;
            $replacement = match ($key) {
                'url' => 'elgg_get_site_url()',
                'user' => 'elgg_get_logged_in_user_entity()',
                'config' => 'elgg_get_config()',
            };
            $results[] = [
                'line' => $access->getLine(),
                'description' => "\$vars['{$key}'] → {$replacement}",
                'code' => $printer->prettyPrintExpr($access),
            ];
        }

        return $results;
    }

    /**
     * @return array{transformed: bool, code: string, warnings: array<string>}
     */
    private function transformFile(string $code): array
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
        $visitor = new class($warnings) extends NodeVisitorAbstract {
            private bool $changed = false;

            public function __construct(private array &$warnings) {}

            public function leaveNode(Node $node): int|Node|null
            {
                // Remove `global $CONFIG` statements
                if ($node instanceof Node\Stmt\Global_) {
                    $remaining = [];
                    $hadConfig = false;

                    foreach ($node->vars as $var) {
                        if ($var instanceof Node\Expr\Variable && $var->name === 'CONFIG') {
                            $hadConfig = true;
                        } else {
                            $remaining[] = $var;
                        }
                    }

                    if ($hadConfig) {
                        $this->changed = true;
                        if (empty($remaining)) {
                            return NodeTraverser::REMOVE_NODE;
                        }
                        $node->vars = $remaining;
                        return $node;
                    }
                }

                // Replace $CONFIG->property with elgg_get_config('property')
                if ($node instanceof Node\Expr\PropertyFetch
                    && $node->var instanceof Node\Expr\Variable
                    && $node->var->name === 'CONFIG'
                    && $node->name instanceof Node\Identifier
                ) {
                    $propName = $node->name->name;
                    $this->changed = true;
                    return new Node\Expr\FuncCall(
                        new Node\Name('elgg_get_config'),
                        [new Node\Arg(new Node\Scalar\String_($propName))],
                    );
                }

                // Replace $vars['url'] → elgg_get_site_url()
                // Replace $vars['user'] → elgg_get_logged_in_user_entity()
                // Replace $vars['config'] → warn (needs context-specific replacement)
                if ($node instanceof Node\Expr\ArrayDimFetch
                    && $node->var instanceof Node\Expr\Variable
                    && $node->var->name === 'vars'
                    && $node->dim instanceof Node\Scalar\String_
                ) {
                    $key = $node->dim->value;

                    if ($key === 'url') {
                        $this->changed = true;
                        return new Node\Expr\FuncCall(new Node\Name('elgg_get_site_url'));
                    }

                    if ($key === 'user') {
                        $this->changed = true;
                        return new Node\Expr\FuncCall(new Node\Name('elgg_get_logged_in_user_entity'));
                    }

                    if ($key === 'config') {
                        $this->changed = true;
                        $this->warnings[] = "\$vars['config'] removed — replace \$vars['config']->property with elgg_get_config('property')";
                        return new Node\Expr\FuncCall(new Node\Name('elgg_get_config'), [new Node\Arg(new Node\Scalar\String_('/* FIXME: specify config key */'))]);
                    }
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

    private function hasConfigVar(Node\Stmt\Global_ $node): bool
    {
        foreach ($node->vars as $var) {
            if ($var instanceof Node\Expr\Variable && $var->name === 'CONFIG') {
                return true;
            }
        }
        return false;
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
