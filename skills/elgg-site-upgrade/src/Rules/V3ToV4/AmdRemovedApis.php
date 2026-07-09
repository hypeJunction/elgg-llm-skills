<?php

declare(strict_types=1);

namespace ElggMigrate\Rules\V3ToV4;

use ElggMigrate\AbstractRule;
use ElggMigrate\FileChange;
use ElggMigrate\Finding;
use ElggMigrate\RuleAnalysis;
use ElggMigrate\RuleResult;

/**
 * Removes AMD module APIs that were dropped in Elgg 4.x from plugin JS files.
 *
 * Three patterns are detected and auto-fixed:
 *
 * 1. `require('elgg/init')` — the elgg/init AMD module was removed in 4.x.
 *    Its presence causes a 403 script error that silently aborts the entire
 *    AMD module. Fix: remove the require line entirely.
 *
 * 2. `elgg.echo(...)` — moved off the `elgg` AMD object into `elgg/i18n`.
 *    Fix: replace with `i18n.echo(...)` and inject `var i18n = require('elgg/i18n');`
 *    if not already present.
 *
 * 3. `elgg.provide(...)` + `deprecated_settings` block — `elgg.provide` was
 *    removed in 4.x. The deprecated_settings conditional it guards is dead code.
 *    Fix: remove the provide line and collapse the conditional to its else branch
 *    (plain `$.extend(settings, opts)`).
 */
final class AmdRemovedApis extends AbstractRule
{
    public function getId(): string
    {
        return 'amd-removed-apis-4x';
    }

    public function getDescription(): string
    {
        return 'Remove AMD APIs dropped in Elgg 4.x: elgg/init module, elgg.echo(), elgg.provide()';
    }

    public function canAutomate(): bool
    {
        return true;
    }

    public function analyze(string $pluginPath): RuleAnalysis
    {
        $findings = [];

        foreach ($this->findJsFiles($pluginPath) as $file) {
            $rel = $this->relativePath($pluginPath, $file);
            $code = file_get_contents($file);
            if ($code === false) continue;

            if (preg_match('/require\([\'"]elgg\/init[\'"]\)/', $code)) {
                $findings[] = new Finding(
                    file: $rel,
                    line: $this->firstLineOf('/require\([\'"]elgg\/init[\'"]\)/', $code),
                    description: "require('elgg/init') — module removed in 4.x; causes silent AMD abort",
                    code: "require('elgg/init')",
                );
            }

            if (preg_match('/\belgg\.echo\s*\(/', $code)) {
                $findings[] = new Finding(
                    file: $rel,
                    line: $this->firstLineOf('/\belgg\.echo\s*\(/', $code),
                    description: "elgg.echo() — moved to elgg/i18n in 4.x; replace with i18n.echo()",
                    code: 'elgg.echo(...)',
                );
            }

            if (preg_match('/\belgg\.provide\s*\(/', $code)) {
                $findings[] = new Finding(
                    file: $rel,
                    line: $this->firstLineOf('/\belgg\.provide\s*\(/', $code),
                    description: "elgg.provide() — removed in 4.x; drop call and deprecated_settings block",
                    code: 'elgg.provide(...)',
                );
            }
        }

        $applicable = count($findings) > 0;

        return new RuleAnalysis(
            ruleId: $this->getId(),
            applicable: $applicable,
            findings: $findings,
            summary: $applicable
                ? sprintf('Found %d removed AMD API usage(s) in JS files', count($findings))
                : 'No removed AMD API usages found in JS files',
        );
    }

    public function apply(string $pluginPath): RuleResult
    {
        $changes = [];

        foreach ($this->findJsFiles($pluginPath) as $file) {
            $rel = $this->relativePath($pluginPath, $file);
            $code = file_get_contents($file);
            if ($code === false) continue;

            $original = $code;

            // 1. Remove require('elgg/init') lines
            $code = preg_replace('/[ \t]*require\([\'"]elgg\/init[\'"]\);?\n?/', '', $code) ?? $code;

            // 2. Replace elgg.echo() with i18n.echo() and inject require if needed
            if (preg_match('/\belgg\.echo\s*\(/', $code)) {
                $code = preg_replace('/\belgg\.echo\s*\(/', 'i18n.echo(', $code) ?? $code;

                if (!str_contains($code, "require('elgg/i18n')") && !str_contains($code, 'require("elgg/i18n")')) {
                    $code = $this->injectI18nRequire($code);
                }
            }

            // 3. Remove elgg.provide() line and collapse the deprecated_settings conditional
            $code = $this->removeElggProvideBlock($code);

            if ($code !== $original) {
                file_put_contents($file, $code);
                $changes[] = new FileChange(
                    file: $rel,
                    type: 'modified',
                    description: 'Removed 3.x AMD APIs: elgg/init require, elgg.echo→i18n.echo, elgg.provide block',
                );
            }
        }

        return new RuleResult(
            ruleId: $this->getId(),
            success: true,
            changes: $changes,
        );
    }

