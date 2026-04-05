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

/**
 * Generates elgg-plugin.php from start.php registrations.
 *
 * Scans start.php for:
 * - elgg_register_action() → 'actions' key
 * - elgg_register_route() → 'routes' key
 * - elgg_set_entity_class() → 'entities' key
 * - elgg_register_widget_type() → 'widgets' key
 * - elgg_register_plugin_hook_handler() → 'hooks' key
 * - elgg_register_event_handler() → 'events' key
 *
 * Generates elgg-plugin.php with all extracted registrations.
 * Does NOT remove start.php — that's left for manual cleanup
 * since some logic (like conditional registrations) can't be
 * mechanically moved.
 */
final class GenerateElggPluginPhp extends AbstractRule
{
    public function getId(): string
    {
        return 'generate-elgg-plugin-php';
    }

    public function getDescription(): string
    {
        return 'Generate elgg-plugin.php from start.php registrations';
    }

    public function canAutomate(): bool
    {
        return true;
    }

    public function analyze(string $pluginPath): RuleAnalysis
    {
        $findings = [];

        // Check if elgg-plugin.php already exists
        if (is_file($pluginPath . '/elgg-plugin.php')) {
            return new RuleAnalysis(
                ruleId: $this->getId(),
                applicable: false,
                findings: [],
                summary: 'elgg-plugin.php already exists',
            );
        }

        // Check if start.php exists
        if (!is_file($pluginPath . '/start.php')) {
            return new RuleAnalysis(
                ruleId: $this->getId(),
                applicable: false,
                findings: [],
                summary: 'No start.php found',
            );
        }

        $extracted = $this->extractRegistrations($pluginPath);

        if (!empty($extracted['actions'])) {
            $findings[] = new Finding('start.php', 0, count($extracted['actions']) . ' action(s) to move to elgg-plugin.php', '');
        }
        if (!empty($extracted['routes'])) {
            $findings[] = new Finding('start.php', 0, count($extracted['routes']) . ' route(s) to move to elgg-plugin.php', '');
        }
        if (!empty($extracted['entities'])) {
            $findings[] = new Finding('start.php', 0, count($extracted['entities']) . ' entity class(es) to move to elgg-plugin.php', '');
        }
        if (!empty($extracted['hooks'])) {
            $findings[] = new Finding('start.php', 0, count($extracted['hooks']) . ' hook(s) to move to elgg-plugin.php', '');
        }
        if (!empty($extracted['events'])) {
            $findings[] = new Finding('start.php', 0, count($extracted['events']) . ' event(s) to move to elgg-plugin.php', '');
        }

        // Also check activate.php for entity registrations
        if (is_file($pluginPath . '/activate.php')) {
            $activateEntities = $this->extractEntityClassesFromActivate($pluginPath);
            if (!empty($activateEntities)) {
                $findings[] = new Finding('activate.php', 0, count($activateEntities) . ' entity class(es) in activate.php', '');
            }
        }

        $applicable = count($findings) > 0;

        return new RuleAnalysis(
            ruleId: $this->getId(),
            applicable: $applicable,
            findings: $findings,
            summary: $applicable
                ? 'Found registrations to extract into elgg-plugin.php'
                : 'No registrations found to extract',
        );
    }

    public function apply(string $pluginPath): RuleResult
    {
        if (is_file($pluginPath . '/elgg-plugin.php')) {
            return new RuleResult(
                ruleId: $this->getId(),
                success: true,
                changes: [],
                warnings: ['elgg-plugin.php already exists — skipping'],
            );
        }

        $extracted = $this->extractRegistrations($pluginPath);
        $activateEntities = $this->extractEntityClassesFromActivate($pluginPath);

        // Merge activate.php entities with start.php entities
        $allEntities = array_merge($extracted['entities'], $activateEntities);

        $changes = [];
        $warnings = [];

        // Build elgg-plugin.php content
        $config = [];

        if (!empty($allEntities)) {
            $config['entities'] = $allEntities;
        }

        if (!empty($extracted['actions'])) {
            $config['actions'] = $extracted['actions'];
        }

        if (!empty($extracted['routes'])) {
            $config['routes'] = $extracted['routes'];
        }

        if (!empty($extracted['hooks'])) {
            $config['hooks'] = $extracted['hooks'];
        }

        if (!empty($extracted['events'])) {
            // Filter out init,system (that's the bootstrap)
            $config['events'] = array_filter($extracted['events'], function ($e) {
                return !($e['event'] === 'init' && $e['type'] === 'system');
            });
            if (empty($config['events'])) {
                unset($config['events']);
            }
        }

        if (empty($config)) {
            return new RuleResult(
                ruleId: $this->getId(),
                success: true,
                changes: [],
                warnings: ['No registrations found to extract into elgg-plugin.php'],
            );
        }

        $phpContent = $this->generateElggPluginPhp($config);
        file_put_contents($pluginPath . '/elgg-plugin.php', $phpContent);

        $changes[] = new FileChange(
            file: 'elgg-plugin.php',
            type: 'created',
            description: 'Generated from start.php registrations',
        );

        $warnings[] = 'Review elgg-plugin.php and remove corresponding registrations from start.php';
        $warnings[] = 'Hook/event handlers with complex logic should use a Bootstrap class instead';

        return new RuleResult(
            ruleId: $this->getId(),
            success: true,
            changes: $changes,
            warnings: $warnings,
        );
    }

