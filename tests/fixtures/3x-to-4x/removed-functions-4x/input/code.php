<?php

// Uses deprecated functions
validate_email_address($email);
validate_password($password);
forward('activity');

elgg_set_plugin_setting('key', 'value', 'myplugin');
elgg_get_filter_tabs('context');

// This one is fine — not in the removed list
elgg_echo('greeting');
