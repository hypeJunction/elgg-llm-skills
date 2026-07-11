-- Deterministic dataset: 1,000,000 metadata rows = 50,000 entities x 20 rows each.
--
-- Shape chosen to model the real hot path (MetadataTable::getIDsByName and the
-- metadata preload): each entity carries many metadata rows under different
-- names, so a lookup by (entity_guid, name) must sift ~20 rows per entity when
-- only the single-column `entity_guid` index exists.
--
--   entity_guid = (n DIV 20) + 1        -> 50,000 entities, 20 rows each
--   name        = 'name_' + (n MOD 30)  -> 30 distinct names, ~20 per entity
--   value       = 'value_' + (n MOD 1000)
--
-- Fully reproducible: derived from `seq`, no RAND()/clock.
USE bench;

INSERT INTO metadata (entity_guid, name, value, value_type, time_created)
SELECT (n DIV 20) + 1                AS entity_guid,
       CONCAT('name_',  n MOD 30)    AS name,
       CONCAT('value_', n MOD 1000)  AS value,
       'text'                        AS value_type,
       n                             AS time_created
FROM seq;

-- Driver table for the aggregate probe: 10,000 distinct entity_guids with their
-- own PK, so the driver contributes ~10k reads (not a full scan) and the metadata
-- access path dominates the measurement.
CREATE TABLE drv (g BIGINT UNSIGNED PRIMARY KEY);
INSERT INTO drv SELECT DISTINCT (n DIV 20) + 1 FROM seq WHERE n < 200000;

-- Stored procedure replaying the exact getIDsByName query N times over a
-- deterministic spread of (entity_guid, name) pairs.
DROP PROCEDURE IF EXISTS point_lookups;
DELIMITER //
CREATE PROCEDURE point_lookups(IN iters INT)
BEGIN
  DECLARE i INT DEFAULT 0;
  DECLARE g BIGINT;
  DECLARE nm VARCHAR(32);
  DECLARE dummy INT;
  WHILE i < iters DO
    SET g  = (i * 7) MOD 50000 + 1;
    SET nm = CONCAT('name_', (i * 13) MOD 30);
    SELECT id INTO dummy FROM metadata WHERE entity_guid = g AND name = nm LIMIT 1;
    SET i = i + 1;
  END WHILE;
END//
DELIMITER ;

ANALYZE TABLE metadata;
SELECT COUNT(*) AS metadata_rows, COUNT(DISTINCT entity_guid) AS entities FROM metadata;