    /**
     * Extract registrations from start.php using AST analysis.
     */
    private function extractRegistrations(string $pluginPath): array
    {
        $result = [
            'actions' => [],
            'routes' => [],
            'entities' => [],
            'hooks' => [],
            'events' => [],
        ];

        $startPhp = $pluginPath . '/start.php';
        if (!is_file($startPhp)) {
            return $result;
        }

        $code = file_get_contents($startPhp);
        $ast = $this->parse($code);
        if ($ast === null) {
            return $result;
        }

        $finder = $this->finder();
        $printer = $this->printer();

        // Find all function calls
        $calls = $finder->find($ast, fn(Node $n) => $n instanceof Node\Expr\FuncCall && $n->name instanceof Node\Name);

        foreach ($calls as $call) {
            /** @var Node\Expr\FuncCall $call */
            $funcName = $call->name->toString();

            switch ($funcName) {
                case 'elgg_register_action':
                    $action = $this->extractAction($call);
                    if ($action) {
                        $result['actions'][$action['name']] = $action['config'];
                    }
                    break;

                case 'elgg_register_route':
                    $route = $this->extractRoute($call, $printer);
                    if ($route) {
                        $result['routes'][$route['name']] = $route['config'];
                    }
                    break;

                case 'elgg_set_entity_class':
                    $entity = $this->extractEntityClass($call);
                    if ($entity) {
                        $result['entities'][] = $entity;
                    }
                    break;

                case 'elgg_register_plugin_hook_handler':
                    $hook = $this->extractHook($call, $printer);
                    if ($hook) {
                        $result['hooks'][] = $hook;
                    }
                    break;

                case 'elgg_register_event_handler':
                    $event = $this->extractEvent($call, $printer);
                    if ($event) {
                        $result['events'][] = $event;
                    }
                    break;
            }
        }

        return $result;
    }

    private function extractAction(Node\Expr\FuncCall $call): ?array
    {
        if (!isset($call->args[0]) || !$call->args[0]->value instanceof Node\Scalar\String_) {
            return null;
        }

        $name = $call->args[0]->value->value;
        $config = [];

        // Check for access level (3rd arg)
        if (isset($call->args[2]) && $call->args[2]->value instanceof Node\Scalar\String_) {
            $access = $call->args[2]->value->value;
            if ($access !== 'logged_in') {
                $config['access'] = $access;
            }
        }

        return ['name' => $name, 'config' => $config];
    }

    private function extractRoute(Node\Expr\FuncCall $call, $printer): ?array
    {
        if (!isset($call->args[0]) || !$call->args[0]->value instanceof Node\Scalar\String_) {
            return null;
        }

        $name = $call->args[0]->value->value;

        // Try to extract the route config array
        if (isset($call->args[1]) && $call->args[1]->value instanceof Node\Expr\Array_) {
            $config = $this->arrayNodeToPhp($call->args[1]->value);
            return ['name' => $name, 'config' => $config];
        }

        return ['name' => $name, 'config' => []];
    }

