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
 * Replaces magic property setters for protected entity attributes.
 *
 * In Elgg 4.0, type/subtype/enabled/admin/banned can no longer be set
 * via $entity->attr = value. Each has a specific setter method.
 */
final class EntityAttributeSetters extends AbstractRule
{
    /**
     * Properties that are no longer magic-settable and their handling.
     *
     * 'transform' = can be auto-transformed
     * 'warn' = needs manual review
     */
    public const ATTRIBUTES = [
        'subtype' => ['action' => 'transform', 'method' => 'setSubtype'],
        'enabled' => ['action' => 'transform'], // enable()/disable() based on value
        'type'    => ['action' => 'warn', 'note' => 'type cannot be changed after creation'],
        'admin'   => ['action' => 'warn', 'note' => 'use makeAdmin()/removeAdmin()'],
        'banned'  => ['action' => 'warn', 'note' => 'use ban()/unban()'],
    ];

    public function getId(): string
    {
        return 'entity-attribute-setters';
    }

    public function getDescription(): string
    {
        return 'Replace magic setters for protected entity attributes';
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

            $assigns = $this->findAttributeAssignments($ast);
            $printer = $this->printer();

            foreach ($assigns as [$assign, $propName]) {
                $info = self::ATTRIBUTES[$propName];
                $desc = match ($info['action']) {
                    'transform' => "->{$propName} = ... → setter method",
                    'warn' => "->{$propName} = ... — {$info['note']}",
                };

                $findings[] = new Finding(
                    file: $relativePath,
                    line: $assign->getLine(),
                    description: $desc,
                    code: $printer->prettyPrintExpr($assign),
                );
            }
        }

        $applicable = count($findings) > 0;

        return new RuleAnalysis(
            ruleId: $this->getId(),
            applicable: $applicable,
            findings: $findings,
            summary: $applicable
                ? sprintf('Found %d protected attribute assignment(s)', count($findings))
                : 'No protected attribute assignments found',
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

            $assigns = $this->findAttributeAssignments($ast);
            if (empty($assigns)) continue;

            $result = $this->transformFile($ast, $code);

            if ($result['transformed']) {
                file_put_contents($file, $result['code']);
                $changes[] = new FileChange(
                    file: $relativePath,
                    type: 'modified',
                    description: 'Replaced protected attribute assignments with setter methods',
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
     * @return array<array{Node\Expr\Assign, string}>
     */
    private function findAttributeAssignments(array $ast): array
    {
        $results = [];
        $targetProps = array_keys(self::ATTRIBUTES);

        $this->finder()->find($ast, function (Node $node) use ($targetProps, &$results) {
            if ($node instanceof Node\Expr\Assign
                && $node->var instanceof Node\Expr\PropertyFetch
                && $node->var->name instanceof Node\Identifier
                && in_array($node->var->name->name, $targetProps, true)
            ) {
                $results[] = [$node, $node->var->name->name];
            }
            return false;
        });

        return $results;
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
            public bool $changed = false;

            public function __construct(private array &$warnings) {}

            public function leaveNode(Node $node): int|Node|null
            {
                if (!$node instanceof Node\Expr\Assign
                    || !$node->var instanceof Node\Expr\PropertyFetch
                    || !$node->var->name instanceof Node\Identifier
                ) {
                    return null;
                }

                $propName = $node->var->name->name;
                if (!isset(EntityAttributeSetters::ATTRIBUTES[$propName])) {
                    return null;
                }

                $info = EntityAttributeSetters::ATTRIBUTES[$propName];
                $object = $node->var->var;
                $value = $node->expr;

                if ($info['action'] === 'warn') {
                    $this->warnings[] = "->{$propName} = ... — {$info['note']}";
                    return null;
                }

                // Transform ->subtype = $val → ->setSubtype($val)
                if ($propName === 'subtype') {
                    $this->changed = true;
                    return new Node\Expr\MethodCall(
                        $object,
                        'setSubtype',
                        [new Node\Arg($value)],
                    );
                }

                // Transform ->enabled = 'yes'/'no' → ->enable()/->disable()
                if ($propName === 'enabled') {
                    if ($value instanceof Node\Scalar\String_) {
                        $this->changed = true;
                        $method = $value->value === 'yes' ? 'enable' : 'disable';
                        return new Node\Expr\MethodCall($object, $method);
                    }
                    // Non-literal value — warn
                    $this->warnings[] = "->enabled = <dynamic> — use enable()/disable() manually";
                    return null;
                }

                return null;
            }
        };

        $traverser->addVisitor($visitor);
        $newAst = $traverser->traverse($ast);

        if (!$visitor->changed) {
            return ['transformed' => false, 'code' => $originalCode, 'warnings' => $warnings];
        }

        return ['transformed' => true, 'code' => $this->print($newAst), 'warnings' => $warnings];
    }
}
