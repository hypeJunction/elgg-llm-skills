-- The change under test (authoritative, version-controlled form). It is the DDL
-- for a proposed Elgg CORE migration
-- (engine/schema/migrations/..._add_entity_guid_name_index_to_metadata.php),
-- which lives upstream rather than in this toolkit — composite on entity_guid +
-- name, name prefixed to 255 chars to match the sibling `entity_guid_name` index
-- already on the annotations table.
USE bench;

ALTER TABLE metadata ADD INDEX entity_guid_name (entity_guid, name(255));
ANALYZE TABLE metadata;
