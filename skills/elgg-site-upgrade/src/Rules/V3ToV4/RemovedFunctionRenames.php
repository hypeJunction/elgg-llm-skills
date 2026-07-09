<?php

declare(strict_types=1);

namespace ElggMigrate\Rules\V3ToV4;

use ElggMigrate\Rules\Shared\DataDrivenRemovedFunctions;

/**
 * Auto-renames the 1:1 global functions removed in Elgg 4.0 (elgg_flush_caches)
 * to their surviving equivalents, driven by the '4.x' block of
 * references/removed-function-renames.json.
 *
 * elgg_flush_caches was core-verified as a 4.x removal (removed-functions.json,
 * commit d9df460) but previously sat in the 6.x rename block, so it was only
 * rewritten at 5x->6x instead of at 3x->4x (bd elgg-migrate-jfrc1). Coexists
 * with the detection-only V3ToV4\RemovedFunctions rule, which warns on the
 * removals that need judgment rather than a plain 1:1 rename.
 */
final class RemovedFunctionRenames extends DataDrivenRemovedFunctions
{
    protected function targetMajor(): string
    {
        return '4.x';
    }

    public function getId(): string
    {
        return 'removed-function-renames-4x';
    }
}
