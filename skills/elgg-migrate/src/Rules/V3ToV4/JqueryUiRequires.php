<?php

declare(strict_types=1);

namespace ElggMigrate\Rules\V3ToV4;

use ElggMigrate\AbstractRule;
use ElggMigrate\Finding;
use ElggMigrate\RuleAnalysis;
use ElggMigrate\RuleResult;

/**
 * Flags jQuery UI method calls that require explicit AMD module imports in Elgg 4.x.
 *
 * jQuery UI is no longer fully loaded by default. Plugins must require individual
 * widget modules such as 'jquery-ui/widgets/sortable'.
 *
 * Warn-only: cannot safely auto-inject require statements into existing AMD defines.
 */
final class JqueryUiRequires extends AbstractRule
{
    private const WIDGET_METHODS = [
        '.sortable('     => 'jquery-ui/widgets/sortable',
        '.draggable('    => 'jquery-ui/widgets/draggable',
        '.droppable('    => 'jquery-ui/widgets/droppable',
        '.resizable('    => 'jquery-ui/widgets/resizable',
        '.datepicker('   => 'jquery-ui/widgets/datepicker',
        '.dialog('       => 'jquery-ui/widgets/dialog',
        '.accordion('    => 'jquery-ui/widgets/accordion',
        '.autocomplete(' => 'jquery-ui/widgets/autocomplete',
        '.slider('       => 'jquery-ui/widgets/slider',
        '.tabs('         => 'jquery-ui/widgets/tabs',
        '.tooltip('      => 'jquery-ui/widgets/tooltip',
        '.progressbar('  => 'jquery-ui/widgets/progressbar',
        '.selectable('   => 'jquery-ui/widgets/selectable',
    ];

    public function getId(): string
    {
        return 'jquery-ui-requires-4x';
    }

    public function getDescription(): string
    {
        return 'Flag jQuery UI usages that need explicit AMD require in Elgg 4.x';
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

            foreach (self::WIDGET_METHODS as $method => $module) {
                if (!str_contains($code, $method)) continue;
                $findings[] = new Finding(
                    file: $rel,
                    line: $this->firstLineOf('/' . preg_quote($method, '/') . '/', $code),
                    description: "{$method} — add require('{$module}') to AMD define",
                    code: $method,
                );
            }
        }

        $applicable = count($findings) > 0;

        return new RuleAnalysis(
            ruleId: $this->getId(),
            applicable: $applicable,
            findings: $findings,
            summary: $applicable
                ? sprintf('Found %d jQuery UI method(s) that need explicit AMD requires', count($findings))
                : 'No jQuery UI method calls found requiring explicit imports',
        );
    }

    public function apply(string $pluginPath): RuleResult
    {
        $analysis = $this->analyze($pluginPath);
        $warnings = [];

        foreach ($analysis->findings as $finding) {
            $module = self::WIDGET_METHODS[$finding->code] ?? '(unknown)';
            $warnings[] = "{$finding->file}:{$finding->line} — {$finding->code} requires 'define' dep: '{$module}'";
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
