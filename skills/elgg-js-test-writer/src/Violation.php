<?php

declare(strict_types=1);

namespace ElggMigrate;

/**
 * A single version boundary or security violation found during post-migration verification.
 */
final class Violation
{
    public function __construct(
        public readonly string $file,
        public readonly int $line,
        public readonly string $severity, // 'error', 'warning'
        public readonly string $message,
        public readonly string $code,
        public readonly string $category,
    ) {}
}
