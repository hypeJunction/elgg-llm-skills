<?php

declare(strict_types=1);

namespace ElggMigrate;

/**
 * A single occurrence found during analysis that needs migration.
 */
final class Finding
{
    public function __construct(
        public readonly string $file,
        public readonly int $line,
        public readonly string $description,
        public readonly string $code,
    ) {}
}
