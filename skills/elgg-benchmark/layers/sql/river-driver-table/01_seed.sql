-- Deterministic dataset modelling a realistic mid-size Elgg site, sized to the
-- reference profile (references/site-profile.md) that reproduces the optimizer's
-- bad join order for the river listing:
--
--   entities : 23,000  (1,500 users, 200 groups, 21,300 objects)
--              ~11,500 objects deleted='no' -> the "deleted" index looks like a
--              cheap ~11.5k-row leading table to the optimizer
--   river    :  4,278  rows, posted/last_action strictly descending
--   metadata : ~207,030 rows (~9 per entity) + 30 private_profiles opt-out rows
--
-- All values are derived from `seq` (a formula) — no RAND(), no clock — so every
-- run on every engine produces byte-identical data. The proportions, not the
-- absolute counts, drive the plan; they mirror the captured production shape.
USE bench;

-- ---------------------------------------------------------------------------
-- entities: users, groups, objects across the 13 activity-registered subtypes.
--   access_id 2 = public; 100000 = a private ACL (NOT accessible -> filtered).
--   ~20% of objects private, ~46% deleted (independent moduli).
-- ---------------------------------------------------------------------------
INSERT INTO entities (guid, type, subtype, owner_guid, container_guid, access_id,
                      time_created, time_updated, last_action, enabled, deleted, time_deleted)
SELECT n + 1 AS guid,
       CASE WHEN n < 1500 THEN 'user' WHEN n < 1700 THEN 'group' ELSE 'object' END AS type,
       CASE WHEN n < 1500 THEN 'user'
            WHEN n < 1700 THEN 'group'
            ELSE ELT((n MOD 13) + 1, 'file','comment','blog','page','bookmarks','thewire',
                     'library_entry','library_file','discussion','feedback','hjplace','hjwall','wall_tag')
       END AS subtype,
       CASE WHEN n < 1700 THEN n + 1 ELSE (n MOD 1500) + 1 END AS owner_guid,
       CASE WHEN n < 1700 THEN n + 1 ELSE (n MOD 1500) + 1 END AS container_guid,
       CASE WHEN n < 1700 THEN 2 WHEN (n MOD 5) = 0 THEN 100000 ELSE 2 END AS access_id,
       1000000000 + n, 1000000000 + n, 1000000000 + n,
       'yes' AS enabled,
       CASE WHEN n < 1700 THEN 'no' WHEN (n MOD 100) < 54 THEN 'no' ELSE 'yes' END AS deleted,
       0
FROM seq WHERE n < 23000;

-- ---------------------------------------------------------------------------
-- river: subject = a user, object = spread across the object range (some land on
--   deleted/private objects and get filtered, as in production), target = a group
--   for 10% of rows and "no target" (guid 0) for the rest. posted DESC = 2e9 - n.
-- ---------------------------------------------------------------------------
INSERT INTO river (id, action_type, view, subject_guid, object_guid, target_guid,
                   annotation_id, posted, last_action)
SELECT n + 1, 'foo:bar', 'river/foo',
       (n MOD 1500) + 1                               AS subject_guid,
       1701 + ((n * 7) MOD 21300)                     AS object_guid,
       CASE WHEN (n MOD 10) = 0 THEN 1500 + ((n MOD 200) + 1) ELSE 0 END AS target_guid,
       0,
       2000000000 - n                                 AS posted,
       2000000000 - n                                 AS last_action
FROM seq WHERE n < 4278;

-- ---------------------------------------------------------------------------
-- metadata: ~9 rows/entity under 25 common names (bulk), plus 30 rows carrying
--   the private_profiles opt-out name/value the access hook probes with NOT EXISTS
--   (a rare name -> ~30-row index dive, matching the production EXPLAIN estimate).
-- ---------------------------------------------------------------------------
INSERT INTO metadata (entity_guid, name, value, value_type, time_created)
SELECT (n DIV 9) + 1, CONCAT('md_', n MOD 25), CONCAT('v_', n MOD 500), 'text', n
FROM seq WHERE n < 207000;

INSERT INTO metadata (entity_guid, name, value, value_type, time_created)
SELECT (n * 50) + 1, 'plugin:user_setting:private_profiles:user_activity_setting', 'members', 'text', n
FROM seq WHERE n < 30;

-- Fresh optimizer statistics — mandatory for a reproducible plan.
ANALYZE TABLE entities;
ANALYZE TABLE river;
ANALYZE TABLE metadata;

SELECT (SELECT COUNT(*) FROM river)                                        AS river_rows,
       (SELECT COUNT(*) FROM entities)                                     AS entity_rows,
       (SELECT COUNT(*) FROM entities WHERE type='object' AND deleted='no') AS live_objects,
       (SELECT COUNT(*) FROM metadata)                                     AS metadata_rows;
