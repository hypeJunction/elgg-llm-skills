<?php

declare(strict_types=1);

namespace ElggMigrate\Rules\V4ToV5;

use ElggMigrate\AbstractRule;
use ElggMigrate\Finding;
use ElggMigrate\RuleAnalysis;
use ElggMigrate\RuleResult;

/**
 * Flags usages of the monolithic 'jquery-ui' AMD module that was split into
 * per-widget submodule paths in Elgg 5.x.
 *
 * Detection targets:
 *   - PHP: elgg_require_js('jquery-ui') call sites
 *   - JS:  define([..., 'jquery-ui', ...]) and require([..., 'jquery-ui', ...])
 *
 * Warn-only (canAutomate = false): the correct submodule path depends on which
 * jQuery UI widgets the code actually uses — a human must inspect and pick the
 * right path (e.g. 'jquery-ui/widgets/sortable').
 */
final class JqueryUiSplit extends AbstractRule
{
    public function getId(): string
    {
        return 'jquery-ui-split-5x';
    }

    public function getDescription(): string
    {
        return 'Flag monolithic jquery-ui AMD module require — split into widget submodules in 5.x';
    }

    public function canAutomate(): bool
    {
        return false;
    }

    public function analyze(string $pluginPath): RuleAnalysis
    {
        $findings = [];

        // --- PHP: elgg_require_js('jquery-ui') ---
        foreach ($this->findPhpFiles($pluginPath) as $file) {
            $rel  = $this->relativePath($pluginPath, $file);
            $code = file_get_contents($file);
            if ($code === false) {
                continue;
            }

            $ast = $this->parse($code);
            if ($ast === null) {
                continue;
            }

            $calls = $this->findFunctionCalls($ast, ['elgg_require_js']);
            $printer = $this->printer();

            foreach ($calls as $call) {
                if (empty($call->args)) {
                    continue;
                }

                $firstArg = $call->args[0];
                if (!($firstArg instanceof \PhpParser\Node\Arg)) {
                    continue;
                }

                $value = $firstArg->value;
                if (
                    $value instanceof \PhpParser\Node\Scalar\String_
                    && $value->value === 'jquery-ui'
                ) {
                    $findings[] = new Finding(
                        file: $rel,
                        line: $call->getLine(),
                        description: "elgg_require_js('jquery-ui') — 'jquery-ui' AMD module split in 5.x; require specific widget submodule instead",
                        code: $printer->prettyPrintExpr($call),
                    );
                }
            }
        }

        // --- JS: define([..., 'jquery-ui', ...]) / require([..., 'jquery-ui', ...]) ---
        foreach ($this->findJsFiles($pluginPath) as $file) {
            $rel  = $this->relativePath($pluginPath, $file);
            $code = file_get_contents($file);
            if ($code === false) {
                continue;
            }

            $matches = [];
            // Match both AMD define() and require() dep arrays containing 'jquery-ui'
            if (preg_match_all(
                '/\b(?:define|require)\s*\(\s*\[([^\]]*)\]/s',
                $code,
                $matches,
                PREG_OFFSET_CAPTURE,
            ) === false) {
                continue;
            }

            foreach ($matches[0] as $idx => $match) {
                $depsString = $matches[1][$idx][0];
                if (!str_contains($depsString, "'jquery-ui'") && !str_contains($depsString, '"jquery-ui"')) {
                    continue;
                }

                $offset = $match[1];
                $line   = substr_count(substr($code, 0, $offset), "\n") + 1;

                $findings[] = new Finding(
                    file: $rel,
                    line: $line,
                    description: "AMD dep 'jquery-ui' — module split in 5.x; replace with specific submodule path (e.g. 'jquery-ui/widgets/sortable')",
                    code: trim(substr($match[0], 0, 80)),
                );
            }
        }

        $applicable = count($findings) > 0;

        return new RuleAnalysis(
            ruleId: $this->getId(),
            applicable: $applicable,
            findings: $findings,
            summary: $applicable
                ? sprintf('Found %d jquery-ui AMD reference(s) that need submodule migration', count($findings))
                : 'No monolithic jquery-ui AMD references found',
        );
    }

    public function apply(string $pluginPath): RuleResult
    {
        // Flag-only — cannot safely pick the correct submodule automatically.
        $analysis = $this->analyze($pluginPath);
        $warnings = [];

        foreach ($analysis->findings as $finding) {
            $warnings[] = "{$finding->file}:{$finding->line} — {$finding->description}";
        }

        return new RuleResult(
            ruleId: $this->getId(),
            success: true,
            changes: [],
            warnings: $warnings,
        );
    }

    // -------------------------------------------------------------------------

    /**
     * Yield all .js files recursively under a directory, skipping
     * node_modules, vendor, Playwright test dirs, and minified files.
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
            if ($file->getExtension() !== 'js') {
                continue;
            }

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
}
