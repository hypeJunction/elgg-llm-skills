<?php

use MyPlugin\Hooks;
use MyPlugin\Router;

require_once __DIR__ . '/autoloader.php';

elgg_register_event_handler('init', 'system', function () {
    elgg_register_action('myplugin/save', __DIR__ . '/actions/myplugin/save.php');
    elgg_register_action('myplugin/delete', __DIR__ . '/actions/myplugin/delete.php', 'admin');

    elgg_register_route('myplugin:view', [
        'path' => '/myplugin/view/{guid}',
        'resource' => 'myplugin/view',
    ]);

    elgg_register_plugin_hook_handler('register', 'menu:entity', [Hooks::class, 'entityMenu']);
    elgg_register_plugin_hook_handler('register', 'menu:river', [Hooks::class, 'riverMenu']);
    elgg_register_plugin_hook_handler('entity:url', 'object', [Router::class, 'urlHandler']);

    elgg_register_event_handler('create', 'object', [Hooks::class, 'onCreate']);

    elgg_extend_view('elgg.css', 'myplugin/stylesheet.css');
    elgg_extend_view('elgg.js', 'myplugin/init.js');
    elgg_extend_view('page/layouts/elements/filter', 'myplugin/container', 100);

    elgg_register_widget_type('myplugin', elgg_echo('myplugin'), elgg_echo('myplugin:widget:desc'));

    elgg_register_notification_event('object', 'myplugin_item', ['create', 'publish']);

    elgg_register_entity_type('object', 'myplugin_item');
});
