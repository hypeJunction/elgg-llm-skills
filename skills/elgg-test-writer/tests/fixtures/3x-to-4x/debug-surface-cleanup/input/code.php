<?php

declare(strict_types=1);

use Elgg\Logger;

/**
 * A plugin file with debug residue left over from development.
 */
function my_plugin_process($entity) {
    elgg_dump($entity);

    // var_dump($entity->guid);

    $level = Logger::ERROR;
    $level2 = Logger::WARNING;
    $level3 = Logger::INFO;
    $level4 = Logger::NOTICE;
    $level5 = Logger::DEBUG;

    echo $_REQUEST['foo'];

    return true;
}

function my_plugin_other($data) {
    // print_r($data);
    // error_log('debug: ' . print_r($data, true));
    return $data;
}
