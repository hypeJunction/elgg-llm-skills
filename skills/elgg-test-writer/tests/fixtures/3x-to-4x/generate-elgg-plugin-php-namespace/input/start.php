<?php

namespace MyPlugin\Core;

elgg_register_event_handler('init', 'system', function () {
    elgg_register_action('myplugin/save', __DIR__ . '/actions/myplugin/save.php');

    // Array-style: [__NAMESPACE__ . '\Class', 'method']
    elgg_register_plugin_hook_handler('register', 'menu:entity', [__NAMESPACE__ . '\\Hooks', 'entityMenu']);

    // String-style: __NAMESPACE__ . '\Class::method'
    elgg_register_plugin_hook_handler('register', 'menu:river', __NAMESPACE__ . '\\Hooks::riverMenu');

    // Invokable class: __NAMESPACE__ . '\Class'
    elgg_register_plugin_hook_handler('entity:url', 'object', __NAMESPACE__ . '\\Router');

    elgg_register_event_handler('create', 'object', [__NAMESPACE__ . '\\Hooks', 'onCreate']);
});
