<?php

// Auto-renamed (action=rename)
$url = current_page_url();
$access = get_default_access($user);

// Warn-only (action=warn) — REMOVED in 5.x without a 1:1 swap.
// These were deprecated-only in 4.x via deprecated-4.x.php and slipped past the
// 3→4 sweep before bd elgg-migrate-5h0u4 / 4pye6.
forward('activity');
add_translation('en', ['greeting' => 'hi']);
elgg_register_entity_type('object', 'foo');
elgg_register_admin_menu_item('configure', $item);

// Not in MAP — must not be flagged
elgg_echo('greeting');
