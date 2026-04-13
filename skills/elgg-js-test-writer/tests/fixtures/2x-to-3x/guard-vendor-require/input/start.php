<?php

require_once __DIR__ . '/vendor/autoload.php';

elgg_register_event_handler('init', 'system', 'my_plugin_init');

function my_plugin_init() {
    // plugin init
}
