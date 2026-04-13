<?php

declare(strict_types=1);

namespace ElggMigrate;

/**
 * Thrown when a plugin's detected version does not match the manifest's "from" version.
 */
final class VersionMismatchException extends \RuntimeException
{
    public function __construct(
        string $message,
        public readonly string $detectedVersion,
        public readonly string $expectedVersion,
    ) {
        parent::__construct($message);
    }
}
