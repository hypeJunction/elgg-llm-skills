#!/usr/bin/env bash
# provision-test-db.sh — create the disposable table set that Elgg's
# IntegrationTestCase writes to, so plugin integration suites can run without
# touching the live tables.
#
# Elgg\BaseTestCase resolves its prefix as
#     getenv('ELGG_DB_PREFIX') ?: 'c_i_elgg_'
# so an integration suite writes to c_i_elgg_* — but ONLY if those tables exist.
# On a site whose DB was restored from a dump they never do, and every test errors
# with "table doesn't exist". Point run-plugin-tests.sh at the live prefix instead
# and the suite writes real entities into the running site. Neither is acceptable;
# this script provisions the third option.
#
# It mirrors the STRUCTURE of every <src-prefix>* table into <test-prefix>*, then
# seeds the few tables Elgg needs to boot (config, entities, metadata,
# entity_relationships, and private_settings where it still exists — it was folded
# into metadata in Elgg 6+). Idempotent: re-running rebuilds the test tables.
#
# The live tables are only ever READ.
#
# Usage:
#   provision-test-db.sh [--drop] [--db-container NAME]
#
#   --drop          remove the test tables instead of creating them
#   --db-container  mysql container (default: $ELGG_DB_CONTAINER)
#
# Env:
#   ELGG_DB_CONTAINER  mysql container name (required, or --db-container)
#   ELGG_DB_NAME       database          (default: elgg)
#   ELGG_DB_USER       user              (default: elgg)
#   ELGG_DB_PASS       password          (default: elgg)
#   ELGG_DB_SRC_PREFIX prefix to mirror  (default: elgg_)
#   ELGG_DB_PREFIX     test prefix       (default: c_i_elgg_)
#
# Exit: 0 ok, 2 usage, 1 failure.
set -uo pipefail

DB_CONTAINER="${ELGG_DB_CONTAINER:-}"
DB_NAME="${ELGG_DB_NAME:-elgg}"
DB_USER="${ELGG_DB_USER:-elgg}"
DB_PASS="${ELGG_DB_PASS:-elgg}"
SRC_PREFIX="${ELGG_DB_SRC_PREFIX:-elgg_}"
TEST_PREFIX="${ELGG_DB_PREFIX:-c_i_elgg_}"
DROP=0

while [ $# -gt 0 ]; do
    case "$1" in
        --drop) DROP=1; shift ;;
        --db-container) DB_CONTAINER="${2:-}"; shift 2 ;;
        -h|--help) sed -n '2,36p' "$0" | sed 's/^# \{0,1\}//'; exit 0 ;;
        *) echo "unknown arg: $1" >&2; exit 2 ;;
    esac
done

[ -n "$DB_CONTAINER" ] || { echo "ERROR: set ELGG_DB_CONTAINER or pass --db-container" >&2; exit 2; }
docker exec "$DB_CONTAINER" true 2>/dev/null || { echo "ERROR: container '$DB_CONTAINER' not running" >&2; exit 1; }

if [ "$SRC_PREFIX" = "$TEST_PREFIX" ]; then
    echo "ERROR: test prefix equals the live prefix ('$SRC_PREFIX') — that would target the live tables." >&2
    exit 2
fi

mysql_exec() { docker exec -i "$DB_CONTAINER" mysql -u"$DB_USER" -p"$DB_PASS" -N -B "$DB_NAME" 2>/dev/null; }

# Table names are identifiers, not values: they cannot be bound as parameters, so
# they are matched against SHOW TABLES output rather than interpolated blindly.
list_tables() { printf "SHOW TABLES LIKE '%s%%';\n" "$1" | mysql_exec; }

if [ "$DROP" -eq 1 ]; then
    mapfile -t victims < <(list_tables "$TEST_PREFIX")
    if [ "${#victims[@]}" -eq 0 ]; then
        echo "no '$TEST_PREFIX' tables in $DB_NAME — nothing to drop"
        exit 0
    fi
    {
        echo "SET FOREIGN_KEY_CHECKS=0;"
        for t in "${victims[@]}"; do
            case "$t" in "$TEST_PREFIX"*) echo "DROP TABLE IF EXISTS \`$t\`;" ;; esac
        done
        echo "SET FOREIGN_KEY_CHECKS=1;"
    } | mysql_exec || { echo "ERROR: drop failed" >&2; exit 1; }
    echo "dropped ${#victims[@]} '$TEST_PREFIX' tables from $DB_NAME"
    exit 0
fi

mapfile -t src_tables < <(list_tables "$SRC_PREFIX")
# SHOW TABLES LIKE 'elgg_%' also matches 'c_i_elgg_%'? No — LIKE anchors at the
# start. But a previous run's test tables share no prefix with SRC, so nothing to
# filter. Guard anyway in case someone sets SRC_PREFIX to something contained in
# TEST_PREFIX.
filtered=()
for t in "${src_tables[@]}"; do
    case "$t" in
        "$TEST_PREFIX"*) continue ;;
        "$SRC_PREFIX"*)  filtered+=("$t") ;;
    esac
done

if [ "${#filtered[@]}" -eq 0 ]; then
    echo "ERROR: no tables matching '${SRC_PREFIX}*' in $DB_NAME — is the site installed?" >&2
    exit 1
fi

echo "provisioning ${#filtered[@]} '$TEST_PREFIX' tables from '$SRC_PREFIX' in $DB_NAME ..."

# Structure first: DROP + CREATE each mirror table from SHOW CREATE TABLE.
for t in "${filtered[@]}"; do
    new="${TEST_PREFIX}${t#"$SRC_PREFIX"}"
    ddl="$(printf 'SHOW CREATE TABLE `%s`;\n' "$t" | mysql_exec | cut -f2-)"
    [ -n "$ddl" ] || { echo "ERROR: could not read schema of $t" >&2; exit 1; }
    # Rename only the leading table identifier of the CREATE statement.
    ddl_new="${ddl/CREATE TABLE \`$t\`/CREATE TABLE \`$new\`}"
    {
        echo "SET FOREIGN_KEY_CHECKS=0;"
        echo "DROP TABLE IF EXISTS \`$new\`;"
        echo "$ddl_new;"
        echo "SET FOREIGN_KEY_CHECKS=1;"
    } | mysql_exec || { echo "ERROR: failed to create $new" >&2; exit 1; }
done

# Seed the tables Elgg reads to boot. private_settings was folded into metadata in
# Elgg 6+, so copy only what exists on this stack.
seeded=0
for t in config entities metadata private_settings entity_relationships; do
    src="${SRC_PREFIX}${t}"
    dst="${TEST_PREFIX}${t}"
    printf "SHOW TABLES LIKE '%s';\n" "$src" | mysql_exec | grep -qx "$src" || continue
    printf 'INSERT INTO `%s` SELECT * FROM `%s`;\n' "$dst" "$src" | mysql_exec \
        || { echo "ERROR: failed to seed $dst" >&2; exit 1; }
    seeded=$((seeded + 1))
done

echo "test tables ready: ${#filtered[@]} created, $seeded seeded (prefix '$TEST_PREFIX')"
echo "run integration suites with:  ELGG_DB_PREFIX=$TEST_PREFIX bin/run-plugin-tests.sh <id> --suite=integration"
