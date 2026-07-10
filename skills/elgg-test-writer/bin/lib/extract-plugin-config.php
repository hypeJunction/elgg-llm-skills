<?php

declare(strict_types=1);

/**
 * extract-plugin-config.php — statically read a plugin's elgg-plugin.php and
 * emit a JSON descriptor of its registered actions, entities, and events.
 *
 * Usage:
 *   php extract-plugin-config.php <plugin-dir>
 *
 * Output (stdout): JSON object {
 *   "actions": ["myplugin/save", ...],
 *   "entities": [{"type":"object","subtype":"foo","class":"\\MyPlugin\\Foo"}],
 *   "events": {"create": ["object","user"], ...}
 * }
 *
 * Uses nikic/php-parser to extract the top-level `return [...]` array literal
 * without executing the manifest. Plugin code is never run, so side effects,
 * undefined classes, and missing services don't matter.
 *
 * Exits 0 on success, 1 if elgg-plugin.php is missing/unparseable or doesn't
 * end in a static return-array.
 */

// Locate a usable autoloader: prefer this skill's vendor, fall back to the
// sibling elgg-migrate skill (they share nikic/php-parser).
$autoloads = [
    __DIR__ . '/../../vendor/autoload.php',
    __DIR__ . '/../../../elgg-migrate/vendor/autoload.php',
];
$loaded = false;
foreach ($autoloads as $candidate) {
    if (is_file($candidate)) {
        require $candidate;
        $loaded = true;
        break;
    }
}
if (!$loaded) {
    fwrite(STDERR, "no vendor/autoload.php found — run composer install in skills/elgg-test-writer or skills/elgg-migrate\n");
    exit(1);
}

use PhpParser\Node;
use PhpParser\ParserFactory;

if ($argc < 2) {
    fwrite(STDERR, "usage: php extract-plugin-config.php <plugin-dir>\n");
    exit(1);
}

$pluginDir = rtrim((string) $argv[1], '/');
$manifest = $pluginDir . '/elgg-plugin.php';

if (!is_file($manifest)) {
    fwrite(STDERR, "no elgg-plugin.php at {$manifest}\n");
    exit(1);
}

$code = file_get_contents($manifest);
if ($code === false) {
    fwrite(STDERR, "could not read {$manifest}\n");
    exit(1);
}

$parser = (new ParserFactory())->createForHostVersion();
try {
    $ast = $parser->parse($code);
} catch (\Throwable $e) {
    fwrite(STDERR, "parse error in {$manifest}: " . $e->getMessage() . "\n");
    exit(1);
}
if ($ast === null) {
    fwrite(STDERR, "could not parse {$manifest}\n");
    exit(1);
}

// Find the top-level `return [...]` statement.
$returnArray = null;
foreach ($ast as $stmt) {
    if ($stmt instanceof Node\Stmt\Return_ && $stmt->expr instanceof Node\Expr\Array_) {
        $returnArray = $stmt->expr;
        break;
    }
}
if ($returnArray === null) {
    fwrite(STDERR, "elgg-plugin.php has no top-level `return [...]` array literal\n");
    exit(1);
}

$out = [
    'actions' => [],
    'entities' => [],
    'events' => new \stdClass(),
];
/** @var array<int,array{line:int,reason:string}> entity rows we could not resolve statically */
$unresolvedEntities = [];

foreach ($returnArray->items as $item) {
    if (!$item instanceof Node\ArrayItem || $item->key === null) continue;

    $key = stringValue($item->key);
    if ($key === null) continue;

    if ($key === 'actions' && $item->value instanceof Node\Expr\Array_) {
        $out['actions'] = collectActionKeys($item->value);
        sort($out['actions']);
    } elseif ($key === 'entities' && $item->value instanceof Node\Expr\Array_) {
        $out['entities'] = collectEntities($item->value, $unresolvedEntities);
    } elseif ($key === 'events' && $item->value instanceof Node\Expr\Array_) {
        $out['events'] = collectEvents($item->value);
    }
}

