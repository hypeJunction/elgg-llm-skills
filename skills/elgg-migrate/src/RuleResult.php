<?php

declare(strict_types=1);

namespace ElggMigrate;

/**
 * Result of applying a migration rule to a plugin.
 */
final class RuleResult
{
    /**
     * @param string $ruleId Which rule was applied
     * @param bool $success Whether the transformation succeeded
     * @param array<FileChange> $changes Files that were modified
     * @param array<string> $warnings Non-fatal issues encountered
     * @param array<string> $errors Fatal issues that prevented transformation
     */
    public function __construct(
        public readonly string $ruleId,
        public readonly bool $success,
        public readonly array $changes,
        public readonly array $warnings = [],
        public readonly array $errors = [],
    ) {}
}
