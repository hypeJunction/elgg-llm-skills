<?php
if (elgg_instanceof($entity, 'object', 'blog')) {
    // blog post
}
if (elgg_instanceof($entity, 'user')) {
    // user check
}
$result = elgg_instanceof($entity, 'object', 'page');
