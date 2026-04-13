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
 * Removes redundant require_once for Elgg's vendor/autoload.php.
 *
 * Some plugins include the Elgg root autoloader:
 *   require_once dirname(dirname(dirname(__FILE__))) . '/vendor/autoload.php';
 *
 * This is redundant because Elgg loads its autoloader before start.php runs.
 * It also breaks when the plugin is symlinked from a different directory.
 *
 * This rule removes require_once statements that navigate UP from the plugin
 * directory to load vendor/autoload.php. It does NOT remove plugin-local
 * autoloaders (e.g., __DIR__ . '/vendor/autoload.php').
 */
final class RemoveVendorAutoload extends AbstractRule
{
    public function getId(): string
    {
        return 'remove-vendor-autoload';
    }

    public function getDescription(): string
    {
        return 'Remove redundant require_once for Elgg root vendor/autoload.php';
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

            // Look for require_once with dirname() chains leading to vendor/autoload.php
            if (preg_match('/require_once\s+dirname\s*\(.*vendor\/autoload\.php/s', $code)) {
                $lineNum = 0;
                foreach (explode("\n", $code) as $i => $line) {
                    if (str_contains($line, 'vendor/autoload.php') && str_contains($line, 'dirname')) {
                        $lineNum = $i + 1;
                        break;
                    }
                }

                $findings[] = new Finding(
                    file: $relativePath,
                    line: $lineNum,
                    description: 'Redundant vendor/autoload.php require — Elgg loads its autoloader before start.php',
                    code: trim($code),
                );
            }
        }

        $applicable = count($findings) > 0;

        return new RuleAnalysis(
            ruleId: $this->getId(),
            applicable: $applicable,
            findings: $findings,
            summary: $applicable
                ? sprintf('Found %d redundant vendor/autoload.php require(s)', count($findings))
                : 'No redundant autoload requires found',
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

            $result = $this->transformFile($ast, $code);

            if ($result['transformed']) {
                file_put_contents($file, $result['code']);
                $changes[] = new FileChange(
                    file: $relativePath,
                    type: 'modified',
                    description: 'Removed redundant vendor/autoload.php require',
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
     * @return array{transformed: bool, code: string, warnings: array<string>}
     */
    private function transformFile(array $ast, string $originalCode): array
    {
        $warnings = [];

        $traverser = new NodeTraverser();
        $visitor = new class($warnings) extends NodeVisitorAbstract {
            private bool $changed = false;

            public function __construct(private array &$warnings) {}

            public function leaveNode(Node $node): int|Node|null
            {
                if (!$node instanceof Node\Stmt\Expression) return null;

                // Match: require_once <expr containing dirname and vendor/autoload.php>
                if ($node->expr instanceof Node\Expr\Include_
                    && $node->expr->type === Node\Expr\Include_::TYPE_REQUIRE_ONCE
                ) {
                    $printer = new \PhpParser\PrettyPrinter\Standard();
                    $exprCode = $printer->prettyPrintExpr($node->expr->expr);

                    // Check if it navigates UP (dirname) to vendor/autoload.php
                    if (str_contains($exprCode, 'dirname') && str_contains($exprCode, 'vendor/autoload.php')) {
                        // Don't remove plugin-local autoloaders (__DIR__ . '/vendor/autoload.php')
                        if (str_contains($exprCode, "__DIR__ . '/vendor") || str_contains($exprCode, '__DIR__ . "/vendor')) {
                            return null; // Keep plugin-local autoloader
                        }

                        $this->warnings[] = 'Removed redundant vendor/autoload.php require (Elgg autoloader already loaded)';
                        $this->changed = true;
                        return NodeTraverser::REMOVE_NODE;
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
        $newAst = $traverser->traverse($ast);

        if (!$visitor->hasChanged()) {
            return ['transformed' => false, 'code' => $originalCode, 'warnings' => $warnings];
        }

        return ['transformed' => true, 'code' => $this->print($newAst), 'warnings' => $warnings];
    }
}
