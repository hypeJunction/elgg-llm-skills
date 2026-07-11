-- Wall-clock timing procedures for the two query forms.
--
-- Each proc replays the SAME access path as the headline SELECT (identical joins,
-- filters, ORDER BY rv.posted DESC LIMIT 20) N times, wrapped in COUNT(*) so the
-- result set is a single scalar and we time the engine's work, not row transport.
-- Wall-clock is a footnote (Iron Law #1); the verdict is EXPLAIN + Handler_read_*.
USE bench;

DROP PROCEDURE IF EXISTS run_before;
DROP PROCEDURE IF EXISTS run_after;

DELIMITER //

-- BEFORE: entities joined in (INNER se, INNER oe, LEFT te) and filtered -> the
-- optimizer is free to reorder and lead with an entities scan.
CREATE PROCEDURE run_before(IN iters INT)
BEGIN
  DECLARE i INT DEFAULT 0;
  DECLARE sink INT;
  WHILE i < iters DO
    SELECT COUNT(*) INTO sink FROM (
      SELECT DISTINCT rv.id, rv.posted
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
      LIMIT 20
    ) t;
    SET i = i + 1;
  END WHILE;
END//

-- AFTER: entity accessibility expressed as correlated EXISTS() semi-joins -> river
-- is the sole driving table and ORDER BY rv.posted uses the posted index.
CREATE PROCEDURE run_after(IN iters INT)
BEGIN
  DECLARE i INT DEFAULT 0;
  DECLARE sink INT;
  WHILE i < iters DO
    SELECT COUNT(*) INTO sink FROM (
      SELECT DISTINCT rv.id, rv.posted
      FROM river rv
      WHERE EXISTS (SELECT 1 FROM entities se WHERE (se.guid = rv.subject_guid)
                      AND ((se.enabled = 'yes') AND (se.deleted = 'no')
                           AND (NOT EXISTS (SELECT 1 FROM metadata pp_md WHERE ((pp_md.entity_guid = se.guid) OR (pp_md.entity_guid = se.owner_guid)) AND (pp_md.name = 'plugin:user_setting:private_profiles:user_activity_setting') AND (pp_md.value = 'members')))
                           AND (se.access_id IN (2, -5))))
        AND EXISTS (SELECT 1 FROM entities oe WHERE (oe.guid = rv.object_guid)
                      AND (((oe.enabled = 'yes') AND (oe.deleted = 'no')
                            AND (NOT EXISTS (SELECT 1 FROM metadata pp_md WHERE ((pp_md.entity_guid = oe.guid) OR (pp_md.entity_guid = oe.owner_guid)) AND (pp_md.name = 'plugin:user_setting:private_profiles:user_activity_setting') AND (pp_md.value = 'members')))
                            AND (oe.access_id IN (2, -5))
                            AND (((oe.type = 'user') AND (oe.subtype IN ('user'))) OR ((oe.type = 'group') AND (oe.subtype IN ('group'))) OR ((oe.type = 'object') AND (oe.subtype IN ('file','comment','blog','page','bookmarks','thewire','library_entry','library_file','discussion','feedback','hjplace','hjwall','wall_tag')))))))
        AND ((EXISTS (SELECT 1 FROM entities te WHERE (te.guid = rv.target_guid)
                        AND ((te.enabled = 'yes') AND (te.deleted = 'no')
                             AND (NOT EXISTS (SELECT 1 FROM metadata pp_md WHERE ((pp_md.entity_guid = te.guid) OR (pp_md.entity_guid = te.owner_guid)) AND (pp_md.name = 'plugin:user_setting:private_profiles:user_activity_setting') AND (pp_md.value = 'members')))
                             AND (te.access_id IN (2, -5)))))
             OR (NOT EXISTS (SELECT 1 FROM entities te WHERE te.guid = rv.target_guid)))
      ORDER BY rv.posted DESC
      LIMIT 20
    ) t;
    SET i = i + 1;
  END WHILE;
END//

DELIMITER ;
