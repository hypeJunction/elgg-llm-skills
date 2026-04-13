<?php

// Should be removed - navigates UP to Elgg root autoloader
require_once dirname(dirname(dirname(__FILE__))) . '/vendor/autoload.php';

// Should be kept - not a vendor autoload
require_once __DIR__ . '/lib/functions.php';

// Regular code
elgg_register_event_handler('init', 'system', 'myplugin_init');
