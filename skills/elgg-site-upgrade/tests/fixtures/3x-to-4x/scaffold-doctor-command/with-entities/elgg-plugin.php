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
        [
            'type' => 'object',
            'subtype' => 'note_album',
            'class' => 'Notes\NoteAlbum',
        ],
    ],
    'actions' => [
        'notes/save' => [],
        'notes/delete' => [],
    ],
];
