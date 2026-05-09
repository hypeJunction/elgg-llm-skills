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
        [
            'type' => 'object',
            'subtype' => 'note_album',
            'class' => 'HypeNotes\NoteAlbum',
        ],
    ],
    'actions' => [
        'hypenotes/save' => [],
        'hypenotes/delete' => [],
    ],
];
