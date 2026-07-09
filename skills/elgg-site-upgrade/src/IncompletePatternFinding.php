<?php

declare(strict_types=1);

namespace ElggMigrate;

/**
 * A single instance of a prior-version pattern found in a plugin that claims
 * (by shape) to be on a later version. Signals an incomplete migration.
 *
 * Example: a plugin without start.php (shape says 4.x) that still contains a
 * 3.x-style 4-argument hook handler signature is incompletely migrated.
 */
final class IncompletePatternFinding
{
    public function __construct(
        public readonly string $file,
        public readonly int $line,
        /** Version the leftover pattern belongs to (e.g. '3.x'). */
        public readonly string $sourceVersion,
        /** Version the plugin claims to be on by shape (e.g. '4.x'). */
        public readonly string $claimedVersion,
        /** Short identifier for the pattern (e.g. 'old-hook-signature'). */
        public readonly string $patternId,
        /** Human-readable description of what was found. */
        public readonly string $description,
        /** Suggested fix or pointer to a rule that addresses it. */
        public readonly string $fix,
    ) {}
}
