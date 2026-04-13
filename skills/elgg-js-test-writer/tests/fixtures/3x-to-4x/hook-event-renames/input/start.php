<?php

elgg_register_plugin_hook_handler('profile:fields', 'group', 'my_group_fields');
elgg_register_plugin_hook_handler('profile:fields', 'user', 'my_user_fields');
elgg_register_plugin_hook_handler('usersettings', 'plugin', 'my_settings');

elgg_register_event_handler('created', 'river', 'my_river_handler');
elgg_register_event_handler('creating', 'river', 'my_river_blocker');

// This one is fine — not deprecated
elgg_register_event_handler('create', 'object', 'my_object_handler');
