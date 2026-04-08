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
 * Rewrites hook/event handler signatures from old multi-arg format to Elgg 4.x single-arg format.
 *
 * In Elgg 4.x, handlers registered via elgg-plugin.php receive a single object:
 * - Hooks: \Elgg\Hook $hook  (was: $hook, $type, $return, $params)
 * - Events: \Elgg\Event $event  (was: $event, $type, $entity)
 *
 * This rule:
 * 1. Parses elgg-plugin.php to find all handler references under 'hooks' and 'events' keys
 * 2. Locates each handler method in the plugin's PHP files
 * 3. Rewrites the method signature and body references
 */
final class HookCallbackSignatures extends AbstractRule
{
    public function getId(): string
    {
        return 'hook-callback-signatures-4x';
    }

    public function getDescription(): string
    {
        return 'Rewrite hook/event handler signatures to Elgg 4.x single-arg format';
    }

    public function canAutomate(): bool
    {
        return true;
    }

    public function analyze(string $pluginPath): RuleAnalysis
    {
        $findings = [];

        $handlers = $this->extractHandlerReferences($pluginPath);
        if (empty($handlers)) {
            return new RuleAnalysis(
                ruleId: $this->getId(),
                applicable: false,
                findings: [],
                summary: 'No hook/event handlers found in elgg-plugin.php',
            );
        }

        foreach ($handlers as $handler) {
            $location = $this->findHandlerMethod($pluginPath, $handler['class'], $handler['method']);
            if (!$location) {
                continue;
            }

            // Check if handler already has the new signature
            if ($this->hasNewSignature($location['code'], $handler['method'], $handler['kind'])) {
                continue;
            }

            if ($this->hasOldSignature($location['code'], $handler['method'], $handler['kind'])) {
                $typeHint = $handler['kind'] === 'hook' ? '\\Elgg\\Hook' : '\\Elgg\\Event';
                $findings[] = new Finding(
                    file: $location['relpath'],
                    line: $location['line'],
                    description: "{$handler['class']}::{$handler['method']}() has old {$handler['kind']} signature — needs {$typeHint}",
                    code: $location['signature'],
                );
            }
        }

        return new RuleAnalysis(
            ruleId: $this->getId(),
            applicable: count($findings) > 0,
            findings: $findings,
            summary: count($findings) > 0
                ? sprintf('Found %d handler(s) with old-style signatures', count($findings))
                : 'All handlers already use new-style signatures',
        );
    }

