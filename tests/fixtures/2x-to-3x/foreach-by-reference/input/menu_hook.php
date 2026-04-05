<?php

/**
 * Example plugin hook that iterates by reference over menu items.
 * This pattern breaks in Elgg 3.x because MenuItems is a Traversable.
 */
function my_plugin_menu_setup($hook, $type, $return, $params) {

    $primary_actions = ['edit', 'delete'];

    foreach ($return as &$item) {
        if (!$item instanceof ElggMenuItem) {
            continue;
        }

        if (in_array($item->getName(), $primary_actions)) {
            $item = null;
            continue;
        }

        $item->setSection('default');
    }

    return array_filter($return);
}
