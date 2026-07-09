<?php

declare(strict_types=1);

use Elgg\Logger;

/**
 * A plugin file with debug residue left over from development.
 */
function my_plugin_process($entity) {

    $level = 'error';
    $level2 = 'warning';
    $level3 = 'info';
    $level4 = 'notice';
    $level5 = 'debug';

    echo $_REQUEST['foo'];

    return true;
}

function my_plugin_other($data) {
    return $data;
}