    public function apply(string $pluginPath): RuleResult
    {
        $handlers = $this->extractHandlerReferences($pluginPath);
        $changes = [];
        $warnings = [];

        // Group handlers by file so we modify each file once
        $byFile = [];
        foreach ($handlers as $handler) {
            $location = $this->findHandlerMethod($pluginPath, $handler['class'], $handler['method']);
            if (!$location) {
                $warnings[] = "Could not find {$handler['class']}::{$handler['method']}()";
                continue;
            }
            if ($this->hasNewSignature($location['code'], $handler['method'], $handler['kind'])) {
                continue;
            }
            if (!$this->hasOldSignature($location['code'], $handler['method'], $handler['kind'])) {
                continue;
            }

            $byFile[$location['abspath']][] = [
                'handler' => $handler,
                'location' => $location,
            ];
        }

        foreach ($byFile as $absPath => $items) {
            $code = file_get_contents($absPath);
            $relPath = $this->relativePath($pluginPath, $absPath);

            foreach ($items as $item) {
                $handler = $item['handler'];
                $method = $handler['method'];
                $kind = $handler['kind'];

                $code = $this->rewriteHandler($code, $method, $kind);
            }

            file_put_contents($absPath, $code);
            $methods = array_map(fn($i) => $i['handler']['method'], $items);
            $changes[] = new FileChange(
                file: $relPath,
                type: 'modified',
                description: 'Rewrote handler signature(s): ' . implode(', ', $methods),
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
     * Parse elgg-plugin.php to extract all handler references.
     *
     * @return array<array{class: string, method: string, kind: 'hook'|'event'}>
     */
    private function extractHandlerReferences(string $pluginPath): array
    {
        $file = $pluginPath . '/elgg-plugin.php';
        if (!is_file($file)) {
            return [];
        }

        $code = file_get_contents($file);
        $handlers = [];

        // Match patterns like: ClassName::class . '::methodName' => []
        // in both 'hooks' and 'events' sections
        $inSection = null;

        foreach (explode("\n", $code) as $line) {
            if (preg_match("/^\s*'hooks'\s*=>/", $line)) {
                $inSection = 'hook';
            } elseif (preg_match("/^\s*'events'\s*=>/", $line)) {
                $inSection = 'event';
            } elseif (preg_match("/^\s*'(actions|routes|view_extensions|widgets|entities|notifications|group_tools|bootstrap|settings|plugin)'\s*=>/", $line)) {
                $inSection = null;
            }

            if ($inSection && preg_match("/(\w[\w\\\\]*)::class\s*\.\s*'::([\w]+)'/", $line, $m)) {
                $handlers[] = [
                    'class' => $m[1],
                    'method' => $m[2],
                    'kind' => $inSection,
                ];
            } elseif ($inSection && preg_match("/'([\w\\\\]+)::([\w]+)'\s*=>/", $line, $m)) {
                // Fully qualified string: 'Vendor\Class::method' => []
                $parts = explode('\\', $m[1]);
                $handlers[] = [
                    'class' => end($parts),
                    'method' => $m[2],
                    'kind' => $inSection,
                ];
            }
        }

        return $handlers;
    }

    /**
     * Find the file and line where a handler method is defined.
     */
    private function findHandlerMethod(string $pluginPath, string $className, string $methodName): ?array
    {
        foreach ($this->findPhpFiles($pluginPath) as $file) {
            if (str_contains($file, '/vendor/')) {
                continue;
            }

            $code = file_get_contents($file);
            if ($code === false || !str_contains($code, $methodName)) {
                continue;
            }

            // Find the method definition line
            if (preg_match('/function\s+' . preg_quote($methodName) . '\s*\(([^)]*)\)/m', $code, $m, PREG_OFFSET_CAPTURE)) {
                $line = substr_count(substr($code, 0, $m[0][1]), "\n") + 1;
                return [
                    'abspath' => $file,
                    'relpath' => $this->relativePath($pluginPath, $file),
                    'line' => $line,
                    'code' => $code,
                    'signature' => trim($m[0][0]),
                ];
            }
        }

        return null;
    }

    /**
     * Check if a method already has the new single-arg signature.
     */
    private function hasNewSignature(string $code, string $method, string $kind): bool
    {
        $typeHint = $kind === 'hook' ? 'Elgg\\\\Hook' : 'Elgg\\\\Event';
        $pattern = '/function\s+' . preg_quote($method) . '\s*\(\s*\\\\?' . $typeHint . '\s+\$/';
        return (bool) preg_match($pattern, $code);
    }

    /**
     * Check if a method has the old multi-arg signature.
     */
    private function hasOldSignature(string $code, string $method, string $kind): bool
    {
        if ($kind === 'hook') {
            // Match: function method($hook, $type, $return, $params) or similar 4-arg
            $pattern = '/function\s+' . preg_quote($method) . '\s*\(\s*\$\w+\s*,\s*\$\w+\s*,\s*\$\w+\s*,\s*\$\w+/';
        } else {
            // Match: function method($event, $type, $entity) or similar 3-arg
            $pattern = '/function\s+' . preg_quote($method) . '\s*\(\s*\$\w+\s*,\s*\$\w+\s*,\s*\$\w+/';
        }
        return (bool) preg_match($pattern, $code);
    }

    /**
     * Rewrite a handler method's signature and body references.
     */
    private function rewriteHandler(string $code, string $method, string $kind): string
    {
        if ($kind === 'hook') {
            return $this->rewriteHookHandler($code, $method);
        }
        return $this->rewriteEventHandler($code, $method);
    }

    /**
     * Rewrite a hook handler: ($hook, $type, $return, $params) → (\Elgg\Hook $hook)
     */
    private function rewriteHookHandler(string $code, string $method): string
    {
        // Capture the 4 parameter names
        $sigPattern = '/(function\s+' . preg_quote($method) . '\s*\()\s*\$(\w+)\s*,\s*\$(\w+)\s*,\s*\$(\w+)\s*,\s*\$(\w+)\s*\)/';

        if (!preg_match($sigPattern, $code, $m)) {
            return $code;
        }

        $hookVar = $m[2];   // e.g. 'hook'
        $typeVar = $m[3];   // e.g. 'type'
        $returnVar = $m[4]; // e.g. 'return' or 'value' or 'items'
        $paramsVar = $m[5]; // e.g. 'params'

        // Replace signature first, then extract body (so offsets are correct)
        $code = preg_replace(
            $sigPattern,
            '${1}\\Elgg\\Hook $hook)',
            $code,
            1
        );

        $methodBody = $this->extractMethodBody($code, $method);
        if (!$methodBody) {
            return $code;
        }

        $bodyStart = $methodBody['start'];
        $bodyEnd = $methodBody['end'];
        $body = substr($code, $bodyStart, $bodyEnd - $bodyStart);

        // Determine if $return is modified (assigned to or array-pushed)
        $returnIsModified = (bool) preg_match('/\$' . preg_quote($returnVar) . '\s*(\[|=)/', $body);

        // 1. Replace $params['key'] → $hook->getParam('key')
        $body = preg_replace(
            '/\$' . preg_quote($paramsVar) . '\s*\[\s*[\'"](\w+)[\'"]\s*\]/',
            '\$hook->getParam(\'$1\')',
            $body
        );

        // 2. Replace elgg_extract('key', $params, default) → $hook->getParam('key', default)
        $body = preg_replace(
            '/elgg_extract\s*\(\s*[\'"](\w+)[\'"]\s*,\s*\$' . preg_quote($paramsVar) . '\s*,\s*([^)]+)\)/',
            '\$hook->getParam(\'$1\', $2)',
            $body
        );

        // 3. Replace elgg_extract('key', $params) → $hook->getParam('key')
        $body = preg_replace(
            '/elgg_extract\s*\(\s*[\'"](\w+)[\'"]\s*,\s*\$' . preg_quote($paramsVar) . '\s*\)/',
            '\$hook->getParam(\'$1\')',
            $body
        );

        // 4. Replace remaining $params → $hook->getParams()
        $body = preg_replace(
            '/\$' . preg_quote($paramsVar) . '\b/',
            '\$hook->getParams()',
            $body
        );

        // 5. Handle $return variable
        if ($returnIsModified) {
            // $return is modified — initialize a local copy from getValue(), keep using $return
            $body = "\n\t\t\$" . $returnVar . " = \$hook->getValue();\n" . $body;
        } else {
            // $return is only read — replace with $hook->getValue()
            $body = preg_replace(
                '/\$' . preg_quote($returnVar) . '\b/',
                '\$hook->getValue()',
                $body
            );
        }

        // 6. Replace $type → $hook->getType()
        $body = preg_replace(
            '/\$' . preg_quote($typeVar) . '\b/',
            '\$hook->getType()',
            $body
        );

        // 7. Replace $hook used as string name (old first arg) — only if hookVar != 'hook'
        if ($hookVar !== 'hook') {
            $body = preg_replace(
                '/\$' . preg_quote($hookVar) . '\b/',
                '\$hook->getName()',
                $body
            );
        }

        $code = substr($code, 0, $bodyStart) . $body . substr($code, $bodyEnd);

        return $code;
    }

    /**
     * Rewrite an event handler: ($event, $type, $entity) → (\Elgg\Event $event)
     */
    private function rewriteEventHandler(string $code, string $method): string
    {
        // Capture the 3 parameter names
        $sigPattern = '/(function\s+' . preg_quote($method) . '\s*\()\s*\$(\w+)\s*,\s*\$(\w+)\s*,\s*\$(\w+)\s*\)/';

        if (!preg_match($sigPattern, $code, $m)) {
            return $code;
        }

        $eventVar = $m[2];  // e.g. 'event'
        $typeVar = $m[3];   // e.g. 'type'
        $entityVar = $m[4]; // e.g. 'entity'

        // Replace signature first, then extract body (so offsets are correct)
        $code = preg_replace(
            $sigPattern,
            '${1}\\Elgg\\Event $event)',
            $code,
            1
        );

        $methodBody = $this->extractMethodBody($code, $method);
        if (!$methodBody) {
            return $code;
        }

        $bodyStart = $methodBody['start'];
        $bodyEnd = $methodBody['end'];
        $body = substr($code, $bodyStart, $bodyEnd - $bodyStart);

        // Replace $entity → $event->getObject()
        $body = preg_replace(
            '/\$' . preg_quote($entityVar) . '\b/',
            '\$event->getObject()',
            $body
        );

        // Replace $type → $event->getType()
        $body = preg_replace(
            '/\$' . preg_quote($typeVar) . '\b/',
            '\$event->getType()',
            $body
        );

        // Replace $event used as string name (old first arg) — only if eventVar != 'event'
        if ($eventVar !== 'event') {
            $body = preg_replace(
                '/\$' . preg_quote($eventVar) . '\b/',
                '\$event->getName()',
                $body
            );
        }

        $code = substr($code, 0, $bodyStart) . $body . substr($code, $bodyEnd);

        return $code;
    }

    /**
     * Extract the body boundaries of a method (between opening { and matching }).
     *
     * @return array{start: int, end: int}|null
     */
    private function extractMethodBody(string $code, string $method): ?array
    {
        // Find "function methodName(...) {"
        $pattern = '/function\s+' . preg_quote($method) . '\s*\([^)]*\)\s*\{/';
        if (!preg_match($pattern, $code, $m, PREG_OFFSET_CAPTURE)) {
            return null;
        }

        $bracePos = strpos($code, '{', $m[0][1]);
        if ($bracePos === false) {
            return null;
        }

        // Find matching closing brace
        $depth = 1;
        $pos = $bracePos + 1;
        $len = strlen($code);
        $inString = false;
        $stringChar = '';

        while ($pos < $len && $depth > 0) {
            $ch = $code[$pos];

            if ($inString) {
                if ($ch === '\\') {
                    $pos++; // skip escaped char
                } elseif ($ch === $stringChar) {
                    $inString = false;
                }
            } else {
                if ($ch === "'" || $ch === '"') {
                    $inString = true;
                    $stringChar = $ch;
                } elseif ($ch === '{') {
                    $depth++;
                } elseif ($ch === '}') {
                    $depth--;
                }
            }
            $pos++;
        }

        // Body is between opening { and closing }
        return ['start' => $bracePos + 1, 'end' => $pos - 1];
    }
}
