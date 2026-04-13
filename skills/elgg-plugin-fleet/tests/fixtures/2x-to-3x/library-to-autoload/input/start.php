<?php

elgg_register_event_handler('init', 'system', function () {
    elgg_register_library('myplugin:helpers', __DIR__ . '/lib/helpers.php');
    elgg_load_library('myplugin:helpers');

    elgg_register_action('myplugin/save', __DIR__ . '/actions/save.php');
});
