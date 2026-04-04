<?php

$posts = elgg_get_entities_from_metadata([
    'type' => 'object',
    'subtype' => 'blog',
    'metadata_name' => 'status',
    'metadata_value' => 'published',
]);

$list = elgg_list_entities_from_relationship([
    'type' => 'object',
    'relationship' => 'tagged',
    'relationship_guid' => $user->guid,
]);

$batch = new ElggBatch('elgg_get_entities_from_metadata', [
    'type' => 'object',
    'subtype' => 'file',
]);
