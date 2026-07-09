<?php

declare(strict_types=1);

namespace ElggMigrate\Rules\V4ToV5;

use ElggMigrate\AbstractRule;
use ElggMigrate\FileChange;
use ElggMigrate\Finding;
use ElggMigrate\RuleAnalysis;
use ElggMigrate\RuleResult;

/**
 * Renames/remaps classes that moved namespaces in Elgg 5.0.
 *
 * Affected classes:
 * - ElggAutoP          → Elgg\Views\AutoParagraph
 * - ElggCache          → Elgg\Cache\BaseCache
 * - ElggDiskFilestore  → Elgg\Filesystem\Filestore\DiskFilestore
 * - ElggFilestore      → Elgg\Filesystem\Filestore
 * - ElggRewriteTester  → Elgg\Router\RewriteTester
 * - ElggTempDiskFilestore → Elgg\Filesystem\Filestore\TempDiskFilestore
 * - Elgg\Database\SiteSecret → Elgg\Security\SiteSecret
 *
 * Removed classes (flagged as findings, not auto-removed):
 * - Elgg\WebServices\ApiKeyForm
 * - Loggable interface (merged into ElggData)
 */
final class MovedClasses extends AbstractRule
{
    /**
     * Map of old class name → new fully-qualified class name.
     * Root-level (no namespace) classes have no leading backslash here.
     *
     * @var array<string, string>
     */
    public const MAP = [
        // Root-level Elgg* classes → namespaced equivalents
        'ElggAutoP'             => 'Elgg\\Views\\AutoParagraph',
        'ElggCache'             => 'Elgg\\Cache\\BaseCache',
        'ElggDiskFilestore'     => 'Elgg\\Filesystem\\Filestore\\DiskFilestore',
        'ElggFilestore'         => 'Elgg\\Filesystem\\Filestore',
        'ElggRewriteTester'     => 'Elgg\\Router\\RewriteTester',
        'ElggTempDiskFilestore' => 'Elgg\\Filesystem\\Filestore\\TempDiskFilestore',

        // Namespaced class moves
        'Elgg\\Database\\SiteSecret' => 'Elgg\\Security\\SiteSecret',
    ];

    /**
     * Classes that were removed (no replacement available via simple rename).
     * These are flagged as findings but not auto-transformed.
     *
     * @var array<string>
     */
    public const REMOVED = [
        'Elgg\\WebServices\\ApiKeyForm',
        // Loggable interface was merged into ElggData — references must be
        // reviewed manually since the fix is to remove the implements clause.
        'Loggable',
    ];

    public function getId(): string
    {
        return 'moved-classes-5x';
    }

    public function getDescription(): string
    {
        return 'Update references to classes moved or removed in Elgg 5.0';
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
            if ($code === false) {
                continue;
            }

            foreach (self::MAP as $old => $new) {
                if ($this->codeContainsClass($code, $old)) {
                    $findings[] = new Finding(
                        file: $relativePath,
                        line: 0,
                        description: "{$old} → {$new}",
                        code: '',
                    );
                }
            }

            foreach (self::REMOVED as $removed) {
                if ($this->codeContainsClass($code, $removed)) {
                    $findings[] = new Finding(
                        file: $relativePath,
                        line: 0,
                        description: "{$removed} was removed in 5.0 — review and remove usage manually",
                        code: '',
                    );
                }
            }
        }

        $applicable = count($findings) > 0;

        return new RuleAnalysis(
            ruleId: $this->getId(),
            applicable: $applicable,
            findings: $findings,
            summary: $applicable
                ? sprintf('Found %d moved/removed class reference(s) to update', count($findings))
                : 'No references to moved or removed classes found',
        );
    }

    public function apply(string $pluginPath): RuleResult
    {
        $changes = [];

        // Sort by length descending to avoid partial-match replacements
        $sorted = self::MAP;
        uksort($sorted, fn($a, $b) => strlen($b) - strlen($a));

        foreach ($this->findPhpFiles($pluginPath) as $file) {
            $relativePath = $this->relativePath($pluginPath, $file);
            $code = file_get_contents($file);
            if ($code === false) {
                continue;
            }

            $original = $code;

            foreach ($sorted as $old => $new) {
                if (str_contains($old, '\\')) {
                    // Namespaced class: replace use statements and FQN references
                    $code = str_replace('use ' . $old, 'use ' . $new, $code);
                    $code = str_replace('\\' . $old, '\\' . $new, $code);
                    // Bare reference without leading backslash (e.g., in catch blocks)
                    $code = str_replace($old, $new, $code);
                } else {
                    // Root-level class: only replace when preceded by backslash (FQN usage)
                    // or as a use statement target
                    $code = str_replace('\\' . $old, '\\' . $new, $code);
                    $code = str_replace('use ' . $old . ';', 'use ' . $new . ';', $code);
                    $code = str_replace('use ' . $old . ' ', 'use ' . $new . ' ', $code);
                }
            }

            if ($code !== $original) {
                file_put_contents($file, $code);
                $changes[] = new FileChange(
                    file: $relativePath,
                    type: 'modified',
                    description: 'Updated references to classes moved to new namespaces in Elgg 5.0',
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
     * Check whether source code likely contains a reference to the given class.
     */
    private function codeContainsClass(string $code, string $className): bool
    {
        if (str_contains($className, '\\')) {
            return str_contains($code, $className)
                || str_contains($code, '\\' . $className);
        }

        // Root-level: look for FQN usage (\ClassName) or use-statement
        return str_contains($code, '\\' . $className)
            || str_contains($code, 'use ' . $className . ';')
            || str_contains($code, 'use ' . $className . ' ');
    }
}
