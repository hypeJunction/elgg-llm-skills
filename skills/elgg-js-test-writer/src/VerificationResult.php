<?php

declare(strict_types=1);

namespace ElggMigrate;

/**
 * Result of running post-migration verification on a plugin.
 */
final class VerificationResult
{
    /**
     * @param string $targetVersion Version the plugin was migrated to
     * @param array<Violation> $violations All violations found
     * @param bool $passed Whether verification passed (no error-level violations)
     */
    public function __construct(
        public readonly string $targetVersion,
        public readonly array $violations,
        public readonly bool $passed,
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
}
