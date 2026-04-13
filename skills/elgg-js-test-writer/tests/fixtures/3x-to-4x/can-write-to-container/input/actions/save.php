<?php
$container = get_entity($container_guid);
if (!$container->canWriteToContainer()) {
    return elgg_error_response('No permission');
}

// This one is fine - has all 3 args
if (!$container->canWriteToContainer(0, 'object', 'blog')) {
    return elgg_error_response('No permission');
}

// This one only has user_guid
if (!$container->canWriteToContainer($user->guid)) {
    return elgg_error_response('No permission');
}
