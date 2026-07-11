-- The fix under test. This mirrors, byte-for-byte, the index created by
-- engine/schema/migrations/20260711120000_add_entity_guid_name_index_to_metadata.php
-- (composite on entity_guid + name, name prefixed to 255 chars to match its
-- sibling `entity_guid_name` index on the annotations table).
USE bench;

ALTER TABLE metadata ADD INDEX entity_guid_name (entity_guid, name(255));
ANALYZE TABLE metadata;
