<?php

namespace MyPlugin;

class Hooks {

	public static function entityMenu($hook, $type, $return, $params) {
		$entity = elgg_extract('entity', $params);
		if (!$entity) {
			return;
		}

		$return[] = \ElggMenuItem::factory([
			'name' => 'myplugin',
			'text' => 'My Plugin',
			'href' => $entity->getURL(),
		]);

		return $return;
	}

	public static function filterUrlVars($hook, $type, $return, $params) {
		$href = $params['href'];
		$is_trusted = elgg_extract('is_trusted', $params, false);

		if (!$is_trusted) {
			$return['rel'] = 'nofollow';
		}

		return $return;
	}
}
