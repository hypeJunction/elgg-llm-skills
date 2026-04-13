<?php

if (is_memcache_available()) {
    $cache = true;
}

$tables = get_db_tables();

$id = elgg_get_metastring_id('likes');

$result = is_memcache_available() ? 'memcache' : 'filecache';
