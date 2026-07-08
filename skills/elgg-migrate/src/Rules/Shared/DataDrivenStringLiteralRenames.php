<?php

declare(strict_types=1);

namespace ElggMigrate\Rules\Shared;

use ElggMigrate\AbstractRule;
use ElggMigrate\FileChange;
use ElggMigrate\Finding;
use ElggMigrate\RuleAnalysis;
use ElggMigrate\RuleResult;
use PhpParser\Node;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitorAbstract;

/**
 * Data-driven rewrite of whole PHP string LITERALS whose entire value matches a
 * renamed path (form/view/action). The map is loaded per target major from
 * references/string-renames.json. Because it matches a String_ node's complete
 * value (===), it never rewrites a substring, an identifier, or text inside a
 * comment — the failure mode of a naive grep/str_replace. This is what makes
 * automating the core form/action path renames safe.
 *
 * Concrete per-step subclasses supply only their target major.
 */
abstract class DataDrivenStringLiteralRenames extends AbstractRule
{
    /** The Elgg major this rule targets, e.g. '7.x'. Must key string-renames.json. */
    abstract protected function targetMajor(): string;

    /**
     * @return array<string, string> old exact string value → new value
     */
    protected function renameMap(): array
    {
        $path = __DIR__ . '/../../../references/string-renames.json';
        if (!is_file($path)) {
            return [];
        }
        $data = json_decode((string) file_get_contents($path), true);
        $major = $this->targetMajor();
        if (!is_array($data) || !isset($data[$major]) || !is_array($data[$major])) {
            return [];
        }
        $map = [];
        foreach ($data[$major] as $old => $new) {
            $map[(string) $old] = (string) $new;
        }
        return $map;
    }

    public function getId(): string
    {
        return 'string-renames-' . str_replace('.x', 'x', $this->targetMajor());
    }

    public function getDescription(): string
    {
        return sprintf(
            'Rewrite core form/view/action path string literals renamed in Elgg %s (data-driven, whole-literal match)',
            $this->targetMajor(),
        );
    }

    public function canAutomate(): bool
    {
        return true;
    }

    public function analyze(string $pluginPath): RuleAnalysis
    {
        $map = $this->renameMap();
        $findings = [];

        if (!empty($map)) {
            foreach ($this->findPhpFiles($pluginPath) as $file) {
                $relativePath = $this->relativePath($pluginPath, $file);
                $code = file_get_contents($file);
                if ($code === false) {
                    continue;
                }
                $ast = $this->parse($code);
                if ($ast === null) {
                    continue;
                }
                foreach ($this->collectStringLiterals($ast) as $node) {
                    if (isset($map[$node->value])) {
                        $findings[] = new Finding(
                            file: $relativePath,
                            line: $node->getLine(),
                            description: sprintf("'%s' → '%s'", $node->value, $map[$node->value]),
                            code: '',
                        );
                    }
                }
            }
        }

        $applicable = count($findings) > 0;

        return new RuleAnalysis(
            ruleId: $this->getId(),
            applicable: $applicable,
            findings: $findings,
            summary: $applicable
                ? sprintf('Found %d renamed path string literal(s) to update', count($findings))
                : 'No renamed path string literals found',
        );
    }

    public function apply(string $pluginPath): RuleResult
    {
        $map = $this->renameMap();
        $changes = [];

        if (!empty($map)) {
            foreach ($this->findPhpFiles($pluginPath) as $file) {
                $relativePath = $this->relativePath($pluginPath, $file);
                $code = file_get_contents($file);
                if ($code === false) {
                    continue;
                }
                $ast = $this->parse($code);
                if ($ast === null || empty($this->matchingLiterals($ast, $map))) {
                    continue;
                }

                $result = $this->transformFile($code, $map);
                if ($result['transformed']) {
                    file_put_contents($file, $result['code']);
                    $changes[] = new FileChange(
                        file: $relativePath,
                        type: 'modified',
                        description: sprintf('Rewrote form/action path string literals renamed in Elgg %s', $this->targetMajor()),
                    );
                }
            }
        }

        return new RuleResult(
            ruleId: $this->getId(),
            success: true,
            changes: $changes,
        );
    }

    /**
     * @param array<Node> $ast
     * @return array<Node\Scalar\String_>
     */
    private function collectStringLiterals(array $ast): array
    {
        $finder = new \PhpParser\NodeFinder();
        return $finder->findInstanceOf($ast, Node\Scalar\String_::class);
    }

    /**
     * @param array<Node> $ast
     * @param array<string, string> $map
     * @return array<Node\Scalar\String_>
     */
    private function matchingLiterals(array $ast, array $map): array
    {
        return array_values(array_filter(
            $this->collectStringLiterals($ast),
            fn(Node\Scalar\String_ $n) => isset($map[$n->value]),
        ));
    }

    /**
     * @param array<string, string> $map
     * @return array{transformed: bool, code: string}
     */
    private function transformFile(string $originalCode, array $map): array
    {
        $parsed = $this->parsePreserving($originalCode);
        if ($parsed === null) {
            return ['transformed' => false, 'code' => $originalCode];
        }

        $traverser = new NodeTraverser();
        $visitor = new class($map) extends NodeVisitorAbstract {
            private bool $changed = false;

            /** @param array<string, string> $map */
            public function __construct(private array $map) {}

            public function leaveNode(Node $node): int|Node|null
            {
                if ($node instanceof Node\Scalar\String_ && isset($this->map[$node->value])) {
                    $new = $this->map[$node->value];
                    // Replace with a fresh node so the printer re-renders the literal.
                    $replacement = new Node\Scalar\String_($new, $node->getAttributes());
                    $this->changed = true;
                    return $replacement;
                }
                return null;
            }

            public function hasChanged(): bool
            {
                return $this->changed;
            }
        };

        $traverser->addVisitor($visitor);
        $parsed['new'] = $traverser->traverse($parsed['new']);

        if (!$visitor->hasChanged()) {
            return ['transformed' => false, 'code' => $originalCode];
        }

        return [
            'transformed' => true,
            'code' => $this->printPreserving($parsed['new'], $parsed['old'], $parsed['tokens']),
        ];
    }
}