    // -------------------------------------------------------------------------

    /**
     * Yield all .js files recursively under a plugin directory.
     * Excludes node_modules, vendor, and minified files.
     *
     * @return \Generator<string>
     */
    private function findJsFiles(string $dir): \Generator
    {
        $it = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
        );

        foreach ($it as $file) {
            /** @var \SplFileInfo $file */
            if ($file->getExtension() !== 'js') continue;

            $path = $file->getPathname();

            // Skip generated / third-party dirs and Playwright/Jest test dirs
            if (
                str_contains($path, '/node_modules/') ||
                str_contains($path, '/vendor/') ||
                str_contains($path, '/tests/playwright/') ||
                str_contains($path, '/__tests__/') ||
                str_ends_with($path, '.min.js')
            ) {
                continue;
            }

            yield $path;
        }
    }

    /**
     * Inject `var i18n = require('elgg/i18n');` after the last var/require line
     * in the AMD define factory body.
     */
    private function injectI18nRequire(string $code): string
    {
        // Find the last `var ... = require(...)` line and insert after it.
        if (preg_match_all('/^([ \t]+var \w+ = require\([^\)]+\);)$/m', $code, $matches, PREG_OFFSET_CAPTURE)) {
            $last = end($matches[0]);
            [$lineText, $offset] = $last;
            $insertAt = $offset + strlen($lineText);
            $indent = '';
            if (preg_match('/^([ \t]+)/', $lineText, $m)) {
                $indent = $m[1];
            }
            return substr($code, 0, $insertAt)
                . "\n" . $indent . "var i18n = require('elgg/i18n');"
                . substr($code, $insertAt);
        }

        return $code;
    }

    /**
     * Remove `elgg.provide(...)` lines and collapse the deprecated_settings
     * conditional that typically follows them:
     *
     *   elgg.provide('elgg.ui.foo');
     *
     *   if ($.isPlainObject(elgg.ui.foo.deprecated_settings)) {
     *       $.extend(settings, elgg.ui.foo.deprecated_settings, opts);
     *   } else {
     *       $.extend(settings, opts);
     *   }
     *
     * → $.extend(settings, opts);
     */
    private function removeElggProvideBlock(string $code): string
    {
        // Remove elgg.provide(...) lines plus the blank line that typically follows.
        $code = preg_replace('/[ \t]*elgg\.provide\([^)]*\);\n(\n)?/', '', $code) ?? $code;

        // Collapse the deprecated_settings if/else to just the else body.
        // Captures the indentation of the `if` keyword so the replacement is at the same level.
        $pattern = '/^([ \t]*)if\s*\(\$\.isPlainObject\([^)]*deprecated_settings[^)]*\)\)\s*\{[^}]+\}\s*else\s*\{[ \t]*\n([ \t]+[^\n]+)\n[ \t]*\}/m';
        $code = preg_replace_callback($pattern, static function (array $m): string {
            // $m[1] = indentation of the if line, $m[2] = else body line (over-indented by one level)
            // Re-indent the else body to match the if level.
            $ifIndent = $m[1];
            $elseBody = ltrim($m[2]);
            return $ifIndent . $elseBody;
        }, $code) ?? $code;

        return $code;
    }

    /**
     * Return the 1-based line number of the first regex match in $code.
     */
    private function firstLineOf(string $pattern, string $code): int
    {
        if (!preg_match($pattern, $code, $m, PREG_OFFSET_CAPTURE)) {
            return 0;
        }
        return substr_count(substr($code, 0, $m[0][1]), "\n") + 1;
    }
}
