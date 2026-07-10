<?php

declare(strict_types=1);

namespace ElggMigrate\Rules\V6ToV7;

use ElggMigrate\Rules\Shared\DataDrivenRemovedFunctions;

/**
 * Rewrites global functions removed in Elgg 7.0 that have an exact 1:1
 * replacement with a compatible call signature — currently only
 * elgg_dump() → elgg_log(). The rename set lives in
 * references/removed-function-renames.json['7.x'].
 *
 * Everything else 7.0 removed is detection-only (see removed-functions.json
 * ['7.x']) because no mechanical rewrite is safe:
 *  - notify_user() → elgg_notify_user() takes entirely different arguments.
 *  - elgg_plugin_exists() has no procedural equivalent.
 *  - the simplecache/system-cache toggles became service calls on
 *    _elgg_services()->{simpleCache,systemCache}.
 *  - elgg_reset_system_cache() has its own rule (ResetSystemCache).
 * Those stay LLM-guided.
 */
final class RemovedFunctions extends DataDrivenRemovedFunctions
{
    protected function targetMajor(): string
    {
        return '7.x';
    }
}
