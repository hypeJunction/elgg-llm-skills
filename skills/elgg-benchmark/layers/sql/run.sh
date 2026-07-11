#!/usr/bin/env bash
#
# Reproducible before/after benchmark for the metadata (entity_guid, name) index.
#
# For every supported engine (Elgg 7.x CI matrix) this script:
#   1. boots a pristine, empty container (tmpfs storage, no persisted state),
#   2. installs the exact pre-fix `metadata` schema + deterministic 1M-row seed,
#   3. measures the getIDsByName access path BEFORE the index,
#   4. applies the index (same ALTER as the committed migration),
#   5. measures again AFTER,
#   6. writes a per-engine report under results/.
#
# No host ports are used; the DB client is exec'd inside each container.
set -euo pipefail

cd "$(dirname "$0")"
# shared clean-container stack lives one level up in the skill's infra/
COMPOSE="docker compose -f ../../infra/docker-compose.yml"
SQL_DIR=sql
OUT_DIR=results
mkdir -p "$OUT_DIR"

# service : client-binary
ENGINES=(
  "mysql80:mysql"
  "mysql84:mysql"
  "mariadb106:mariadb"
  "mariadb1011:mariadb"
)

ITERS=10000

# median of three timed runs of `iters` getIDsByName lookups, in seconds
time_lookups() {
  local svc="$1" client="$2" t times=()
  for _ in 1 2 3; do
    t=$( { /usr/bin/time -f "%e" \
        $COMPOSE exec -T "$svc" "$client" -uroot -proot bench \
        -e "CALL point_lookups($ITERS);" >/dev/null; } 2>&1 | tail -1 )
    times+=("$t")
  done
  printf '%s\n' "${times[@]}" | sort -n | sed -n '2p'
}

run_engine() {
  local svc="$1" client="$2"
  local out="$OUT_DIR/$svc.txt"
  echo ">>> $svc"

  $COMPOSE up -d "$svc" >/dev/null
  # wait until the server actually accepts a query (portable across mysql/mariadb)
  local tries=0
  until $COMPOSE exec -T "$svc" "$client" -uroot -proot -e "SELECT 1" >/dev/null 2>&1; do
    sleep 2
    tries=$((tries + 1))
    if [ "$tries" -gt 60 ]; then echo "!! $svc never became ready" >&2; return 1; fi
  done

  local exec="$COMPOSE exec -T $svc $client -uroot -proot"
  local ver; ver=$($exec -N -B -e "SELECT VERSION();")

  $exec < "$SQL_DIR/00_schema.sql"
  $exec < "$SQL_DIR/01_seed.sql" >/dev/null

  {
    echo "# $svc  (server: $ver)"
    echo
    echo "## BEFORE (single-column entity_guid index only)"
    echo '```'
    $exec < "$SQL_DIR/02_measure.sql"
    echo '```'
    echo "median wall-clock, $ITERS lookups x3: $(time_lookups "$svc" "$client")s"
    echo

    $exec < "$SQL_DIR/03_add_index.sql" >/dev/null

    echo "## AFTER (composite entity_guid_name index)"
    echo '```'
    $exec < "$SQL_DIR/02_measure.sql"
    echo '```'
    echo "median wall-clock, $ITERS lookups x3: $(time_lookups "$svc" "$client")s"
  } | tee "$out"
  echo

  $COMPOSE stop "$svc" >/dev/null
  $COMPOSE rm -f "$svc" >/dev/null
}

for e in "${ENGINES[@]}"; do
  run_engine "${e%%:*}" "${e##*:}"
done

$COMPOSE down -v >/dev/null 2>&1 || true
echo "Done. Per-engine reports in $OUT_DIR/."
