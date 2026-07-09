<?php

declare(strict_types=1);

namespace ElggMigrate\Rules\V5ToV6;

use ElggMigrate\Rules\Shared\DataDrivenRemovedFunctions;

/**
 * Rewrites global functions removed in Elgg 6.0 that have an exact 1:1
 * replacement — chiefly the procedural plugin-hook family
 * (elgg_trigger_plugin_hook → elgg_trigger_event_results, …) plus the
 * register_error/system_message/forward message+redirect helpers.
 *
 * The rename set lives in references/removed-function-renames.json['6.x'].
 * Non-mechanical 6.x removals (AMD→ESM, elgg_set_ignore_access →
 * elgg_call(…), the elgg_get_plugin_setting family, …) stay LLM-guided.
 */
final class RemovedFunctions extends DataDrivenRemovedFunctions
{
    protected function targetMajor(): string
    {
        return '6.x';
    }
}
