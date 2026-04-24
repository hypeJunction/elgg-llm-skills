<?php

class Bootstrap {

    public function boot(): void {
        // Unregister core handlers using old procedural names — these will silently
        // fail in 4.x because the functions were renamed to Class::method handlers.
        elgg_unregister_plugin_hook_handler('register', 'menu:entity', '_elgg_entity_menu_setup');
        elgg_unregister_event_handler('create', 'object', '_elgg_filestore_move_icons');
        elgg_unregister_plugin_hook_handler('register', 'menu:owner_block', '_groups_owner_block_menu');
        elgg_unregister_plugin_hook_handler('register', 'menu:user_hover', '_members_user_hover_menu');

        // This should NOT be flagged — callback is a valid class-based handler
        elgg_unregister_plugin_hook_handler('register', 'menu:entity', 'MyPlugin\\Menus::setup');
    }
}
