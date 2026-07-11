-- Pre-change schema for the river "driving table" benchmark.
--
-- Three tables (entities, river, metadata) with the EXACT indexes produced by the
-- Elgg 7.x migration chain, verified against `SHOW CREATE TABLE` on a live install
-- (the only change is the dropped `elgg_` prefix). No schema/index change is made
-- by this benchmark: the change under test is the QUERY SHAPE that
-- Elgg\Database\River::buildEntityClauses() emits (INNER/LEFT JOIN on the entities
-- table -> correlated EXISTS() semi-joins). Both query forms run on identical data.
USE bench;

CREATE TABLE entities (
  `guid` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `type` enum('object','user','group','site') NOT NULL,
  `subtype` varchar(255) CHARACTER SET utf8mb4 DEFAULT NULL,
  `owner_guid` bigint(20) unsigned NOT NULL,
  `container_guid` bigint(20) unsigned NOT NULL,
  `access_id` int(11) NOT NULL,
  `time_created` int(11) NOT NULL,
  `time_updated` int(11) NOT NULL,
  `last_action` int(11) NOT NULL DEFAULT '0',
  `enabled` enum('yes','no') NOT NULL DEFAULT 'yes',
  `deleted` enum('yes','no') NOT NULL DEFAULT 'no',
  `time_deleted` int(11) NOT NULL DEFAULT '0',
  PRIMARY KEY (`guid`),
  KEY `type` (`type`),
  KEY `owner_guid` (`owner_guid`),
  KEY `container_guid` (`container_guid`),
  KEY `access_id` (`access_id`),
  KEY `time_created` (`time_created`),
  KEY `time_updated` (`time_updated`),
  KEY `type_subtype` (`type`,`subtype`(50)),
  KEY `type_subtype_owner` (`type`,`subtype`(50),`owner_guid`),
  KEY `type_subtype_container` (`type`,`subtype`(50),`container_guid`),
  KEY `deleted` (`deleted`),
  KEY `time_deleted` (`time_deleted`),
  KEY `subtype` (`subtype`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 ROW_FORMAT=DYNAMIC;

CREATE TABLE river (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `action_type` varchar(32) NOT NULL,
  `view` mediumtext NOT NULL,
  `subject_guid` bigint(20) unsigned NOT NULL,
  `object_guid` bigint(20) unsigned NOT NULL,
  `target_guid` bigint(20) unsigned NOT NULL,
  `annotation_id` int(11) NOT NULL,
  `posted` int(11) NOT NULL,
  `last_action` int(11) NOT NULL,
  PRIMARY KEY (`id`),
  KEY `action_type` (`action_type`),
  KEY `subject_guid` (`subject_guid`),
  KEY `object_guid` (`object_guid`),
  KEY `target_guid` (`target_guid`),
  KEY `annotation_id` (`annotation_id`),
  KEY `posted` (`posted`),
  KEY `last_action` (`last_action`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE metadata (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `entity_guid` bigint(20) unsigned NOT NULL,
  `name` mediumtext NOT NULL,
  `value` longtext NOT NULL,
  `value_type` enum('integer','text','bool') NOT NULL,
  `time_created` int(11) NOT NULL,
  PRIMARY KEY (`id`),
  KEY `entity_guid` (`entity_guid`),
  KEY `name` (`name`(50)),
  KEY `value` (`value`(50))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 ROW_FORMAT=DYNAMIC;

-- Deterministic numbers table 0..999999 built from a digit cross-join.
-- No RAND(), no clock: identical on every run and every engine.
CREATE TABLE seq (n INT PRIMARY KEY);
INSERT INTO seq (n)
SELECT d5.d*100000 + d4.d*10000 + d3.d*1000 + d2.d*100 + d1.d*10 + d0.d AS n
FROM       (SELECT 0 d UNION SELECT 1 UNION SELECT 2 UNION SELECT 3 UNION SELECT 4 UNION SELECT 5 UNION SELECT 6 UNION SELECT 7 UNION SELECT 8 UNION SELECT 9) d0
CROSS JOIN (SELECT 0 d UNION SELECT 1 UNION SELECT 2 UNION SELECT 3 UNION SELECT 4 UNION SELECT 5 UNION SELECT 6 UNION SELECT 7 UNION SELECT 8 UNION SELECT 9) d1
CROSS JOIN (SELECT 0 d UNION SELECT 1 UNION SELECT 2 UNION SELECT 3 UNION SELECT 4 UNION SELECT 5 UNION SELECT 6 UNION SELECT 7 UNION SELECT 8 UNION SELECT 9) d2
CROSS JOIN (SELECT 0 d UNION SELECT 1 UNION SELECT 2 UNION SELECT 3 UNION SELECT 4 UNION SELECT 5 UNION SELECT 6 UNION SELECT 7 UNION SELECT 8 UNION SELECT 9) d3
CROSS JOIN (SELECT 0 d UNION SELECT 1 UNION SELECT 2 UNION SELECT 3 UNION SELECT 4 UNION SELECT 5 UNION SELECT 6 UNION SELECT 7 UNION SELECT 8 UNION SELECT 9) d4
CROSS JOIN (SELECT 0 d UNION SELECT 1 UNION SELECT 2 UNION SELECT 3 UNION SELECT 4 UNION SELECT 5 UNION SELECT 6 UNION SELECT 7 UNION SELECT 8 UNION SELECT 9) d5;
