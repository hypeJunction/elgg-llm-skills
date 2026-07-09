<?php

namespace HypeMyPlugin\Actions;

class Save
{
    public function execute(): void
    {
        // Read-side: direct comparison with 'yes' — gets rewritten
        if (elgg_get_plugin_setting('enable_feature', 'hypemyplugin') === 'yes') {
            // do something
        }

        // Read-side: direct comparison with 'no' — gets rewritten
        if (elgg_get_plugin_setting('show_sidebar', 'hypemyplugin') === 'no') {
            // hide sidebar
        }

        // Write-side: set to 'yes' — gets rewritten
        elgg_set_plugin_setting('enable_feature', 'yes', 'hypemyplugin');

        // Write-side: set to 'no' — gets rewritten
        elgg_set_plugin_setting('show_sidebar', 'no', 'hypemyplugin');

        // This should NOT be flagged (not a yes/no value)
        elgg_set_plugin_setting('title', 'My Title', 'hypemyplugin');

        // getSetting() on plugin object — gets rewritten
        $plugin = elgg_get_plugin_from_id('hypemyplugin');
        if ($plugin->getSetting('debug_mode') === 'yes') {
            // debug
        }
    }
}