    private function extractEntityClass(Node\Expr\FuncCall $call): ?array
    {
        $type = isset($call->args[0]) && $call->args[0]->value instanceof Node\Scalar\String_
            ? $call->args[0]->value->value : null;
        $subtype = isset($call->args[1]) && $call->args[1]->value instanceof Node\Scalar\String_
            ? $call->args[1]->value->value : null;

        if (!$type || !$subtype) {
            return null;
        }

        // Extract class name
        $classExpr = $call->args[2]->value ?? null;
        $class = null;

        if ($classExpr instanceof Node\Scalar\String_) {
            $class = $classExpr->value;
        } elseif ($classExpr instanceof Node\Expr\ClassConstFetch
            && $classExpr->name instanceof Node\Identifier
            && $classExpr->name->name === 'class'
        ) {
            $class = $this->printer()->prettyPrintExpr($classExpr);
        }

        if (!$class) {
            return null;
        }

        return [
            'type' => $type,
            'subtype' => $subtype,
            'class' => $class,
        ];
    }

    private function extractHook(Node\Expr\FuncCall $call, $printer): ?array
    {
        $hook = isset($call->args[0]) && $call->args[0]->value instanceof Node\Scalar\String_
            ? $call->args[0]->value->value : null;
        $type = isset($call->args[1]) && $call->args[1]->value instanceof Node\Scalar\String_
            ? $call->args[1]->value->value : null;

        if (!$hook || !$type) {
            return null;
        }

        // Skip closures — they can't be serialized in elgg-plugin.php
        if (isset($call->args[2]) && $call->args[2]->value instanceof Node\Expr\Closure) {
            return null;
        }

        $callback = isset($call->args[2]) ? $printer->prettyPrintExpr($call->args[2]->value) : null;

        return ['hook' => $hook, 'type' => $type, 'callback' => $callback];
    }

    private function extractEvent(Node\Expr\FuncCall $call, $printer): ?array
    {
        $event = isset($call->args[0]) && $call->args[0]->value instanceof Node\Scalar\String_
            ? $call->args[0]->value->value : null;
        $type = isset($call->args[1]) && $call->args[1]->value instanceof Node\Scalar\String_
            ? $call->args[1]->value->value : null;

        if (!$event || !$type) {
            return null;
        }

        // Skip closures — they can't be serialized in elgg-plugin.php
        if (isset($call->args[2]) && $call->args[2]->value instanceof Node\Expr\Closure) {
            return null;
        }

        $callback = isset($call->args[2]) ? $printer->prettyPrintExpr($call->args[2]->value) : null;

        return ['event' => $event, 'type' => $type, 'callback' => $callback];
    }

    /**
     * Extract entity class registrations from activate.php.
     */
    private function extractEntityClassesFromActivate(string $pluginPath): array
    {
        $activatePath = $pluginPath . '/activate.php';
        if (!is_file($activatePath)) {
            return [];
        }

        $code = file_get_contents($activatePath);
        $ast = $this->parse($code);
        if ($ast === null) {
            return [];
        }

        $entities = [];
        $finder = $this->finder();

        $calls = $finder->find($ast, function (Node $n) {
            return $n instanceof Node\Expr\FuncCall
                && $n->name instanceof Node\Name
                && $n->name->toString() === 'elgg_set_entity_class';
        });

        foreach ($calls as $call) {
            $entity = $this->extractEntityClass($call);
            if ($entity) {
                $entities[] = $entity;
            }
        }

        return $entities;
    }

    /**
     * Convert a PHP-Parser Array_ node to a PHP array (simple values only).
     */
    private function arrayNodeToPhp(Node\Expr\Array_ $node): array
    {
        $result = [];
        foreach ($node->items as $item) {
            if (!$item) continue;

            $key = null;
            if ($item->key instanceof Node\Scalar\String_) {
                $key = $item->key->value;
            }

            $value = $this->nodeToPhpValue($item->value);

            if ($key !== null) {
                $result[$key] = $value;
            } else {
                $result[] = $value;
            }
        }
        return $result;
    }

    private function nodeToPhpValue(Node\Expr $node): mixed
    {
        if ($node instanceof Node\Scalar\String_) {
            return $node->value;
        }
        if ($node instanceof Node\Scalar\Int_) {
            return $node->value;
        }
        if ($node instanceof Node\Expr\ConstFetch) {
            $name = $node->name->toString();
            return match ($name) {
                'true' => true,
                'false' => false,
                'null' => null,
                default => $name, // Keep constant name as string
            };
        }
        if ($node instanceof Node\Expr\Array_) {
            return $this->arrayNodeToPhp($node);
        }
        // For complex expressions, return the printed code
        return $this->printer()->prettyPrintExpr($node);
    }

