<?php

// Hard removals (status=removed)
validate_email_address($email);
validate_password($password);

elgg_set_plugin_setting('key', 'value', 'myplugin');
elgg_get_filter_tabs('context');

// Removed in 4.0 without deprecation — caused csv_process activation fatal (bd 4pye6)
elgg_register_admin_menu_item('configure', $item);

// Deprecated in 4.x (still ships via deprecated-4.x.php). Must not be flagged
// as "removed in 4.0" — that was a false positive that blocked triage.
forward('activity');                       // deprecated-4.0.php
elgg_register_entity_type('object', 'foo'); // deprecated-4.1.php
add_translation('en', ['key' => 'value']); // deprecated-4.3.php → REMOVED in 5.x (bd 5h0u4)

// This one is fine — not in the removed list
elgg_echo('greeting');
