-- Finish the job \Elgg\Pages\Upgrades\MigratePageTop was supposed to do.
--
-- Elgg 3 merged the `page_top` subtype into `page`. The upgrade that performs the
-- rename ships as an ElggUpgrade entity created during the 2.x->3.x hop — but the
-- CLASS was deleted from Elgg upstream by 7.0. UpgradeService::getPendingUpgrades()
-- silently drops an upgrade whose batch cannot be instantiated, so the entity sits
-- "pending" forever and the rename never happens.
--
-- The damage: 85 `page_top` entities load as ElggUndefinedObject, getURL() returns
-- an empty string, and /pages/view/{guid} 404s. Eighty-five pages of production
-- content are unreachable on the migrated site while 2.x serves them.
--
-- A `page` differs from a `page_top` only by carrying a parent_guid (0 = top level).
--
-- Idempotent: re-running rewrites nothing once no page_top rows remain.

UPDATE elgg_entities SET subtype = 'page' WHERE subtype = 'page_top';

-- Top-level pages have no parent. Only insert where the row does not already have one.
INSERT INTO elgg_metadata (entity_guid, name, value, value_type, time_created)
SELECT e.guid, 'parent_guid', '0', 'integer', UNIX_TIMESTAMP()
FROM elgg_entities e
WHERE e.subtype = 'page'
  AND NOT EXISTS (
    SELECT 1 FROM elgg_metadata m
    WHERE m.entity_guid = e.guid AND m.name = 'parent_guid'
  );
