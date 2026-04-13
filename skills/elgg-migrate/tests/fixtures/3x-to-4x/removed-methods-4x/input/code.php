<?php

$plugin = elgg_get_plugin_from_id('myplugin');
$setting = $plugin->getUserSetting('name', $user_guid);
$plugin->setUserSetting('name', 'value', $user_guid);

$group->addObjectToGroup($entity);
$group->removeObjectFromGroup($entity);

$email->getRecipient();
