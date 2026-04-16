<?php

// These all use camelCase plugin IDs — should be flagged and rewritten
$plugin = elgg_get_plugin_from_id('hypeDirectory');
$setting = elgg_get_plugin_setting('key', 'hypeDirectory');
$userSetting = elgg_get_plugin_user_setting('key', 0, 'hypeDirectory');

// Mixed-case variants
elgg_get_plugin_from_id('HypeDirectory');
elgg_get_plugin_setting('timeout', 'hypeSeo');

// These are already lowercase — should NOT be flagged
elgg_get_plugin_from_id('hypedirectory');
elgg_get_plugin_setting('key', 'hypedirectory');
elgg_get_plugin_user_setting('key', 0, 'hypedirectory');

// Dynamic variable — cannot be rewritten, should NOT be flagged
elgg_get_plugin_from_id($plugin_id);
elgg_get_plugin_setting('key', $plugin_id);
