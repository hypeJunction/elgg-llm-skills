<?php

declare(strict_types=1);

namespace ElggMigrate\Rules\V3ToV4;

use ElggMigrate\AbstractRule;
use ElggMigrate\FileChange;
use ElggMigrate\Finding;
use ElggMigrate\RuleAnalysis;
use ElggMigrate\RuleResult;

/**
 * Replaces jQuery APIs removed or deprecated in jQuery 3.5.x (shipped with Elgg 4.0).
 *
 * Auto-fixed replacements:
 * - .bind(     → .on(
 * - .unbind(   → .off(
 * - $.parseJSON(    → JSON.parse(
 * - jQuery.parseJSON( → JSON.parse(
 * - $.isArray(      → Array.isArray(
 * - jQuery.isArray( → Array.isArray(
 * - $.unique(       → $.uniqueSort(
 * - jQuery.unique(  → jQuery.uniqueSort(
 *
 * Warn-only (arg reordering makes auto-fix unsafe):
 * - .delegate(
 * - .undelegate(
 * - .size()
 */
final class JqueryDeprecatedApis extends AbstractRule
{
    public function getId(): string
    {
        return 'jquery-deprecated-apis-4x';
    }

    public function getDescription(): string
    {
        return 'Replace jQuery APIs removed in jQuery 3.5.x (Elgg 4.0)';
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

            if (str_contains($code, '.bind(')) {
                $findings[] = new Finding(
                    file: $rel,
                    line: $this->firstLineOf('/\.bind\(/', $code),
                    description: '.bind() — removed in jQuery 3.x; replace with .on()',
                    code: '.bind(',
                );
            }

            if (str_contains($code, '.unbind(')) {
                $findings[] = new Finding(
                    file: $rel,
                    line: $this->firstLineOf('/\.unbind\(/', $code),
                    description: '.unbind() — removed in jQuery 3.x; replace with .off()',
                    code: '.unbind(',
                );
            }

            if (str_contains($code, '$.parseJSON(') || str_contains($code, 'jQuery.parseJSON(')) {
                $findings[] = new Finding(
                    file: $rel,
                    line: $this->firstLineOf('/(?:\$|jQuery)\.parseJSON\(/', $code),
                    description: '$.parseJSON() — removed in jQuery 3.x; replace with JSON.parse()',
                    code: '$.parseJSON(',
                );
            }

            if (str_contains($code, '$.isArray(') || str_contains($code, 'jQuery.isArray(')) {
                $findings[] = new Finding(
                    file: $rel,
                    line: $this->firstLineOf('/(?:\$|jQuery)\.isArray\(/', $code),
                    description: '$.isArray() — deprecated; replace with Array.isArray()',
                    code: '$.isArray(',
                );
            }

            if (str_contains($code, '$.unique(') || str_contains($code, 'jQuery.unique(')) {
                $findings[] = new Finding(
                    file: $rel,
                    line: $this->firstLineOf('/(?:\$|jQuery)\.unique\(/', $code),
                    description: '$.unique() — deprecated; replace with $.uniqueSort()',
                    code: '$.unique(',
                );
            }

            if (str_contains($code, '.delegate(')) {
                $findings[] = new Finding(
                    file: $rel,
                    line: $this->firstLineOf('/\.delegate\(/', $code),
                    description: '.delegate() — removed in jQuery 3.x; replace with .on(event, selector, fn) (arg reordering required — manual fix)',
                    code: '.delegate(',
                );
            }

            if (str_contains($code, '.undelegate(')) {
                $findings[] = new Finding(
                    file: $rel,
                    line: $this->firstLineOf('/\.undelegate\(/', $code),
                    description: '.undelegate() — removed in jQuery 3.x; replace with .off(event, selector) (arg reordering required — manual fix)',
                    code: '.undelegate(',
                );
            }

            if (str_contains($code, '.size()')) {
                $findings[] = new Finding(
                    file: $rel,
                    line: $this->firstLineOf('/\.size\(\)/', $code),
                    description: '.size() — removed in jQuery 3.x; replace with .length (property, not method call — manual fix)',
                    code: '.size()',
                );
            }
        }

        $applicable = count($findings) > 0;

        return new RuleAnalysis(
            ruleId: $this->getId(),
            applicable: $applicable,
            findings: $findings,
            summary: $applicable
                ? sprintf('Found %d deprecated jQuery API usage(s) in JS files', count($findings))
                : 'No deprecated jQuery API usages found in JS files',
        );
    }

    public function apply(string $pluginPath): RuleResult
    {
        $changes = [];
        $warnings = [];

        foreach ($this->findJsFiles($pluginPath) as $file) {
            $rel = $this->relativePath($pluginPath, $file);
            $code = file_get_contents($file);
            if ($code === false) continue;

            $original = $code;

            // Auto-fix: .bind( → .on(
            $code = str_replace('.bind(', '.on(', $code);

            // Auto-fix: .unbind( → .off(
            $code = str_replace('.unbind(', '.off(', $code);

            // Auto-fix: $.parseJSON( and jQuery.parseJSON( → JSON.parse(
            $code = str_replace('$.parseJSON(', 'JSON.parse(', $code);
            $code = str_replace('jQuery.parseJSON(', 'JSON.parse(', $code);

            // Auto-fix: $.isArray( and jQuery.isArray( → Array.isArray(
            $code = str_replace('$.isArray(', 'Array.isArray(', $code);
            $code = str_replace('jQuery.isArray(', 'Array.isArray(', $code);

            // Auto-fix: $.unique( → $.uniqueSort(  (must not match $.uniqueSort already)
            $code = preg_replace('/\$\.unique\(/', '$.uniqueSort(', $code) ?? $code;
            $code = preg_replace('/jQuery\.unique\(/', 'jQuery.uniqueSort(', $code) ?? $code;

            if ($code !== $original) {
                file_put_contents($file, $code);
                $changes[] = new FileChange(
                    file: $rel,
                    type: 'modified',
                    description: 'Replaced deprecated jQuery 3.5.x APIs: .bind/.unbind, $.parseJSON, $.isArray, $.unique',
                );
            }

            // Warn-only: .delegate(
            if (str_contains($code, '.delegate(')) {
                $warnings[] = sprintf(
                    '%s: .delegate() found — replace manually with .on(event, selector, fn)',
                    $rel,
                );
            }

            // Warn-only: .undelegate(
            if (str_contains($code, '.undelegate(')) {
                $warnings[] = sprintf(
                    '%s: .undelegate() found — replace manually with .off(event, selector)',
                    $rel,
                );
            }

            // Warn-only: .size()
            if (str_contains($code, '.size()')) {
                $warnings[] = sprintf(
                    '%s: .size() found — replace manually with .length (property access, not method call)',
                    $rel,
                );
            }
        }

        return new RuleResult(
            ruleId: $this->getId(),
            success: true,
            changes: $changes,
            warnings: $warnings,
        );
    }

    // -------------------------------------------------------------------------

    /**
     * Yield all .js files recursively under a plugin directory.
     * Excludes node_modules, vendor, tests/playwright, __tests__, and minified files.
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
