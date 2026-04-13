<?php

declare(strict_types=1);

namespace ElggMigrate;

/**
 * Result of a security sweep on a plugin.
 */
final class SecurityResult
{
    /**
     * @param array<Violation> $violations All security violations found
     * @param bool $passed Whether the scan passed (no error-level violations)
     * @param string $summary Human-readable summary
     */
    public function __construct(
        public readonly array $violations,
        public readonly bool $passed,
        public readonly string $summary,
    ) {}

    /**
     * @return array<Violation>
     */
    public function errors(): array
    {
        return array_filter($this->violations, fn(Violation $v) => $v->severity === 'error');
    }

    /**
     * @return array<Violation>
     */
    public function warnings(): array
    {
        return array_filter($this->violations, fn(Violation $v) => $v->severity === 'warning');
    }

    /**
     * Group violations by category.
     *
     * @return array<string, array<Violation>>
     */
    public function byCategory(): array
    {
        $grouped = [];
        foreach ($this->violations as $v) {
            $grouped[$v->category][] = $v;
        }
        return $grouped;
    }
}
