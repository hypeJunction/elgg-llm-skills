<?php

declare(strict_types=1);

namespace ElggMigrate\Rules\V4ToV5;

use ElggMigrate\Rules\Shared\DataDrivenRemovedFunctions;

/**
 * Auto-renames the 1:1 global functions removed in Elgg 5.0
 * (current_page_url, get_default_access, forward, register_error,
 * system_message, elgg_get_version) to their surviving equivalents.
 *
 * The map is the '5.x' block of references/removed-function-renames.json — the
 * single source of truth shared with the DETECTION side (removed-functions.json).
 * Previously this rule carried a hand-coded MAP and the message/redirect family
 * was mis-filed under the 6.x rename block, so a 4x->5x migration never rewrote
 * it at the step where it actually breaks (bd elgg-migrate-jfrc1). getId() stays
 * 'removed-functions-5x'.
 */
final class RemovedFunctions extends DataDrivenRemovedFunctions
{
    protected function targetMajor(): string
    {
        return '5.x';
    }
}
