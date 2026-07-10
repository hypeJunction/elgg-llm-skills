#!/usr/bin/env bash
# 7x-utf8mb4-convert.sh — convert every Elgg table to InnoDB + utf8mb4.
#
# Elgg's own \Elgg\Upgrades\AlterDatabaseToMultiByteCharset cannot do this any
# more: it iterates a hardcoded table list that still contains
# `elgg_private_settings`, a table Elgg 4 removed. It dies on
#
#   SQLSTATE[42S02]: Base table or view not found: 1146
#   Table 'elgg.elgg_private_settings' doesn't exist
#
# before converting anything. And because Elgg rejects its upgrade promise on a
# failed batch, EVERY upgrade queued behind it is abandoned too.
#
# So do the work here, then mark that upgrade completed — it has nothing left to
# do. Discovering the table list at runtime, rather than hardcoding it, is the
# whole point: which tables are stale depends on the site's plugin history.
#
# EXCLUDED, deliberately:
#   elgg_system_log_<epoch>  ARCHIVE backups. Read-only history; ARCHIVE cannot be
#                            ALTERed to InnoDB.
#   elgg_hmac_cache          MEMORY, transient, recreated on restart.
#   elgg_users_apisessions   MEMORY, transient.
#   anything not named elgg_*  not ours (bodyology carried wponline_bigbluebutton*).
#
# MyISAM has a 1000-byte index limit, and elgg_sef_aliases has a PRIMARY KEY on a
# varchar(255) — 1020 bytes as utf8mb4. Converting to InnoDB (DYNAMIC row format,
# 3072-byte limit with innodb_large_prefix) is therefore required, and is what
# Elgg 7 expects regardless.
#
# Usage:
#   7x-utf8mb4-convert.sh <db-container> [--dry-run]
set -euo pipefail

DB_C="${1:?usage: 7x-utf8mb4-convert.sh <db-container> [--dry-run]}"
DRY=0
[ "${2:-}" = "--dry-run" ] && DRY=1

q() { docker exec "$DB_C" mysql --skip-ssl -uroot -proot -N -e "$1" 2>/dev/null; }

mapfile -t TABLES < <(q "
  SELECT table_name FROM information_schema.tables
  WHERE table_schema = 'elgg'
    AND table_name LIKE 'elgg\\_%'
    AND table_name NOT LIKE 'elgg\\_system\\_log\\_1%'
    AND table_name NOT IN ('elgg_hmac_cache', 'elgg_users_apisessions')
    AND table_collation NOT LIKE 'utf8mb4%'
  ORDER BY table_name;")

if [ "${#TABLES[@]}" -eq 0 ]; then
  echo "already utf8mb4: nothing to convert"
  exit 0
fi

echo "converting ${#TABLES[@]} table(s):"
for t in "${TABLES[@]}"; do
  [ -n "$t" ] || continue
  engine="$(q "SELECT engine FROM information_schema.tables WHERE table_schema='elgg' AND table_name='$t';")"
  sql="ALTER TABLE \`$t\`"
  [ "$engine" = "MyISAM" ] && sql="$sql ENGINE=InnoDB ROW_FORMAT=DYNAMIC,"
  sql="$sql CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci;"

  if [ "$DRY" = 1 ]; then
    echo "  WOULD RUN: $sql"
    continue
  fi
  printf '  %-36s (%s) ... ' "$t" "$engine"
  if docker exec "$DB_C" mysql --skip-ssl -uroot -proot elgg -e "$sql" 2>/dev/null; then
    echo "ok"
  else
    echo "FAILED"
    exit 1
  fi
done

remaining="$(q "
  SELECT COUNT(*) FROM information_schema.tables
  WHERE table_schema = 'elgg'
    AND table_name LIKE 'elgg\\_%'
    AND table_name NOT LIKE 'elgg\\_system\\_log\\_1%'
    AND table_name NOT IN ('elgg_hmac_cache', 'elgg_users_apisessions')
    AND table_collation NOT LIKE 'utf8mb4%';")"
echo "remaining non-utf8mb4 Elgg tables: ${remaining:-?}"
[ "${remaining:-1}" -eq 0 ]
