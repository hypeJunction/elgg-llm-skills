<?php

// Unambiguous renames
$name = $plugin->getFriendlyName();
$item->getWeight();
$item->setWeight(100);
$time = $riverItem->getPostedTime();

// Removed (no replacement) - should warn
$entity->isFullyLoaded();
$entity->clearAllFiles();
$values = $entity->getExportableValues();

// Ambiguous - should warn only, not rename
$fileSize = $file->size();
$val = $data->get('key');
$entity->addToSite($site);
