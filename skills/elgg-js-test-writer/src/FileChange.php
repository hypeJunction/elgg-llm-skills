<?php

declare(strict_types=1);

namespace ElggMigrate;

/**
 * Describes a file modification made by a migration rule.
 */
final class FileChange
{
    public function __construct(
        public readonly string $file,
        public readonly string $type, // 'modified', 'created', 'deleted', 'renamed'
        public readonly string $description,
    ) {}
}
