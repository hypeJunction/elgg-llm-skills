<?php

return [
    'plugin' => [
        'name' => 'Notes',
        'id' => 'notes',
    ],
    'entities' => [
        [
            'type' => 'object',
            'subtype' => 'note',
            'class' => 'Notes\Note',
        ],
    ],
    'cli' => [
        'commands' => [
            \Notes\Cli\DoctorCommand::class,
        ],
    ],
];
