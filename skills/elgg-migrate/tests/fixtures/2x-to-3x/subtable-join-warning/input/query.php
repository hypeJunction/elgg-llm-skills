<?php

$query = "SELECT * FROM elgg_entities e JOIN elgg_users_entity ue ON e.guid = ue.guid";

$sql = "SELECT * FROM {$CONFIG->dbprefix}objects_entity WHERE title = 'test'";

$join = "JOIN elgg_groups_entity ge ON e.guid = ge.guid";

$normal = "SELECT * FROM elgg_entities WHERE type = 'object'";
