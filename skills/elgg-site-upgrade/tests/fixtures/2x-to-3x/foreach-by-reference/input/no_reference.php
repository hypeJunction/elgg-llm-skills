<?php

/**
 * Normal foreach without by-reference — should not be flagged.
 */
function safe_hook($hook, $type, $return, $params) {
    foreach ($return as $item) {
        if ($item instanceof ElggMenuItem) {
            $item->setSection('default');
        }
    }
    return $return;
}

/**
 * Foreach by reference on a local array — should not be flagged.
 */
function local_array_ref() {
    $data = [1, 2, 3];
    foreach ($data as &$val) {
        $val *= 2;
    }
    return $data;
}
