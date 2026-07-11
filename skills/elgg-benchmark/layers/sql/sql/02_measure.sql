-- Deterministic measurement, run identically BEFORE and AFTER the index.
-- Emits: the chosen access path (EXPLAIN) and rows actually touched by the
-- storage engine (Handler_read_* counters) for 10,000 getIDsByName lookups.
USE bench;

SELECT '### EXPLAIN point lookup (WHERE entity_guid = ? AND name = ?)' AS marker;
EXPLAIN SELECT id FROM metadata WHERE entity_guid = 12345 AND name = 'name_5';

-- Warm the buffer pool so we measure access-path efficiency, not cold-cache I/O.
CALL point_lookups(2000);

SELECT '### Handler counters for 10,000 getIDsByName lookups' AS marker;
FLUSH STATUS;
CALL point_lookups(10000);
SHOW SESSION STATUS WHERE Variable_name IN ('Handler_read_key','Handler_read_next');
