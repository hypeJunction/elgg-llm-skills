<?php

declare(strict_types=1);

namespace ElggMigrate\Rules\V3ToV4;

use ElggMigrate\AbstractRule;
use ElggMigrate\FileChange;
use ElggMigrate\Finding;
use ElggMigrate\RuleAnalysis;
use ElggMigrate\RuleResult;
use PhpParser\Node;
use PhpParser\NodeFinder;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitorAbstract;

/**
 * Prefixes unqualified elgg_* / _elgg_* helper calls with `\` inside namespaced files.
 *
 * When a file declares `namespace Foo\Bar;`, PHP resolves an unqualified call like
 * `elgg_register_admin_menu_item(...)` as `Foo\Bar\elgg_register_admin_menu_item(...)`
 * and fatals with "Call to undefined function". The fix is to use the FQN `\elgg_...`.
 *
 * This rule is conservative: it only touches calls where (a) the file has a top-level
 * `namespace` declaration, (b) the call name matches /^(elgg_|_elgg)/, (c) the name is
 * unqualified (no leading `\`, no qualified prefix), (d) the name is not `use function`-
 * imported, and (e) the name is not defined inside this same file.
 */
final class NamespacedGlobalElggHelpers extends AbstractRule
{
    public function getId(): string
    {
        return 'namespaced-global-elgg-helpers';
    }

    public function getDescription(): string
    {
        return 'Prefix unqualified elgg_* / _elgg_* helper calls with `\\` inside namespaced files';
    }

    public function canAutomate(): bool
    {
        return true;
    }

    /**
     * Path segments under any depth that should be skipped — third-party code
     * or generated state we have no business rewriting.
     */
    private const EXCLUDED_SEGMENTS = [
        '/vendor/',
        '/vendors/',
        '/node_modules/',
        '/.git/',
    ];

    public function analyze(string $pluginPath): RuleAnalysis
    {
        $findings = [];

        foreach ($this->findPhpFiles($pluginPath) as $file) {
            if ($this->isExcluded($file)) continue;

            $relativePath = $this->relativePath($pluginPath, $file);
            $code = file_get_contents($file);
            if ($code === false) continue;

            $ast = $this->parse($code);
            if ($ast === null) continue;

            foreach ($this->findTargetCalls($ast) as $call) {
                $findings[] = new Finding(
                    file: $relativePath,
                    line: $call->getLine(),
                    description: sprintf(
                        "Unqualified call to global Elgg helper '%s()' inside namespaced file — must be '\\%s()' in Elgg 4.x",
                        $call->name->toString(),
                        $call->name->toString(),
                    ),
                    code: $this->printer()->prettyPrintExpr($call),
                );
            }
        }

        $applicable = count($findings) > 0;

        return new RuleAnalysis(
            ruleId: $this->getId(),
            applicable: $applicable,
            findings: $findings,
            summary: $applicable
                ? sprintf('Found %d unqualified Elgg helper callsite(s) in namespaced files', count($findings))
                : 'No unqualified Elgg helper callsites in namespaced files',
        );
    }

    public function apply(string $pluginPath): RuleResult
    {
        $changes = [];

        foreach ($this->findPhpFiles($pluginPath) as $file) {
            if ($this->isExcluded($file)) continue;

            $relativePath = $this->relativePath($pluginPath, $file);
            $code = file_get_contents($file);
            if ($code === false) continue;

            $parsed = $this->parsePreserving($code);
            if ($parsed === null) continue;

            if (!$this->fileIsNamespaced($parsed['new'])) {
                continue;
            }

            $importedFns = $this->collectImportedFunctions($parsed['new']);
            $definedFns = $this->collectDefinedFunctions($parsed['new']);
            $skip = array_merge($importedFns, $definedFns);

            $traverser = new NodeTraverser();
            $visitor = new class($skip) extends NodeVisitorAbstract {
                public int $rewriteCount = 0;

                /** @param array<string, true> $skip names to leave alone (imported or locally defined) */
                public function __construct(private readonly array $skip) {}

                public function leaveNode(Node $node): ?Node
                {
                    if (!$node instanceof Node\Expr\FuncCall) return null;
                    if (!$node->name instanceof Node\Name) return null;

                    // Already FQN — leave it.
                    if ($node->name->isFullyQualified()) return null;
                    // Qualified like Foo\bar() — not our target.
                    if (!$node->name->isUnqualified()) return null;

                    $name = $node->name->toString();
                    if (!self::isElggHelperName($name)) return null;
                    if (isset($this->skip[$name])) return null;

                    // Don't propagate the original Name's attributes — the
                    // format-preserving printer uses node attributes (esp.
                    // position info) to decide whether a node was modified.
                    // Reusing the original attributes makes the printer
                    // think nothing changed and emit the original tokens.
                    $node->name = new Node\Name\FullyQualified($name);
                    $this->rewriteCount++;

                    return $node;
                }

                private static function isElggHelperName(string $name): bool
                {
                    return str_starts_with($name, 'elgg_') || str_starts_with($name, '_elgg');
                }
            };

            $traverser->addVisitor($visitor);
            $parsed['new'] = $traverser->traverse($parsed['new']);

            if ($visitor->rewriteCount > 0) {
                file_put_contents($file, $this->printPreserving($parsed['new'], $parsed['old'], $parsed['tokens']));
                $changes[] = new FileChange(
                    file: $relativePath,
                    type: 'modified',
                    description: sprintf('Prefixed %d Elgg helper call(s) with `\\`', $visitor->rewriteCount),
                );
            }
        }

        return new RuleResult(
            ruleId: $this->getId(),
            success: true,
            changes: $changes,
        );
    }

