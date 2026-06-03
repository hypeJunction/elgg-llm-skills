<?php
if (($entity instanceof \ElggObject && $entity->getSubtype() === 'blog')) {
    // blog post
}
if ($entity instanceof \ElggUser) {
    // user check
}
$result = ($entity instanceof \ElggObject && $entity->getSubtype() === 'page');
