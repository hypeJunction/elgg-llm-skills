-- Convert the Elgg-owned plugin/legacy tables to InnoDB + utf8mb4.
--
-- The table list below is the one bodyology needed. Audit yours first:
--   SELECT table_name, table_collation, engine FROM information_schema.tables
--   WHERE table_schema = DATABASE() AND table_collation NOT LIKE 'utf8mb4%';
--
-- Elgg's own \Elgg\Upgrades\AlterDatabaseToMultiByteCharset cannot do this: it
-- iterates a hardcoded table list that still contains `elgg_private_settings`,
-- a table Elgg 4 removed. The upgrade dies on
--     SQLSTATE[42S02]: Base table or view not found: 1146 Table
--     'elgg.elgg_private_settings' doesn't exist
-- before converting anything, and because Elgg raises an unhandled promise
-- rejection on a failed upgrade, EVERY upgrade queued behind it stays pending —
-- including core's MigratePageTop, which is what leaves 85 `page_top` entities
-- classless and unreachable.
--
-- So do the work here, then mark that upgrade completed (it has nothing left to do).
--
-- Deliberately NOT converted:
--   elgg_system_log_<epoch>  ARCHIVE backups of the 2017-2018 system log. Read-only
--                            history; ARCHIVE cannot be ALTERed to InnoDB.
--   elgg_hmac_cache          MEMORY, transient, recreated on restart.
--   elgg_users_apisessions   MEMORY, transient.
--   wponline_bigbluebutton*  not Elgg tables.
--
-- MyISAM has a 1000-byte index limit; elgg_sef_aliases has a PRIMARY KEY on a
-- varchar(255), which is 1020 bytes as utf8mb4. Converting to InnoDB (DYNAMIC row
-- format, 3072-byte limit) is required, and is what Elgg 7 expects regardless.

ALTER TABLE elgg_digest             ENGINE=InnoDB ROW_FORMAT=DYNAMIC, CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci;
ALTER TABLE elgg_entity_geometry    ENGINE=InnoDB ROW_FORMAT=DYNAMIC, CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci;
ALTER TABLE elgg_folders            ENGINE=InnoDB ROW_FORMAT=DYNAMIC, CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci;
ALTER TABLE elgg_scraper_data       ENGINE=InnoDB ROW_FORMAT=DYNAMIC, CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci;
ALTER TABLE elgg_sef_aliases        ENGINE=InnoDB ROW_FORMAT=DYNAMIC, CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci;
ALTER TABLE elgg_sef_data           ENGINE=InnoDB ROW_FORMAT=DYNAMIC, CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci;
ALTER TABLE elgg_sef_routes         ENGINE=InnoDB ROW_FORMAT=DYNAMIC, CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci;
ALTER TABLE elgg_site_notifications ENGINE=InnoDB ROW_FORMAT=DYNAMIC, CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci;

ALTER TABLE elgg_migrations                CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci;
ALTER TABLE elgg_queue                     CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci;
ALTER TABLE elgg_river                     CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci;
ALTER TABLE elgg_system_log                CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci;
ALTER TABLE elgg_users_remember_me_cookies CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci;
ALTER TABLE elgg_users_sessions            CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci;
