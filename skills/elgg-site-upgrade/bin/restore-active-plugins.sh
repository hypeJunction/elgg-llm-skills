#!/usr/bin/env bash
# restore-active-plugins.sh — re-activate the production plugin set in a
# migration-stage Elgg DB, matching plugin ids CASE-INSENSITIVELY.
#
# Why this exists:
#   The chain harness (verify-migration-chain.sh) overlays an anonymized prod
#   dump, then walks the site up one major version at a time. Elgg <4.x tolerates
#   camelCase plugin directories (hypePrototyper), so the 2.x/3.x stage entities
#   keep their camelCase titles. But the captured prod active-plugin list
#   (prod-active-plugins-*.tsv) is lowercase. A naive case-SENSITIVE activation
#   match silently skips every camelCase hype* plugin, leaving active lowercase
#   dependents (e.g. prototyper_group) calling into a DISABLED dependency
#   (hypePrototyper) -> fatal "Call to undefined function hypePrototyper()".
#
#   This script matches on LOWER(title), so it restores the prod active set
#   regardless of the on-disk directory case at the current migration stage.
#
#   The active set is the INTERSECTION of (prod-active TSV) and (plugins actually
#   present in the app container's mod/ dir). Prod plugins that were intentionally
#   dropped during migration have a stale enabled='yes' entity but NO directory
#   this stage; activating those would break boot, so they are excluded.
#
# Idempotent: only inserts the active_plugin relationship where it is missing.
#
# Usage:
#   bin/restore-active-plugins.sh --tsv snapshots/prod-active-plugins.tsv \
#                                 --db-container <site>3x-db-1 \
#                                 --app-container <site>3x-app-1 [--db elgg] \
#                                 [--mod-path /var/www/html/mod] \
#                                 [--mysql-user root] [--mysql-pass root] [--dry-run]
#
# TSV format: one plugin per line, "<plugin_id>\t<priority>". Lines beginning
# with "//" or "#", and blank lines, are ignored.

set -euo pipefail

TSV=""
DB_CONTAINER=""
APP_CONTAINER=""
MOD_PATH="/var/www/html/mod"
DB="elgg"
MYSQL_USER="root"
MYSQL_PASS="root"
DRY_RUN=0

while [[ $# -gt 0 ]]; do
  case "$1" in
    --tsv)           TSV="$2"; shift 2 ;;
    --db-container)  DB_CONTAINER="$2"; shift 2 ;;
    --app-container) APP_CONTAINER="$2"; shift 2 ;;
    --mod-path)      MOD_PATH="$2"; shift 2 ;;
    --db)            DB="$2"; shift 2 ;;
    --mysql-user)    MYSQL_USER="$2"; shift 2 ;;
    --mysql-pass)    MYSQL_PASS="$2"; shift 2 ;;
    --dry-run)       DRY_RUN=1; shift ;;
    -h|--help)       sed -n '2,36p' "$0" | sed 's/^# \{0,1\}//'; exit 0 ;;
    *) echo "Unknown arg: $1" >&2; exit 2 ;;
  esac
done

[[ -n "$TSV" && -f "$TSV" ]] || { echo "ERROR: --tsv file not found: $TSV" >&2; exit 2; }
[[ -n "$DB_CONTAINER" ]]     || { echo "ERROR: --db-container required" >&2; exit 2; }
[[ -n "$APP_CONTAINER" ]]    || { echo "ERROR: --app-container required (to read on-disk mod/ set)" >&2; exit 2; }

mysql_exec() { docker exec -i "$DB_CONTAINER" mysql -u"$MYSQL_USER" -p"$MYSQL_PASS" -N "$DB" 2>/dev/null; }

# Stream the parsed TSV (lowercased ids) as INSERT rows into a temp table, then
# do all set logic in SQL. Keeping it in one mysql session means the TEMPORARY
# table survives across statements.
build_want_inserts() {
  grep -vE '^[[:space:]]*(//|#|$)' "$TSV" \
    | awk -F'\t' '{print $1}' \
    | tr 'A-Z' 'a-z' \
    | sed "s/'/''/g" \
    | awk 'NF{printf "INSERT IGNORE INTO _want(id) VALUES ('"'"'%s'"'"');\n", $1}'
}

