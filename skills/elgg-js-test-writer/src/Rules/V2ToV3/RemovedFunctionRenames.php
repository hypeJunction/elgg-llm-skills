<?php

declare(strict_types=1);

namespace ElggMigrate\Rules\V2ToV3;

use ElggMigrate\Rules\Shared\DataDrivenRemovedFunctions;

/**
 * Auto-renames the 1:1 global functions removed in Elgg 3.0 (elgg_redirect,
 * _elgg_rmdir, _elgg_html_decode, elgg_get_logged_in_user) to their surviving
 * equivalents, driven by the '3.x' block of
 * references/removed-function-renames.json.
 *
 * These were core-verified as 3.x removals (removed-functions.json, commit
 * d9df460) but previously sat in the 6.x/7.x rename blocks, so they were only
 * rewritten at 5x->6x / 6x->7x instead of at the step where they actually break
 * (bd elgg-migrate-jfrc1). Coexists with the hand-coded V2ToV3\RemovedFunctions
 * rule, which owns the remove/warn removals that are NOT plain 1:1 renames.
 */
final class RemovedFunctionRenames extends DataDrivenRemovedFunctions
{
    protected function targetMajor(): string
    {
        return '3.x';
    }

    public function getId(): string
    {
        return 'removed-function-renames-3x';
    }
}
