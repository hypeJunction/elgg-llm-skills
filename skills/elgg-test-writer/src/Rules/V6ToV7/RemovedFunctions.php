<?php

declare(strict_types=1);

namespace ElggMigrate\Rules\V6ToV7;

use ElggMigrate\Rules\Shared\DataDrivenRemovedFunctions;

/**
 * Rewrites global functions removed in Elgg 7.0 that have an exact 1:1
 * replacement (currently elgg_get_logged_in_user →
 * elgg_get_logged_in_user_entity). The rename set lives in
 * references/removed-function-renames.json['7.x']; the more involved 7.x
 * removals (elgg_is_admin_user, elgg_new_entity, elgg_reset_system_cache —
 * the last handled by the dedicated ResetSystemCache rule) stay LLM-guided.
 */
final class RemovedFunctions extends DataDrivenRemovedFunctions
{
    protected function targetMajor(): string
    {
        return '7.x';
    }
}
