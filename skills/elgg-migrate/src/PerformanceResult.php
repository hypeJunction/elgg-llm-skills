<?php

declare(strict_types=1);

namespace ElggMigrate;

/**
 * Outcome of the PerformanceGate: the schema-changing DDL a migrated plugin
 * ships, and whether a committed benchmark proves it was measured.
 */
final class PerformanceResult
{
    /**
     * @param array<int,array{file:string,line:int,ddl:string}> $findings  schema-DDL sites
     * @param array<int,string> $evidence  relative paths of benchmark artefacts found
     */
    public function __construct(
        public readonly array $findings,
        public readonly array $evidence,
        public readonly bool $passed,
        public readonly string $summary,
    ) {}

    public function hasSchemaChange(): bool
    {
        return !empty($this->findings);
    }

    public function hasEvidence(): bool
    {
        return !empty($this->evidence);
    }
}
