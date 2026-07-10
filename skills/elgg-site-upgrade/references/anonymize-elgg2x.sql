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
--   D. elgg_metastrings is a DEDUPLICATED SHARED POOL keyed by string, referenced
--      by both name_id and value_id. Scrubbing by value alone therefore
--      (i)  renames metadata NAMES whose text also occurs as some entity's free
--           text (an object described as "admin" destroys the admin flag's name),
--      (ii) clobbers FUNCTIONAL values ('members_only', 'yes', '2') that carry
--           group access, tool options and access ids — so Elgg can no longer
--           evaluate group content access and the anonymised DB stops matching
--           production for anything permission-related (bd elgg-migrate-2knpd).
--      Fixed by the subtractive guards P1-P3 in section 5.

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

-- 5. Metastring value pool — scrub free text, PROTECT everything functional ----
--
--    `elgg_metastrings` is a single deduplicated pool: one row per distinct
--    string, referenced by BOTH `name_id` and `value_id` on metadata and
--    annotations. Rewriting a row therefore rewrites every use of that string
--    at once. Two consequences drive the guards below, and the original
--    name-only exclusion list handled neither:
--
--      A. A string used as a NAME anywhere must never be rewritten. If any
--         object's description happens to be the literal text 'admin', its
--         value_id is the SAME row that names the `admin` flag — scrubbing it
--         renames the flag itself on every user. (Guard P1.)
--
--      B. Functional values are not PII. 'members_only', 'yes', '2' carry
--         group access, tool options and access ids. Excluding them by NAME
--         cannot work: tool options are named per plugin (`blog_enable`), and
--         `content_access_mode`/`membership` were simply missing from the list,
--         which is how a group ended up with content_access_mode='metastring_82'
--         and Elgg could no longer evaluate group content access at all
--         (bd elgg-migrate-2knpd). Guard by value SHAPE instead. (Guard P2.)
--
--    Guards are subtractive: start from every referenced value, remove what must
--    survive, scrub the remainder.
DROP TABLE IF EXISTS _scrub_value_ids;
CREATE TABLE _scrub_value_ids (id INT PRIMARY KEY) ENGINE=MEMORY;
INSERT IGNORE INTO _scrub_value_ids SELECT DISTINCT value_id FROM elgg_metadata;
INSERT IGNORE INTO _scrub_value_ids SELECT DISTINCT value_id FROM elgg_annotations;

-- P1. A metastring that serves as a NAME anywhere is an identifier, never PII.
DELETE s FROM _scrub_value_ids s JOIN elgg_metadata    m ON m.name_id = s.id;
DELETE s FROM _scrub_value_ids s JOIN elgg_annotations a ON a.name_id = s.id;

-- P2. Values whose SHAPE marks them functional: enumerations, booleans,
--     integers (access ids, guids, timestamps), ISO dates, empty strings.
--     Real PII is never 'yes', '2' or 'members_only'. This is what keeps
--     content_access_mode / membership / <tool>_enable intact regardless of the
--     metadata name they hang off.
DELETE s FROM _scrub_value_ids s
  JOIN elgg_metastrings ms ON ms.id = s.id
 WHERE ms.string REGEXP '^[0-9]+$'
    OR ms.string REGEXP '^[0-9]{4}-[0-9]{2}-[0-9]{2}'
    OR LOWER(ms.string) IN (
         'yes','no','on','off','true','false','1','0','',
         'unrestricted','members_only','public','private','open','closed',
         'email','site','both','none','default','enabled','disabled'
       );

-- P3. Belt and braces: values hanging off a known-functional metadata NAME.
--     Redundant with P2 for today's data, but a new enumeration value that is
--     not in the P2 list is still protected if its name is recognised here.
DELETE s FROM _scrub_value_ids s
  JOIN elgg_metadata m     ON m.value_id = s.id
  JOIN elgg_metastrings mn ON mn.id     = m.name_id
 WHERE mn.string IN (
   'validated','validated_method','admin','banned','language',
   'access_id','member_of_site','notification','email_notification',
   'last_action','prev_last_action','last_login','prev_last_login',
   'content_access_mode','membership'
 )
    OR mn.string LIKE '%\_enable'      -- per-plugin group tool options
    OR mn.string LIKE '%access%'       -- *_access, access_mode, …
    OR mn.string LIKE 'elgg:%'
    OR mn.string LIKE 'plugin:%';

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

-- Footgun D(i): a metastring used as a NAME must never be scrubbed. If one is,
-- every metadata row carrying that name has lost it (bd elgg-migrate-2knpd).
SELECT 'metadata_names_clobbered' AS metric, COUNT(*) AS n
  FROM (
    SELECT m.name_id AS id FROM elgg_metadata    m
    UNION SELECT a.name_id FROM elgg_annotations a
  ) names
  JOIN elgg_metastrings ms ON ms.id = names.id
 WHERE ms.string LIKE 'metastring\\_%';

-- Footgun D(ii): FUNCTIONAL metadata must keep real values, or Elgg cannot
-- evaluate group content access and the anonymised DB is an unsound oracle for
-- anything permission-related.
--
-- NOTE: this assertion can only name the keys we know about. Once a value has
-- been rewritten to 'metastring_<id>' its original shape is gone, so a setting
-- under an unrecognised name (some plugin's `moderation_mode`) cannot be
-- detected after the fact. Guard P2 — not this query — is what protects those.
-- Verified: with P2 removed and P3 kept, moderation_mode='closed' is destroyed
-- and this assertion still reports 0.
SELECT 'functional_metadata_clobbered' AS metric, COUNT(*) AS n
  FROM elgg_metadata m
  JOIN elgg_metastrings mn ON mn.id = m.name_id
  JOIN elgg_metastrings mv ON mv.id = m.value_id
 WHERE mv.string LIKE 'metastring\\_%'
   AND ( mn.string IN ('content_access_mode','membership','access_id','admin','banned','language')
         OR mn.string LIKE '%\\_enable' );

-- Login-critical: every user carries the documented 'dev' bcrypt hash:
SELECT 'users_with_dev_hash' AS metric, COUNT(*) AS n
  FROM elgg_users_entity
 WHERE password_hash = '$2y$10$TunwvKLLEw5s1XbW59mXoOzBbTJ67lU3x2L2dXtAQL9ldhQN/Xo2G';
