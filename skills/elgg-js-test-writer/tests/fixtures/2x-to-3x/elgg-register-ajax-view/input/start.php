<?php

// Should be removed
elgg_register_ajax_view('myplugin/sidebar');
elgg_register_ajax_view('myplugin/popup');

// Should be kept (different function)
elgg_register_event_handler('init', 'system', 'myplugin_init');
elgg_extend_view('page/elements/sidebar', 'myplugin/sidebar');