    /**
     * Generate the elgg-plugin.php file content.
     */
    private function generateElggPluginPhp(array $config): string
    {
        $lines = ["<?php\n", "return ["];

        // Entities
        if (!empty($config['entities'])) {
            $lines[] = "\t'entities' => [";
            foreach ($config['entities'] as $entity) {
                $class = $entity['class'];
                // Convert Class::class style
                if (!str_contains($class, '\\') && !str_contains($class, '::')) {
                    $class = "\\{$class}::class";
                } elseif (!str_ends_with($class, '::class')) {
                    $class = "\\{$class}::class";
                }
                $lines[] = "\t\t[";
                $lines[] = "\t\t\t'type' => '{$entity['type']}',";
                $lines[] = "\t\t\t'subtype' => '{$entity['subtype']}',";
                $lines[] = "\t\t\t'class' => {$class},";
                $lines[] = "\t\t],";
            }
            $lines[] = "\t],";
            $lines[] = "";
        }

        // Actions
        if (!empty($config['actions'])) {
            $lines[] = "\t'actions' => [";
            foreach ($config['actions'] as $name => $actionConfig) {
                if (empty($actionConfig)) {
                    $lines[] = "\t\t'{$name}' => [],";
                } else {
                    $lines[] = "\t\t'{$name}' => [";
                    foreach ($actionConfig as $k => $v) {
                        $lines[] = "\t\t\t'{$k}' => " . var_export($v, true) . ",";
                    }
                    $lines[] = "\t\t],";
                }
            }
            $lines[] = "\t],";
            $lines[] = "";
        }

        // Routes
        if (!empty($config['routes'])) {
            $lines[] = "\t'routes' => [";
            foreach ($config['routes'] as $name => $routeConfig) {
                $lines[] = "\t\t'{$name}' => [";
                foreach ($routeConfig as $k => $v) {
                    if (is_array($v)) {
                        $lines[] = "\t\t\t'{$k}' => [";
                        foreach ($v as $vk => $vv) {
                            if (is_int($vk)) {
                                $lines[] = "\t\t\t\t" . var_export($vv, true) . ",";
                            } else {
                                $lines[] = "\t\t\t\t'{$vk}' => " . var_export($vv, true) . ",";
                            }
                        }
                        $lines[] = "\t\t\t],";
                    } else {
                        $lines[] = "\t\t\t'{$k}' => " . var_export($v, true) . ",";
                    }
                }
                $lines[] = "\t\t],";
            }
            $lines[] = "\t],";
            $lines[] = "";
        }

        // Hooks — group by hook name, then by type
        if (!empty($config['hooks'])) {
            $grouped = [];
            foreach ($config['hooks'] as $hook) {
                $grouped[$hook['hook']][$hook['type']][] = $hook['callback'];
            }
            $lines[] = "\t'hooks' => [";
            foreach ($grouped as $hookName => $types) {
                $lines[] = "\t\t'{$hookName}' => [";
                foreach ($types as $typeName => $callbacks) {
                    $lines[] = "\t\t\t'{$typeName}' => [";
                    foreach ($callbacks as $cb) {
                        $lines[] = "\t\t\t\t{$cb},";
                    }
                    $lines[] = "\t\t\t],";
                }
                $lines[] = "\t\t],";
            }
            $lines[] = "\t],";
            $lines[] = "";
        }

        // Events (excluding init,system) — group by event name, then by type
        if (!empty($config['events'])) {
            $grouped = [];
            foreach ($config['events'] as $event) {
                $grouped[$event['event']][$event['type']][] = $event['callback'];
            }
            $lines[] = "\t'events' => [";
            foreach ($grouped as $eventName => $types) {
                $lines[] = "\t\t'{$eventName}' => [";
                foreach ($types as $typeName => $callbacks) {
                    $lines[] = "\t\t\t'{$typeName}' => [";
                    foreach ($callbacks as $cb) {
                        $lines[] = "\t\t\t\t{$cb},";
                    }
                    $lines[] = "\t\t\t],";
                }
                $lines[] = "\t\t],";
            }
            $lines[] = "\t],";
        }

        $lines[] = "];";

        return implode("\n", $lines) . "\n";
    }
}
