#!/usr/bin/env bash
# River "driving table" query-refactor benchmark.
#
# Same data, two query forms:
#   BEFORE — accessibility via INNER/LEFT JOIN on entities (optimizer reorders,
#            leads with an entities scan, builds a temp table + filesort).
#   AFTER  — accessibility via correlated EXISTS() semi-joins (river drives, uses
#            the posted index, terminates at LIMIT).
#
# Boots each CI engine clean (tmpfs datadir), seeds a deterministic realistic
# dataset, then reports for each form: EXPLAIN plan, Handler_read_*/tmp counters
# (the verdict), a median wall-clock over N replays (a footnote), AND a hard
# assertion that both forms return the identical 20 river ids in the identical
# order (an access refactor must not change or leak results).
set -euo pipefail

cd "$(dirname "$0")"
COMPOSE="docker compose -f ../../../infra/docker-compose.yml"
OUT_DIR=results
mkdir -p "$OUT_DIR"

ENGINES=(
  "mysql57:mysql"
  "mysql80:mysql"
  "mysql84:mysql"
  "mariadb106:mariadb"
  "mariadb1011:mariadb"
)

REPLAYS=50   # wall-clock replays per timed form

# Ordered id list of the accessible top-20 for a given form ('before'|'after').
ids_sql() {
  local form="$1"
  if [ "$form" = before ]; then
    cat <<'SQL'
SELECT GROUP_CONCAT(id ORDER BY posted DESC) FROM (
  SELECT DISTINCT rv.id, rv.posted FROM river rv
  INNER JOIN entities se ON se.guid = rv.subject_guid
  INNER JOIN entities oe ON oe.guid = rv.object_guid
  LEFT  JOIN entities te ON te.guid = rv.target_guid
  WHERE ((se.enabled='yes') AND (se.deleted='no') AND (NOT EXISTS (SELECT 1 FROM metadata pp_md WHERE ((pp_md.entity_guid=se.guid) OR (pp_md.entity_guid=se.owner_guid)) AND (pp_md.name='plugin:user_setting:private_profiles:user_activity_setting') AND (pp_md.value='members'))) AND (se.access_id IN (2,-5)))
    AND ((oe.enabled='yes') AND (oe.deleted='no') AND (NOT EXISTS (SELECT 1 FROM metadata pp_md WHERE ((pp_md.entity_guid=oe.guid) OR (pp_md.entity_guid=oe.owner_guid)) AND (pp_md.name='plugin:user_setting:private_profiles:user_activity_setting') AND (pp_md.value='members'))) AND (oe.access_id IN (2,-5)) AND (((oe.type='user') AND (oe.subtype IN ('user'))) OR ((oe.type='group') AND (oe.subtype IN ('group'))) OR ((oe.type='object') AND (oe.subtype IN ('file','comment','blog','page','bookmarks','thewire','library_entry','library_file','discussion','feedback','hjplace','hjwall','wall_tag')))))
    AND (((te.enabled='yes') AND (te.deleted='no') AND (NOT EXISTS (SELECT 1 FROM metadata pp_md WHERE ((pp_md.entity_guid=te.guid) OR (pp_md.entity_guid=te.owner_guid)) AND (pp_md.name='plugin:user_setting:private_profiles:user_activity_setting') AND (pp_md.value='members'))) AND (te.access_id IN (2,-5))) OR (te.guid IS NULL))
  ORDER BY rv.posted DESC LIMIT 20
) t;
SQL
  else
    cat <<'SQL'
SELECT GROUP_CONCAT(id ORDER BY posted DESC) FROM (
  SELECT DISTINCT rv.id, rv.posted FROM river rv
  WHERE EXISTS (SELECT 1 FROM entities se WHERE (se.guid=rv.subject_guid) AND ((se.enabled='yes') AND (se.deleted='no') AND (NOT EXISTS (SELECT 1 FROM metadata pp_md WHERE ((pp_md.entity_guid=se.guid) OR (pp_md.entity_guid=se.owner_guid)) AND (pp_md.name='plugin:user_setting:private_profiles:user_activity_setting') AND (pp_md.value='members'))) AND (se.access_id IN (2,-5))))
    AND EXISTS (SELECT 1 FROM entities oe WHERE (oe.guid=rv.object_guid) AND (((oe.enabled='yes') AND (oe.deleted='no') AND (NOT EXISTS (SELECT 1 FROM metadata pp_md WHERE ((pp_md.entity_guid=oe.guid) OR (pp_md.entity_guid=oe.owner_guid)) AND (pp_md.name='plugin:user_setting:private_profiles:user_activity_setting') AND (pp_md.value='members'))) AND (oe.access_id IN (2,-5)) AND (((oe.type='user') AND (oe.subtype IN ('user'))) OR ((oe.type='group') AND (oe.subtype IN ('group'))) OR ((oe.type='object') AND (oe.subtype IN ('file','comment','blog','page','bookmarks','thewire','library_entry','library_file','discussion','feedback','hjplace','hjwall','wall_tag')))))))
    AND ((EXISTS (SELECT 1 FROM entities te WHERE (te.guid=rv.target_guid) AND ((te.enabled='yes') AND (te.deleted='no') AND (NOT EXISTS (SELECT 1 FROM metadata pp_md WHERE ((pp_md.entity_guid=te.guid) OR (pp_md.entity_guid=te.owner_guid)) AND (pp_md.name='plugin:user_setting:private_profiles:user_activity_setting') AND (pp_md.value='members'))) AND (te.access_id IN (2,-5))))) OR (NOT EXISTS (SELECT 1 FROM entities te WHERE te.guid=rv.target_guid)))
  ORDER BY rv.posted DESC LIMIT 20
) t;
SQL
  fi
}

