<?php
namespace MyPlugin;

class Setup {
    public function createItem(\ElggObject $entity): void {
        $entity->subtype = 'my_item';
        $entity->type = 'object';
        $entity->enabled = 'yes';
        $entity->access_id = ACCESS_PUBLIC;
    }

    public function disableItem(\ElggObject $entity): void {
        $entity->enabled = 'no';
    }

    public function toggleBan(\ElggUser $user): void {
        $user->banned = 'yes';
        $user->admin = 'yes';
    }
}
