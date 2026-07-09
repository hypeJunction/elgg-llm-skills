<?php

declare(strict_types=1);

namespace ElggMigrate\Rules\V3ToV4;

use ElggMigrate\AbstractRule;
use ElggMigrate\Finding;
use ElggMigrate\RuleAnalysis;
use ElggMigrate\RuleResult;

/**
 * Flags JavaScript dependencies removed from Elgg 4.x.
 *
 * Warn-only — replacements vary by dependency and cannot be auto-applied.
 *
 * Removed deps:
 * - jquery-treeview    → use a CSS tree or modern alternative
 * - jquery.imgareaselect / imgareaselect → use Cropper.js or Elgg cropper
 * - formdata-polyfill  → remove (FormData is native in all modern browsers)
 * - jquery-form        → use native FormData + fetch() or elgg/Ajax
 * - weakmap-polyfill   → remove (WeakMap is native in all modern browsers)
 * - simpletest/        → SimpleTest removed from Elgg 4.x
 * - widgets.init()     → elgg/widgets auto-initialises; remove the .init() call
 */
final class RemovedJsDeps extends AbstractRule
{
    private const REMOVED = [
        'jquery-treeview'      => 'Removed — use a CSS-based tree or modern alternative',
        'jquery.imgareaselect' => 'Removed — use Cropper.js or the built-in Elgg cropper',
        'imgareaselect'        => 'Removed — use Cropper.js or the built-in Elgg cropper',
        'formdata-polyfill'    => 'Remove — FormData is natively supported in all modern browsers',
        'jquery-form'          => 'Removed — use native FormData + fetch() or elgg/Ajax module',
        'weakmap-polyfill'     => 'Remove — WeakMap is natively supported in all modern browsers',
        'simpletest/'          => 'SimpleTest was removed from Elgg 4.x',
        'widgets.init('        => 'elgg/widgets no longer requires .init() — remove this call',
    ];

    public function getId(): string
    {
        return 'removed-js-deps-4x';
    }

    public function getDescription(): string
    {
        return 'Flag removed JavaScript dependencies in Elgg 4.x';
    }

    public function canAutomate(): bool
    {
        return true;
    }

    public function analyze(string $pluginPath): RuleAnalysis
    {
        $findings = [];

        foreach ($this->findJsFiles($pluginPath) as $file) {
            $rel  = $this->relativePath($pluginPath, $file);
            $code = file_get_contents($file);
            if ($code === false) continue;

            foreach (self::REMOVED as $token => $note) {
                if (!str_contains($code, $token)) continue;
                $findings[] = new Finding(
                    file: $rel,
                    line: $this->firstLineOf('/' . preg_quote($token, '/') . '/', $code),
                    description: "{$token}: {$note}",
                    code: $token,
                );
            }
        }

        $applicable = count($findings) > 0;

        return new RuleAnalysis(
            ruleId: $this->getId(),
            applicable: $applicable,
            findings: $findings,
            summary: $applicable
                ? sprintf('Found %d removed JavaScript dependency reference(s)', count($findings))
                : 'No removed JavaScript dependency references found',
        );
    }

    public function apply(string $pluginPath): RuleResult
    {
        $analysis = $this->analyze($pluginPath);
        $warnings = [];

        foreach ($analysis->findings as $finding) {
            $warnings[] = "{$finding->file}:{$finding->line} — {$finding->code}: {$finding->description}";
        }

        return new RuleResult(
            ruleId: $this->getId(),
            success: true,
            changes: [],
            warnings: $warnings,
        );
    }

    // -------------------------------------------------------------------------

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

    private function firstLineOf(string $pattern, string $code): int
    {
        if (!preg_match($pattern, $code, $m, PREG_OFFSET_CAPTURE)) {
            return 0;
        }
        return substr_count(substr($code, 0, $m[0][1]), "\n") + 1;
    }
}
