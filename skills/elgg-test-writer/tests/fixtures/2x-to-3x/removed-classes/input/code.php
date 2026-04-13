<?php

// Renames
$file = new FilePluginFile();
$reply = new ElggDiscussionReply();

// Removed classes (should warn)
$cache = new ElggMemcache('my_cache');
$fileCache = new ElggFileCache('/tmp/cache');

// In instanceof
if ($entity instanceof FilePluginFile) {
    $entity->save();
}

// In type hints
function processFile(FilePluginFile $file): void {
    // ...
}
