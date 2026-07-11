#!/usr/bin/env bash
# Real-data variant of the SQL micro-benchmark.
#
# Instead of a formula-generated seed, this dumps ONE table from a live Elgg
# database and replays the getIDsByName point-lookup shape
# (WHERE entity_guid = ? AND name = ?) against the site's ACTUAL rows, in a
# clean throwaway container. The live DB is never modified — only a dump is read.
#
# Use it to prove a composite (entity_guid, name) index against a real site's
# real per-entity fan-out (which the synthetic seed can only approximate). Same
# verdict rule as the rest of the skill: the deterministic Handler_read_* delta
# and the EXPLAIN plan change are the verdict; wall-clock is a footnote.
#
# Usage:
#   ./bench-real-db.sh <source-container> [db] [prefix]
#
# Env overrides (defaults target the metadata entity_guid_name index):
#   SRC_ROOT_PW   root password of the source container   (default: root)
#   TABLE         table to benchmark                       (default: metadata)
#   INDEX_NAME    composite index to add                   (default: entity_guid_name)
#   INDEX_COLS    its columns                              (default: "entity_guid, name(255)")
#   ENGINE_IMAGE  throwaway engine image                   (default: mysql:8.0)
#   N             lookups per measured run                 (default: 10000)
#
# Example — metadata index on a live 7.x DB:
#   ./bench-real-db.sh bodyology7x-db-1 elgg elgg_
set -euo pipefail

SRC="${1:?usage: bench-real-db.sh <source-container> [db] [prefix]}"
SRC_DB="${2:-elgg}"
PFX="${3:-elgg_}"
SRC_ROOT_PW="${SRC_ROOT_PW:-root}"
TABLE="${TABLE:-metadata}"
INDEX_NAME="${INDEX_NAME:-entity_guid_name}"
INDEX_COLS="${INDEX_COLS:-entity_guid, name(255)}"
ENGINE_IMAGE="${ENGINE_IMAGE:-mysql:8.0}"
N="${N:-10000}"

BENCH="elgg-bench-real-$$"
WORK="$(mktemp -d)"
DUMP="$WORK/table.sql"
trap 'docker rm -f "$BENCH" >/dev/null 2>&1 || true; rm -rf "$WORK"' EXIT

m() { docker exec -i "$BENCH" mysql -uroot bench "$@"; }

echo "== 1. dump ${PFX}${TABLE} from live $SRC (keeps its real pre-change schema) =="
docker exec "$SRC" mysqldump -uroot -p"$SRC_ROOT_PW" --no-tablespaces --single-transaction \
  "$SRC_DB" "${PFX}${TABLE}" 2>/dev/null > "$DUMP"
echo "   dump: $(du -h "$DUMP" | cut -f1)"

echo "== 2. boot clean throwaway $ENGINE_IMAGE (tmpfs datadir, 1G buffer pool) =="
docker run -d --name "$BENCH" \
  --tmpfs /var/lib/mysql:rw \
  -e MYSQL_ALLOW_EMPTY_PASSWORD=1 \
  "$ENGINE_IMAGE" --innodb-buffer-pool-size=1G >/dev/null
# The mysql/mariadb entrypoint runs a temporary init server first; require N
# consecutive successful SELECTs so we never race the init->real-server restart.
printf "   waiting for engine (stable)"
ok=0
until [ "$ok" -ge 5 ]; do
  if docker exec "$BENCH" mysql -uroot -e 'SELECT 1' >/dev/null 2>&1; then ok=$((ok+1)); printf "+"
  else ok=0; printf "."; fi
  sleep 1
done
echo " up"

echo "== 3. load real table =="
docker exec -i "$BENCH" mysql -uroot -e "CREATE DATABASE bench;"
docker exec -i "$BENCH" mysql -uroot bench < "$DUMP"
m -N -e "SELECT CONCAT('   loaded ', COUNT(*), ' real ${TABLE} rows') FROM ${PFX}${TABLE};"

echo "== 4. build deterministic $N real (entity_guid,name) lookup workload =="
m <<SQL
-- Sample real pairs deterministically (evenly spaced by id), reindexed 0..P-1.
SET @r := -1;
CREATE TABLE pairs2 AS
SELECT seq, entity_guid, name FROM (
  SELECT (@r := @r + 1) AS seq, entity_guid, name
  FROM ${PFX}${TABLE} ORDER BY id
) s
WHERE seq % GREATEST(1, (SELECT COUNT(*) FROM ${PFX}${TABLE}) DIV ${N}) = 0
LIMIT ${N};
SET @s := -1;
CREATE TABLE pairs AS SELECT (@s := @s + 1) AS seq, entity_guid, name FROM pairs2;
DROP TABLE pairs2;
ALTER TABLE pairs ADD PRIMARY KEY(seq);

DELIMITER //
CREATE PROCEDURE point_lookups(IN n INT)
BEGIN
  DECLARE i INT DEFAULT 0; DECLARE np INT; DECLARE eg BIGINT;
  DECLARE nm VARCHAR(255); DECLARE dummy BIGINT;
  SELECT COUNT(*) INTO np FROM pairs;
  WHILE i < n DO
    SELECT entity_guid, name INTO eg, nm FROM pairs WHERE seq = i % np;   -- PK read, constant
    SELECT id INTO dummy FROM ${PFX}${TABLE} WHERE entity_guid = eg AND name = nm LIMIT 1;
    SET i = i + 1;
  END WHILE;
END//
DELIMITER ;
SELECT CONCAT('   workload pairs: ', COUNT(*)) FROM pairs;
SQL

measure() {
  local label="$1" eg nm
  echo "----- $label -----"
  m -e "ANALYZE TABLE ${PFX}${TABLE};" >/dev/null
  eg=$(m -N -e "SELECT entity_guid FROM pairs WHERE seq = (SELECT COUNT(*) DIV 2 FROM pairs);")
  nm=$(m -N -e "SELECT name FROM pairs WHERE seq = (SELECT COUNT(*) DIV 2 FROM pairs);")
  echo "EXPLAIN (entity_guid=$eg name='$nm'):"
  m -e "EXPLAIN SELECT id FROM ${PFX}${TABLE} WHERE entity_guid=$eg AND name='$nm';" | sed 's/^/  /'
  m -e "CALL point_lookups(2000);" >/dev/null   # warm buffer pool (server-global)
  echo "Handler counters for $N getIDsByName lookups:"
  # FLUSH STATUS + workload + SHOW MUST share ONE connection (counters are session-scoped)
  m -e "FLUSH STATUS; CALL point_lookups($N); SHOW SESSION STATUS WHERE Variable_name IN ('Handler_read_key','Handler_read_next');" | sed 's/^/  /'
}

echo "== 5. BEFORE (real schema as-shipped) =="
measure "BEFORE"

echo "== 6. add composite index ${INDEX_NAME} (${INDEX_COLS}) — the change under test =="
m -e "ALTER TABLE ${PFX}${TABLE} ADD INDEX ${INDEX_NAME} (${INDEX_COLS});"

echo "== 7. AFTER =="
measure "AFTER"

echo "== done (throwaway container removed on exit) =="
