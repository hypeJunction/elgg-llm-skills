<?php

// These all use camelCase plugin IDs — should be flagged and rewritten
$plugin = elgg_get_plugin_from_id('myDirectory');
$setting = elgg_get_plugin_setting('key', 'myDirectory');
$userSetting = elgg_get_plugin_user_setting('key', 0, 'myDirectory');

// Mixed-case variants
elgg_get_plugin_from_id('MyDirectory');
elgg_get_plugin_setting('timeout', 'mySeo');

// These are already lowercase — should NOT be flagged
elgg_get_plugin_from_id('mydirectory');
elgg_get_plugin_setting('key', 'mydirectory');
elgg_get_plugin_user_setting('key', 0, 'mydirectory');

// Dynamic variable — cannot be rewritten, should NOT be flagged
elgg_get_plugin_from_id($plugin_id);
elgg_get_plugin_setting('key', $plugin_id);
