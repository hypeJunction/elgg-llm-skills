-- BEFORE: river accessibility via INNER/LEFT JOIN on entities.
-- Deterministic verdict = EXPLAIN plan + Handler_read_* / tmp-table counters for
-- ONE execution of the listing query. Run identically to 04_measure_after.sql.
USE bench;

SELECT '### BEFORE — EXPLAIN (joins entities; optimizer picks the leading table)' AS marker;
EXPLAIN
SELECT DISTINCT rv.*
FROM river rv
INNER JOIN entities se ON se.guid = rv.subject_guid
INNER JOIN entities oe ON oe.guid = rv.object_guid
LEFT  JOIN entities te ON te.guid = rv.target_guid
WHERE ((se.enabled = 'yes') AND (se.deleted = 'no')
       AND (NOT EXISTS (SELECT 1 FROM metadata pp_md WHERE ((pp_md.entity_guid = se.guid) OR (pp_md.entity_guid = se.owner_guid)) AND (pp_md.name = 'plugin:user_setting:private_profiles:user_activity_setting') AND (pp_md.value = 'members')))
       AND (se.access_id IN (2, -5)))
  AND ((oe.enabled = 'yes') AND (oe.deleted = 'no')
       AND (NOT EXISTS (SELECT 1 FROM metadata pp_md WHERE ((pp_md.entity_guid = oe.guid) OR (pp_md.entity_guid = oe.owner_guid)) AND (pp_md.name = 'plugin:user_setting:private_profiles:user_activity_setting') AND (pp_md.value = 'members')))
       AND (oe.access_id IN (2, -5))
       AND (((oe.type = 'user') AND (oe.subtype IN ('user'))) OR ((oe.type = 'group') AND (oe.subtype IN ('group'))) OR ((oe.type = 'object') AND (oe.subtype IN ('file','comment','blog','page','bookmarks','thewire','library_entry','library_file','discussion','feedback','hjplace','hjwall','wall_tag')))))
  AND (((te.enabled = 'yes') AND (te.deleted = 'no')
        AND (NOT EXISTS (SELECT 1 FROM metadata pp_md WHERE ((pp_md.entity_guid = te.guid) OR (pp_md.entity_guid = te.owner_guid)) AND (pp_md.name = 'plugin:user_setting:private_profiles:user_activity_setting') AND (pp_md.value = 'members')))
        AND (te.access_id IN (2, -5))) OR (te.guid IS NULL))
ORDER BY rv.posted DESC
LIMIT 20;

-- Warm the buffer pool so counters reflect access-path efficiency, not cold I/O.
CALL run_before(3);

SELECT '### BEFORE — Handler_read_* and tmp tables for ONE execution' AS marker;
FLUSH STATUS;
CALL run_before(1);
SHOW SESSION STATUS WHERE Variable_name IN
  ('Handler_read_key','Handler_read_next','Handler_read_rnd_next','Handler_read_first',
   'Created_tmp_tables','Created_tmp_disk_tables','Sort_rows','Sort_scan');