if ($unresolvedEntities) {
    $out['entities_unresolved'] = $unresolvedEntities;
    fwrite(STDERR, "WARNING: " . count($unresolvedEntities) . " entity row(s) in elgg-plugin.php could not be resolved statically\n");
    foreach ($unresolvedEntities as $u) {
        fwrite(STDERR, "  line {$u['line']}: {$u['reason']}\n");
    }
    fwrite(STDERR, "  A class CONSTANT (Foo::SUBTYPE) autoloads at generateEntities() time and fatals;\n");
    fwrite(STDERR, "  use a string literal, or Foo::class for the 'class' key (compile-time, never autoloads).\n");
    fwrite(STDERR, "  These entities are ABSENT from the generated tests.\n");
}

echo json_encode($out, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";
exit(0);

/** ---------- helpers ---------- */

function stringValue(Node $node): ?string
{
    if ($node instanceof Node\Scalar\String_) {
        return $node->value;
    }
    if ($node instanceof Node\Expr\ClassConstFetch
        && $node->class instanceof Node\Name
        && $node->name instanceof Node\Identifier
        && $node->name->toString() === 'class'
    ) {
        return '\\' . ltrim($node->class->toString(), '\\');
    }
    return null;
}

/** @return array<int,string> */
function collectActionKeys(Node\Expr\Array_ $arr): array
{
    $keys = [];
    foreach ($arr->items as $it) {
        if (!$it instanceof Node\ArrayItem || $it->key === null) continue;
        $k = stringValue($it->key);
        if ($k !== null) $keys[] = $k;
    }
    return $keys;
}

/** @return array<int,array<string,string>> */
/**
 * @param array<int,array{line:int,reason:string}> $unresolved  filled with rows we
 *        could not statically resolve, so the caller can WARN instead of silently
 *        emitting a descriptor that omits them.
 */
function collectEntities(Node\Expr\Array_ $arr, array &$unresolved = []): array
{
    $rows = [];
    foreach ($arr->items as $it) {
        if (!$it instanceof Node\ArrayItem) continue;
        if (!$it->value instanceof Node\Expr\Array_) continue;
        $row = ['type' => '', 'subtype' => '', 'class' => ''];
        $bad = [];
        foreach ($it->value->items as $sub) {
            if (!$sub instanceof Node\ArrayItem || $sub->key === null) continue;
            $sk = stringValue($sub->key);
            if (!in_array($sk, ['type', 'subtype', 'class'], true)) continue;
            $sv = stringValue($sub->value);
            if ($sv !== null) {
                $row[$sk] = $sv;
            } else {
                $bad[] = $sk . ' => ' . describeNode($sub->value);
            }
        }
        if ($row['type'] !== '' && $row['subtype'] !== '') {
            $rows[] = $row;
            continue;
        }
        // Dropping this row silently would leave the scaffolded BaselineTest
        // asserting nothing about an entity binding — and a class CONSTANT is
        // exactly the construct that fatals at generateEntities() time, so these
        // are the rows that most need coverage.
        $unresolved[] = [
            'line' => $it->getStartLine(),
            'reason' => $bad
                ? 'not a literal: ' . implode(', ', $bad)
                : 'missing a literal type and/or subtype',
        ];
    }
    return $rows;
}

/** Short, human-readable shape of an unresolvable node, for the warning text. */
function describeNode(Node $node): string
{
    if ($node instanceof Node\Expr\ClassConstFetch && $node->class instanceof Node\Name) {
        $const = $node->name instanceof Node\Identifier ? $node->name->toString() : '?';
        return '\\' . ltrim($node->class->toString(), '\\') . '::' . $const;
    }
    if ($node instanceof Node\Expr\ConstFetch) {
        return $node->name->toString();
    }
    if ($node instanceof Node\Expr\Variable && is_string($node->name)) {
        return '$' . $node->name;
    }
    return $node->getType();
}

/** @return array<string, array<int,string>> */
function collectEvents(Node\Expr\Array_ $arr): array
{
    $events = [];
    foreach ($arr->items as $it) {
        if (!$it instanceof Node\ArrayItem || $it->key === null) continue;
        $name = stringValue($it->key);
        if ($name === null) continue;
        if (!$it->value instanceof Node\Expr\Array_) continue;

        $types = [];
        foreach ($it->value->items as $sub) {
            if (!$sub instanceof Node\ArrayItem || $sub->key === null) continue;
            $t = stringValue($sub->key);
            if ($t !== null) $types[] = $t;
        }
        $events[$name] = $types;
    }
    return $events;
}
