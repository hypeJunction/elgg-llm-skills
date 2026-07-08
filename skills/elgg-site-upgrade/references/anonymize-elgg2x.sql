-- anonymize-elgg2x.sql — generic Elgg 2.x production DB anonymizer for building a
-- safe seed to rehearse a migration chain on production-SHAPE data.
--
-- Run AFTER loading a production 2.x dump into a throwaway `elgg` database
-- (bin/build-anon-seed.sh does this in a disposable mysql:5.7 container).
--
-- Goals: replace direct PII, scrub free-text + secret-like settings, drop
-- session/auth tables — while PRESERVING schema, row counts, entity
-- relationships and subtype distribution so phinx/upgrade migrations get
-- exercised against real-shaped data.
--
-- THREE LOGIN/BOOT FOOTGUNS ARE ENCODED BELOW (each broke a real migration):
--   A. A blanket metastring scrub clobbers login-critical flags (validated,
--      admin, banned, language) → uservalidationbyemail blocks EVERY user.
--      Fixed by the exclusion list in section 5.
--   B. Leaving password_hash as-is (or scrubbing it to garbage) makes login
--      silently fail for every user. Fixed by resetting to a KNOWN dev hash
--      in section 1 (password_verify('dev', <hash>) === true).
--   C. Emptying __site_secret__ is harmless on 2.x (regenerated lazily) but
--      Elgg 3.x+ BootService HARD-THROWS "The site secret is not set", killing
--      every chain tier after 2.x. Fixed by re-seeding one in the final section.

SET autocommit = 0;
SET unique_checks = 0;
SET foreign_key_checks = 0;

-- 1. Direct user PII + login-critical password reset (footgun B) --------------
UPDATE elgg_users_entity SET
  name          = CONCAT('User ', guid),
  username      = CONCAT('user_', guid),
  email         = CONCAT('user_', guid, '@example.invalid'),
  password      = '',
  salt          = '',
  -- bcrypt of 'dev' (cost 10). ALL test users share this password.
  -- VERIFIED: password_verify('dev', <hash>) === true.
  password_hash = '$2y$10$TunwvKLLEw5s1XbW59mXoOzBbTJ67lU3x2L2dXtAQL9ldhQN/Xo2G';

-- 2. Auth/session tables — truncate (zero migration value, high leak blast) ---
TRUNCATE TABLE elgg_users_apisessions;
TRUNCATE TABLE elgg_users_remember_me_cookies;
TRUNCATE TABLE elgg_users_sessions;
TRUNCATE TABLE elgg_api_users;
TRUNCATE TABLE elgg_hmac_cache;

-- 3. Object titles/descriptions — PRESERVE plugin entities (their title is the
--    plugin folder id, e.g. '<your_plugin_id>', needed to identify the
--    prod-active plugin set for chain validation — NOT PII).
UPDATE elgg_objects_entity oe
  JOIN elgg_entities e ON e.guid = oe.guid
  JOIN elgg_entity_subtypes es ON es.id = e.subtype
   SET oe.title       = CONCAT('Object ', oe.guid),
       oe.description = CONCAT('Object content for guid ', oe.guid)
 WHERE NOT (es.type = 'object' AND es.subtype = 'plugin');

-- 4. Group names/descriptions ------------------------------------------------
UPDATE elgg_groups_entity SET
  name        = CONCAT('Group ', guid),
  description = CONCAT('Group description for guid ', guid);

-- 5. Metastring value pool — scrub values, but EXCLUDE login/state flags -------
--    (footgun A). Name-strings are identifiers, left alone.
DROP TABLE IF EXISTS _scrub_value_ids;
CREATE TABLE _scrub_value_ids (id INT PRIMARY KEY) ENGINE=MEMORY;
INSERT IGNORE INTO _scrub_value_ids SELECT DISTINCT value_id FROM elgg_metadata;
INSERT IGNORE INTO _scrub_value_ids SELECT DISTINCT value_id FROM elgg_annotations;

-- CRITICAL: remove value_ids that back Elgg system/auth/state flags before
-- scrubbing. These short shared values ('1','yes','email', access ids…) are not
-- PII; scrubbing them to 'metastring_<id>' clobbers login/state.
DELETE s FROM _scrub_value_ids s
  JOIN elgg_metadata m     ON m.value_id = s.id
  JOIN elgg_metastrings mn ON mn.id     = m.name_id
 WHERE mn.string IN (
   'validated','validated_method','admin','banned','language',
   'access_id','member_of_site','notification','email_notification',
   'last_action','prev_last_action','last_login','prev_last_login'
 );

UPDATE elgg_metastrings ms
  JOIN _scrub_value_ids s ON s.id = ms.id
   SET ms.string = CONCAT('metastring_', ms.id)
 WHERE LENGTH(ms.string) > 0;

DROP TABLE _scrub_value_ids;

-- 6. Private settings — scrub secret-like keys -------------------------------
UPDATE elgg_private_settings
   SET value = CONCAT('REDACTED_', id)
 WHERE name REGEXP '(secret|token|key|password|api|smtp|webhook|private|client_id|client_secret|access|credential|signing|salt|hmac)'
   AND LENGTH(value) > 0;

-- 7. Privacy/in-flight tables — truncate -------------------------------------
TRUNCATE TABLE elgg_geocode_cache;
TRUNCATE TABLE elgg_queue;
TRUNCATE TABLE elgg_digest;
TRUNCATE TABLE elgg_system_log;

COMMIT;
SET autocommit = 1;
SET unique_checks = 1;
SET foreign_key_checks = 1;

-- 8. Site secret re-seed (footgun C) — 3.x+ BootService throws if unset -------
INSERT INTO elgg_datalists (name, value)
VALUES ('__site_secret__', CONCAT('z', SUBSTRING(REPLACE(REPLACE(TO_BASE64(RANDOM_BYTES(32)), '+', '-'), '/', '_'), 1, 31)))
ON DUPLICATE KEY UPDATE value = CONCAT('z', SUBSTRING(REPLACE(REPLACE(TO_BASE64(RANDOM_BYTES(32)), '+', '-'), '/', '_'), 1, 31));

-- ---------------------------------------------------------------------------
-- Assertions (build-anon-seed.sh checks these; each MUST return n=0 except the
-- last, which must equal the user count).
-- ---------------------------------------------------------------------------
-- No plaintext user emails remain:
SELECT 'plaintext_emails_remaining' AS metric, COUNT(*) AS n
  FROM elgg_users_entity
 WHERE email NOT REGEXP '^user_[0-9]+@example\\.invalid$';

-- Login-critical: 'validated' flags survived the scrub (0 = good):
SELECT 'validated_flags_clobbered' AS metric, COUNT(*) AS n
  FROM elgg_metadata m
  JOIN elgg_metastrings mn ON mn.id = m.name_id
  JOIN elgg_metastrings mv ON mv.id = m.value_id
 WHERE mn.string = 'validated' AND mv.string LIKE 'metastring\\_%';

-- Login-critical: every user carries the documented 'dev' bcrypt hash:
SELECT 'users_with_dev_hash' AS metric, COUNT(*) AS n
  FROM elgg_users_entity
 WHERE password_hash = '$2y$10$TunwvKLLEw5s1XbW59mXoOzBbTJ67lU3x2L2dXtAQL9ldhQN/Xo2G';
