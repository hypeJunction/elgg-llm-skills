<?php

declare(strict_types=1);

namespace ElggMigrate;

/**
 * Result of a composer audit run.
 */
final class AuditResult
{
    /**
     * @param array<Advisory> $advisories
     * @param array<string> $abandoned Abandoned packages with optional replacement
     * @param bool $passed True if no advisories at error severity
     * @param string $source Path to the composer.lock that was audited
     * @param string $summary Human-readable summary
     */
    public function __construct(
        public readonly array $advisories,
        public readonly array $abandoned,
        public readonly bool $passed,
        public readonly string $source,
        public readonly string $summary,
    ) {}

    /**
     * Advisories at error-level severity (critical, high).
     *
     * @return array<Advisory>
     */
    public function critical(): array
    {
        return array_filter(
            $this->advisories,
            fn(Advisory $a) => in_array(strtolower($a->severity), ['critical', 'high'], true),
        );
    }

    /**
     * Advisories at warning-level severity (medium, low, unknown).
     *
     * @return array<Advisory>
     */
    public function nonCritical(): array
    {
        return array_filter(
            $this->advisories,
            fn(Advisory $a) => !in_array(strtolower($a->severity), ['critical', 'high'], true),
        );
    }
}
