<?php

// Complex action — 50+ LOC, multiple branches and loops
$guid = (int) get_input('guid');
$title = get_input('title');
$description = get_input('description');
$tags = get_input('tags', []);
$access_id = (int) get_input('access_id', ACCESS_LOGGED_IN);

if (!$guid) {
    return elgg_error_response(elgg_echo('error:missing_data'));
}

$entity = get_entity($guid);

if (!$entity) {
    return elgg_error_response(elgg_echo('error:missing_data'));
}

if (!$entity->canEdit()) {
    return elgg_error_response(elgg_echo('error:permission_denied'));
}

if (empty($title)) {
    return elgg_error_response(elgg_echo('error:missing_data'));
}

$entity->title = $title;
$entity->description = $description;
$entity->access_id = $access_id;

$cleaned_tags = [];
foreach ($tags as $tag) {
    $tag = trim($tag);
    if (!empty($tag)) {
        $cleaned_tags[] = $tag;
    }
}

$entity->tags = $cleaned_tags;

$metadata = get_input('metadata', []);
foreach ($metadata as $key => $value) {
    if (is_string($key) && !empty($key)) {
        $entity->$key = $value;
    }
}

if (!$entity->save()) {
    return elgg_error_response(elgg_echo('error:save_failed'));
}

$relationships = get_input('relationships', []);
foreach ($relationships as $rel) {
    if (isset($rel['guid']) && isset($rel['type'])) {
        $entity->addRelationship((int) $rel['guid'], $rel['type']);
    }
}

return elgg_ok_response(['guid' => $entity->guid]);