build_ondisk_inserts() {
  docker exec "$APP_CONTAINER" sh -c "ls -1 '$MOD_PATH'" 2>/dev/null \
    | tr 'A-Z' 'a-z' \
    | sed "s/'/''/g" \
    | awk 'NF{printf "INSERT IGNORE INTO _ondisk(id) VALUES ('"'"'%s'"'"');\n", $1}'
}

site_guid=$(echo "SELECT guid FROM elgg_entities WHERE type='site' ORDER BY guid LIMIT 1;" | mysql_exec)
[[ -n "$site_guid" ]] || { echo "ERROR: no site entity found in $DB" >&2; exit 2; }

# Register on-disk plugins that the migrated DB never created 7.x ElggPlugin
# entities for. Without this, plugins present in mod/ + .plugin-order.txt have no
# enabled plugin entity, so the activation JOIN below (e.enabled='yes') can never
# match them and they silently stay inactive — a feature-parity hole. Idempotent.
if [[ "$DRY_RUN" != "1" ]]; then
  docker exec "$APP_CONTAINER" sh -c 'cd /var/www/html && php -r '\''
    require "vendor/autoload.php"; \Elgg\Application::start();
    $n = _elgg_services()->plugins->generateEntities();
    elgg_invalidate_caches();
    fwrite(STDERR, "generateEntities: registered on-disk plugins\n");
  '\'' 2>&1' | grep -i "generateEntities" || echo "  (generateEntities step ran)"
fi

# Target = prod-active AND on-disk this stage. Matched case-insensitively.
TARGET_JOIN="
JOIN elgg_metadata md ON md.entity_guid=e.guid AND md.name='title'
JOIN _want   w  ON w.id  = LOWER(md.value)
JOIN _ondisk od ON od.id = LOWER(md.value)
WHERE e.type='object' AND e.subtype='plugin' AND e.enabled='yes'
  AND NOT EXISTS (
    SELECT 1 FROM elgg_entity_relationships r
    WHERE r.guid_one=e.guid AND r.relationship='active_plugin'
  )"

if [[ "$DRY_RUN" == "1" ]]; then
  ACTION_SQL="SELECT md.value AS would_activate, e.guid FROM elgg_entities e $TARGET_JOIN ORDER BY md.value;"
else
  ACTION_SQL="
INSERT INTO elgg_entity_relationships (guid_one, relationship, guid_two, time_created)
SELECT e.guid, 'active_plugin', $site_guid, UNIX_TIMESTAMP()
FROM elgg_entities e $TARGET_JOIN;"
fi

{
  echo "CREATE TEMPORARY TABLE _want   (id VARCHAR(255) PRIMARY KEY);"
  echo "CREATE TEMPORARY TABLE _ondisk (id VARCHAR(255) PRIMARY KEY);"
  build_want_inserts
  build_ondisk_inserts
  # Migrated-prod plugin entities for plugins not previously active on 7.x are
  # often enabled='no' (disabled/soft-deleted). A disabled entity can never be
  # activated (the activation JOIN filters e.enabled='yes'), so enable any
  # on-disk + prod-active plugin entity first. Without this, freshly-migrated
  # plugins (e.g. group_sort, widget_manager) silently stay inactive.
  if [[ "$DRY_RUN" != "1" ]]; then
    echo "UPDATE elgg_entities e
            JOIN elgg_metadata m ON m.entity_guid=e.guid AND m.name='title'
            JOIN _want   w  ON w.id  = LOWER(m.value)
            JOIN _ondisk od ON od.id = LOWER(m.value)
          SET e.enabled='yes'
          WHERE e.subtype='plugin' AND e.enabled='no';"
  fi
  echo "SELECT '== prod-active but DROPPED this stage (no on-disk dir; left inactive) ==' AS '';"
  echo "SELECT w.id FROM _want w LEFT JOIN _ondisk od ON od.id=w.id WHERE od.id IS NULL ORDER BY w.id;"
  echo "SELECT '== ${DRY_RUN:+would-}activate (prod-active AND on-disk, currently inactive) ==' AS '';"
  echo "$ACTION_SQL"
  echo "SELECT CONCAT('== plugins now active: ', COUNT(DISTINCT guid_one), ' ==') AS ''
        FROM elgg_entity_relationships WHERE relationship='active_plugin';"
} | mysql_exec

if [[ "$DRY_RUN" != "1" ]]; then
  echo "NOTE: flush Elgg caches + restart the app container for boot to pick this up."
fi
