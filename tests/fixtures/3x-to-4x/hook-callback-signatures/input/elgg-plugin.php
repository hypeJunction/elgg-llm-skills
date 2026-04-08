<?php

use MyPlugin\Hooks;
use MyPlugin\Events;

return [
	'bootstrap' => \MyPlugin\Bootstrap::class,

	'hooks' => [
		'register' => [
			'menu:entity' => [
				Hooks::class . '::entityMenu' => [],
			],
		],
		'view_vars' => [
			'output/url' => [
				Hooks::class . '::filterUrlVars' => [],
			],
		],
	],

	'events' => [
		'create' => [
			'object' => [
				Events::class . '::onCreate' => [],
			],
		],
	],

	'actions' => [
		'myplugin/save' => [],
	],
];
