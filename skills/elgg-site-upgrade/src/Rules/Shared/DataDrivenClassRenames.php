<?php

declare(strict_types=1);

namespace ElggMigrate\Rules\Shared;

use ElggMigrate\AbstractRule;
use ElggMigrate\FileChange;
use ElggMigrate\Finding;
use ElggMigrate\RuleAnalysis;
use ElggMigrate\RuleResult;

/**
 * Data-driven 1:1 fully-qualified class-name rewrite. The map is loaded per
 * target major from references/class-renames.json (the curated SAFE subset) so
 * adding a rename is a data edit, not new code — one source of truth for
 * auto-rewritable class moves across every step. Mirrors the plain-substitution
 * approach of Rules\V4ToV5\MovedClasses: because every key is fully qualified
 * and unique, a substring replace over the source is safe (no short-name
 * collisions), and length-descending order prevents one FQN being a prefix of
 * another.
 *
 * Concrete per-step subclasses supply only their target major.
 */
abstract class DataDrivenClassRenames extends AbstractRule
{
    /**
     * The Elgg major this rule targets, e.g. '7.x'. Must match a top-level key
     * in references/class-renames.json.
     */
    abstract protected function targetMajor(): string;

    /**
     * @return array<string, string> old FQN → new FQN (backslash-separated, no leading '\')
     */
    protected function renameMap(): array
    {
        $path = __DIR__ . '/../../../references/class-renames.json';
        if (!is_file($path)) {
            return [];
        }
        $data = json_decode((string) file_get_contents($path), true);
        $major = $this->targetMajor();
        if (!is_array($data) || !isset($data[$major]) || !is_array($data[$major])) {
            return [];
        }
        $map = [];
        foreach ($data[$major] as $old => $new) {
            $map[(string) $old] = (string) $new;
        }
        // Longest first so a shorter FQN can't partially rewrite a longer one.
        uksort($map, fn($a, $b) => strlen($b) - strlen($a));
        return $map;
    }

    public function getId(): string
    {
        return 'class-renames-' . str_replace('.x', 'x', $this->targetMajor());
    }

    public function getDescription(): string
    {
        return sprintf(
            'Rewrite class references renamed/moved in Elgg %s to their new FQN (data-driven from class-renames.json)',
            $this->targetMajor(),
        );
    }

    public function canAutomate(): bool
    {
        return true;
    }

    public function analyze(string $pluginPath): RuleAnalysis
    {
        $map = $this->renameMap();
        $findings = [];

        if (!empty($map)) {
            foreach ($this->findPhpFiles($pluginPath) as $file) {
                $relativePath = $this->relativePath($pluginPath, $file);
                $code = file_get_contents($file);
                if ($code === false) {
                    continue;
                }
                foreach ($map as $old => $new) {
                    if (str_contains($code, $old)) {
                        $findings[] = new Finding(
                            file: $relativePath,
                            line: 0,
                            description: "{$old} → {$new}",
                            code: '',
                        );
                    }
                }
            }
        }

        $applicable = count($findings) > 0;

        return new RuleAnalysis(
            ruleId: $this->getId(),
            applicable: $applicable,
            findings: $findings,
            summary: $applicable
                ? sprintf('Found %d renamed class reference(s) to update', count($findings))
                : 'No references to renamed classes found',
        );
    }

    public function apply(string $pluginPath): RuleResult
    {
        $map = $this->renameMap();
        $changes = [];

        if (!empty($map)) {
            foreach ($this->findPhpFiles($pluginPath) as $file) {
                $relativePath = $this->relativePath($pluginPath, $file);
                $code = file_get_contents($file);
                if ($code === false) {
                    continue;
                }
                $original = $code;
                foreach ($map as $old => $new) {
                    // Did this file import the old class via a plain (unaliased)
                    // `use Old\FQN;`? If so its short name resolves to the old
                    // class HERE, so it is safe to rewrite the short name in this
                    // file — a file can't import two classes under one short name.
                    $plainUse = (bool) preg_match('/\buse\s+\\\\?' . preg_quote($old, '/') . '\s*;/', $code);

                    // 1. Rewrite every fully-qualified occurrence (the `use` FQN,
                    //    \FQN::class, and 'FQN' string-literal registrations). The
                    //    namespace prefix makes this collision-proof.
                    $code = str_replace($old, $new, $code);

                    // 2. If it was a plain import, the imported short name just
                    //    changed OldShort -> NewShort; update short references.
                    $oldShort = self::shortName($old);
                    $newShort = self::shortName($new);
                    if ($plainUse && $oldShort !== $newShort) {
                        $code = preg_replace(
                            '/\b' . preg_quote($oldShort, '/') . '\b/',
                            $newShort,
                            $code,
                        );
                    }
                }
                if ($code !== $original) {
                    file_put_contents($file, $code);
                    $changes[] = new FileChange(
                        file: $relativePath,
                        type: 'modified',
                        description: sprintf('Rewrote class references renamed in Elgg %s', $this->targetMajor()),
                    );
                }
            }
        }

        return new RuleResult(
            ruleId: $this->getId(),
            success: true,
            changes: $changes,
        );
    }

    /** Trailing segment of a backslash-separated FQN. */
    private static function shortName(string $fqn): string
    {
        $pos = strrpos($fqn, '\\');
        return $pos === false ? $fqn : substr($fqn, $pos + 1);
    }
}
