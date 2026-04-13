<?php
return [
    'entities' => [
        [
            'type' => 'object',
            'subtype' => 'myplugin_item',
            'class' => \MyPlugin\Item::class,
        ],
    ],
    'actions' => [
        'myplugin/save' => [],
    ],
    'routes' => [
        'myplugin:view' => [
            'path' => '/myplugin/view/{guid}',
            'resource' => 'myplugin/view',
        ],
    ],
];
