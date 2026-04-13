<?php

// Direct renames
$value = datalist_get('version');
datalist_set('version', '2023010100');
$class = get_subtype_class('object', 'blog');
elgg_group_gatekeeper($group->guid);
$dates = get_entity_dates('object', 'blog');

// Removals (standalone statements)
create_metadata_from_array($entity->guid, $data);
metadata_array_to_values($metadata);
detect_extender_valuetype($value);
elgg_get_metastring_id('description');
is_memcache_available();

// Warn only (not removed, just warned)
can_write_to_container($user->guid, $container->guid);
run_function_once('my_upgrade_function');
system_messages('Hello world');

// Removed in expression context (should warn, not remove)
if (is_memcache_available()) {
    $cache = _elgg_get_memcache('my_cache');
}

// Search functions (all removed)
search_highlight_words($words, $text);