    /**
     * Returns all unqualified elgg_* / _elgg_* function calls inside a namespaced file,
     * skipping calls whose name is `use function`-imported or locally defined.
     *
     * @param array<Node\Stmt> $ast
     * @return array<Node\Expr\FuncCall>
     */
    private function findTargetCalls(array $ast): array
    {
        if (!$this->fileIsNamespaced($ast)) return [];

        $skip = array_merge(
            $this->collectImportedFunctions($ast),
            $this->collectDefinedFunctions($ast),
        );

        $targets = [];
        $finder = new NodeFinder();
        $calls = $finder->findInstanceOf($ast, Node\Expr\FuncCall::class);

        foreach ($calls as $call) {
            assert($call instanceof Node\Expr\FuncCall);
            if (!$call->name instanceof Node\Name) continue;
            if ($call->name->isFullyQualified()) continue;
            if (!$call->name->isUnqualified()) continue;

            $name = $call->name->toString();
            if (!(str_starts_with($name, 'elgg_') || str_starts_with($name, '_elgg'))) continue;
            if (isset($skip[$name])) continue;

            $targets[] = $call;
        }

        return $targets;
    }

    private function isExcluded(string $path): bool
    {
        $needle = '/' . trim(str_replace(DIRECTORY_SEPARATOR, '/', $path), '/') . '/';
        foreach (self::EXCLUDED_SEGMENTS as $seg) {
            if (str_contains($needle, $seg)) return true;
        }
        return false;
    }

    /**
     * @param array<Node\Stmt> $ast
     */
    private function fileIsNamespaced(array $ast): bool
    {
        foreach ($ast as $stmt) {
            if ($stmt instanceof Node\Stmt\Namespace_ && $stmt->name !== null) {
                return true;
            }
        }
        return false;
    }

    /**
     * @param array<Node\Stmt> $ast
     * @return array<string, true>
     */
    private function collectImportedFunctions(array $ast): array
    {
        $imports = [];
        $finder = new NodeFinder();
        // Use_::TYPE_FUNCTION imports — both `use function Foo\bar;` and
        // grouped `use function Foo\{bar, baz};` produce Use_ nodes with type FUNCTION.
        $useNodes = $finder->findInstanceOf($ast, Node\Stmt\Use_::class);
        foreach ($useNodes as $use) {
            assert($use instanceof Node\Stmt\Use_);
            if ($use->type !== Node\Stmt\Use_::TYPE_FUNCTION) continue;
            foreach ($use->uses as $uu) {
                $alias = $uu->getAlias()->toString();
                $imports[$alias] = true;
            }
        }
        $groupUses = $finder->findInstanceOf($ast, Node\Stmt\GroupUse::class);
        foreach ($groupUses as $gu) {
            assert($gu instanceof Node\Stmt\GroupUse);
            foreach ($gu->uses as $uu) {
                $type = $uu->type === Node\Stmt\Use_::TYPE_UNKNOWN ? $gu->type : $uu->type;
                if ($type !== Node\Stmt\Use_::TYPE_FUNCTION) continue;
                $imports[$uu->getAlias()->toString()] = true;
            }
        }
        return $imports;
    }

    /**
     * @param array<Node\Stmt> $ast
     * @return array<string, true>
     */
    private function collectDefinedFunctions(array $ast): array
    {
        $defined = [];
        $finder = new NodeFinder();
        $fns = $finder->findInstanceOf($ast, Node\Stmt\Function_::class);
        foreach ($fns as $fn) {
            assert($fn instanceof Node\Stmt\Function_);
            $defined[$fn->name->toString()] = true;
        }
        return $defined;
    }
}
