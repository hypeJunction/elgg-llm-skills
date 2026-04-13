<?php

require_once __DIR__ . '/autoloader.php';

elgg_register_event_handler('init', 'system', function () {
    elgg_register_action('myplugin/save', __DIR__ . '/actions/myplugin/save.php');

    elgg_register_route('myplugin:view', [
        'path' => '/myplugin/view/{guid}',
        'resource' => 'myplugin/view',
    ]);
});
