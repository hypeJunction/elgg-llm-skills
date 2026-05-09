<?php

return [
    'plugin' => [
        'name' => 'Hype Tracker',
        'id' => 'hypetracker',
    ],
    'entities' => [
        [
            'type' => 'object',
            'subtype' => 'tracker_item',
            'class' => 'HypeTracker\TrackerItem',
            'capabilities' => [
                'commentable' => true,
            ],
        ],
        [
            'type' => 'object',
            'subtype' => 'tracker_list',
            'class' => 'HypeTracker\TrackerList',
        ],
    ],
    'events' => [],
];
