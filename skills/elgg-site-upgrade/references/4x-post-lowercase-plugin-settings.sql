-- Carry plugin settings across the 3.x -> 4.x plugin-id lowercasing.
--
-- When Elgg 4.x lowercased plugin IDs it created FRESH lowercase plugin entities
-- and left the old camelCase ones in place with their production settings intact
-- but unreachable: ElggPlugin::getAllSettings() reads the *active* (lowercase)
-- entity's metadata, so the migrated plugin silently runs on the static defaults
-- from elgg-plugin.php while the real config sits stranded on the camelCase twin.
--
-- Two traps this file exists to avoid (bd elgg-migrate-vkeia):
--   1. The camelCase entities are enabled='yes', NOT disabled. Any remediation
--      that filters on enabled='no' matches nothing. (The first draft of this
--      script did exactly that and appeared to succeed.)
--   2. The DB collation is case-insensitive, so `title = 'hypeSeo'` also matches
--      'hypeseo'. Every title comparison below is BINARY.
--
-- Run against the chain-migrated DB after the 4.x `elgg-cli upgrade` and while
-- BOTH entities still exist. Idempotent: the NOT EXISTS guard means a second run
-- inserts nothing, and the UPDATE is a no-op once the orphans are disabled.

-- 1. Copy every functional metadata key from the camelCase entity onto its
--    lowercase twin, but only where the twin does not already define it.
--    Excluded:
--      title/description  - identity, not config
--      elgg:internal:*    - bookkeeping; copying priority reorders the plugin list
INSERT INTO elgg_metadata (entity_guid, name, value, value_type, time_created)
SELECT ne.guid, om.name, om.value, om.value_type, om.time_created
FROM elgg_entities oe
JOIN elgg_metadata ot ON ot.entity_guid = oe.guid AND ot.name = 'title'
JOIN elgg_metadata nt ON nt.name = 'title' AND BINARY nt.value = LOWER(ot.value)
JOIN elgg_entities ne ON ne.guid = nt.entity_guid AND ne.subtype = 'plugin'
JOIN elgg_metadata om ON om.entity_guid = oe.guid
WHERE oe.subtype = 'plugin'
  AND ne.guid <> oe.guid
  AND BINARY ot.value REGEXP '[A-Z]'
  AND om.name NOT IN ('title', 'description')
  AND om.name NOT LIKE 'elgg:internal:%'
  AND NOT EXISTS (
    SELECT 1 FROM elgg_metadata nm
    WHERE nm.entity_guid = ne.guid AND nm.name = om.name
  );

-- 2. Retire the camelCase orphans that now have a lowercase twin. Disabled, not
--    deleted: the settings they carried are recoverable if step 1 mis-copied.
--    A camelCase plugin entity with NO lowercase twin (e.g. hypeApprove, a plugin
--    genuinely dropped from the fleet) is left exactly as it is.
--    The twin lookup is a derived table, not a correlated subquery: MySQL rejects
--    "You can't specify target table 'oe' for update in FROM clause" otherwise.
UPDATE elgg_entities oe
JOIN elgg_metadata ot ON ot.entity_guid = oe.guid AND ot.name = 'title'
JOIN (
  SELECT ne.guid AS guid, BINARY nt.value AS title
  FROM elgg_entities ne
  JOIN elgg_metadata nt ON nt.entity_guid = ne.guid AND nt.name = 'title'
  WHERE ne.subtype = 'plugin'
--    NB: LOWER(BINARY x) is a no-op — a binary string has no case mapping. Lower
--    first, then let the binary column on the left force the binary comparison.
) twin ON twin.title = LOWER(ot.value) AND twin.guid <> oe.guid
SET oe.enabled = 'no'
WHERE oe.subtype = 'plugin'
  AND oe.enabled = 'yes'
  AND BINARY ot.value REGEXP '[A-Z]';

-- 3. Drop the orphans' `active_plugin` relationship. Disabling alone is enough to
--    keep them out of Plugins::find('active'), but leaving the relationship makes
--    /admin/plugins and generateEntities() reason about a plugin whose directory
--    no longer exists (mod/ is all-lowercase after the 4.x rename) — that is the
--    "Cannot include elgg-plugin.php for plugin hypeInvite (guid: 12278)" boot error.
DELETE r FROM elgg_entity_relationships r
JOIN elgg_entities oe ON oe.guid = r.guid_one AND oe.subtype = 'plugin' AND oe.enabled = 'no'
JOIN elgg_metadata ot ON ot.entity_guid = oe.guid AND ot.name = 'title'
WHERE r.relationship = 'active_plugin'
  AND BINARY ot.value REGEXP '[A-Z]';
