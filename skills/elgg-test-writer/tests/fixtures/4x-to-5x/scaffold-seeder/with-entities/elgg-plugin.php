<?php

return [
    'plugin' => [
        'name' => 'Tracker',
        'id' => 'tracker',
    ],
    'entities' => [
        [
            'type' => 'object',
            'subtype' => 'tracker_item',
            'class' => 'Tracker\TrackerItem',
            'capabilities' => [
                'commentable' => true,
            ],
        ],
        [
            'type' => 'object',
            'subtype' => 'tracker_list',
            'class' => 'Tracker\TrackerList',
        ],
    ],
    'events' => [],
];
