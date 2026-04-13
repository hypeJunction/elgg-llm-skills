<?php

declare(strict_types=1);

namespace ElggMigrate;

/**
 * A single CVE/security advisory found by composer audit.
 */
final class Advisory
{
    public function __construct(
        public readonly string $packageName,
        public readonly string $advisoryId,
        public readonly string $title,
        public readonly string $severity, // 'critical', 'high', 'medium', 'low', 'unknown'
        public readonly string $cve,       // CVE-XXXX-XXXX or empty
        public readonly string $affectedVersions,
        public readonly string $link,
        public readonly string $reportedAt,
    ) {}
}
