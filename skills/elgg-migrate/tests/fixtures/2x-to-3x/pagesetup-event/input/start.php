<?php

// Should be removed
elgg_register_event_handler('pagesetup', 'system', 'myplugin_pagesetup');
elgg_register_event_handler('pagesetup', 'system', 'myplugin_sidebar_setup');

// Should be kept (not pagesetup)
elgg_register_event_handler('init', 'system', 'myplugin_init');
elgg_register_event_handler('create', 'object', 'myplugin_create_handler');
