<?php

namespace HypeMyPlugin\Actions;

class Save
{
    public function execute(): void
    {
        // Read-side: comparison with 'yes'
        $enabled = elgg_get_plugin_setting('enable_feature', 'hypemyplugin');
        if ($enabled === 'yes') {
            // do something
        }

        // Read-side: comparison with 'no' (negation)
        $sidebar = elgg_get_plugin_setting('show_sidebar', 'hypemyplugin');
        if ($sidebar === 'no') {
            // hide sidebar
        }

        // Write-side: set to 'yes'
        elgg_set_plugin_setting('enable_feature', 'yes', 'hypemyplugin');

        // Write-side: set to 'no'
        elgg_set_plugin_setting('show_sidebar', 'no', 'hypemyplugin');

        // This should NOT be flagged (not a yes/no value)
        elgg_set_plugin_setting('title', 'My Title', 'hypemyplugin');

        // getSetting() on plugin object
        $plugin = elgg_get_plugin_from_id('hypemyplugin');
        if ($plugin->getSetting('debug_mode') === 'yes') {
            // debug
        }
    }
}