time_form() {  # svc client form -> median seconds of 3x REPLAYS replays
  local svc="$1" client="$2" form="$3" t times=()
  for _ in 1 2 3; do
    t=$( { /usr/bin/time -f "%e" \
        $COMPOSE exec -T "$svc" "$client" -uroot -proot bench \
        -e "CALL run_${form}($REPLAYS);" >/dev/null; } 2>&1 | tail -1 )
    times+=("$t")
  done
  printf '%s\n' "${times[@]}" | sort -n | sed -n '2p'
}

run_engine() {
  local svc="$1" client="$2"
  local out="$OUT_DIR/$svc.txt"
  echo ">>> $svc"

  $COMPOSE up -d "$svc" >/dev/null
  local tries=0
  until $COMPOSE exec -T "$svc" "$client" -uroot -proot -e "SELECT 1" >/dev/null 2>&1; do
    sleep 2; tries=$((tries + 1))
    if [ "$tries" -gt 60 ]; then echo "!! $svc never became ready" >&2; return 1; fi
  done

  local exec="$COMPOSE exec -T $svc $client -uroot -proot"
  local ver; ver=$($exec -N -B -e "SELECT VERSION();")

  $exec < 00_schema.sql
  $exec bench < 01_seed.sql
  $exec bench < 02_procs.sql

  # Correctness gate: both forms must return the identical ordered id list.
  local before_ids after_ids verdict
  before_ids=$($exec -N -B bench -e "$(ids_sql before)")
  after_ids=$($exec  -N -B bench -e "$(ids_sql after)")
  if [ "$before_ids" = "$after_ids" ]; then verdict="IDENTICAL ✓"; else verdict="MISMATCH ✗"; fi

  {
    echo "# $svc  (server: $ver)"
    echo
    echo "## Result-set equivalence (access refactor must not change/leak rows)"
    echo "before ids: $before_ids"
    echo "after  ids: $after_ids"
    echo "verdict: $verdict"
    echo
    echo '```'
    $exec bench < 03_measure_before.sql
    echo '```'
    echo "median wall-clock, ${REPLAYS} replays x3: $(time_form "$svc" "$client" before)s"
    echo
    echo '```'
    $exec bench < 04_measure_after.sql
    echo '```'
    echo "median wall-clock, ${REPLAYS} replays x3: $(time_form "$svc" "$client" after)s"
  } | tee "$out"
  echo

  if [ "$verdict" != "IDENTICAL ✓" ]; then
    echo "!! $svc: BEFORE/AFTER result sets differ — refactor is not behaviour-preserving" >&2
  fi

  $COMPOSE stop "$svc" >/dev/null
  $COMPOSE rm -f "$svc" >/dev/null
}

for e in "${ENGINES[@]}"; do
  run_engine "${e%%:*}" "${e##*:}"
done

$COMPOSE down -v >/dev/null 2>&1 || true
echo "Done. Per-engine reports in $OUT_DIR/."
