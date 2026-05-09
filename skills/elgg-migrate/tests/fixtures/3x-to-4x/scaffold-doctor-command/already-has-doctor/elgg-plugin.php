<?php

return [
    'plugin' => [
        'name' => 'Hype Notes',
        'id' => 'hypenotes',
    ],
    'entities' => [
        [
            'type' => 'object',
            'subtype' => 'note',
            'class' => 'HypeNotes\Note',
        ],
    ],
    'cli' => [
        'commands' => [
            \HypeNotes\Cli\DoctorCommand::class,
        ],
    ],
];
