<?php

// Generic Elgg 2.x plugin with a class-based page handler callback

require_once __DIR__ . '/autoloader.php';

use MyPlugin\Router;
use MyPlugin\Menus;

elgg_register_event_handler('init', 'system', function () {
    // Register page handler
    elgg_register_page_handler('myplugin', [Router::class, 'handlePages']);

    // Register actions
    elgg_register_action('myplugin/save', __DIR__ . '/actions/myplugin/save.php');

    // Register menu hooks
    elgg_register_plugin_hook_handler('register', 'menu:entity', [Menus::class, 'setupEntityMenu']);
});
