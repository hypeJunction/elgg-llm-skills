<?php

// Basic usage
$path = elgg_get_plugins_path() . 'myplugin/lib/helpers.php';

// In require
require_once elgg_get_plugins_path() . 'myplugin/vendors/custom.php';

// In function call
elgg_register_library('myplugin:helpers', elgg_get_plugins_path() . 'myplugin/lib/helpers.php');
