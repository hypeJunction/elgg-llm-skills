-- Pre-fix `metadata` schema.
--
-- This DDL is the exact current shape of the table as produced by the Elgg
-- migration chain (engine/schema/migrations/*), verified byte-for-byte against
-- `SHOW CREATE TABLE elgg_metadata` on a live install. It intentionally does NOT
-- contain the (entity_guid, name) composite index — that is what this benchmark
-- adds and measures. The only change from the real table is the dropped
-- `elgg_` prefix.
USE bench;

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
