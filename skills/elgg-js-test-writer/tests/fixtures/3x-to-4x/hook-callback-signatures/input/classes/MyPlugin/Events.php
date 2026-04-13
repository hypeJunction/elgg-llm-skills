<?php

namespace MyPlugin;

class Events {

	public static function onCreate($event, $type, $entity) {
		if (!$entity instanceof \ElggObject) {
			return;
		}

		if ($type !== 'object') {
			return;
		}

		error_log("Created entity: {$entity->guid}");
	}
}
