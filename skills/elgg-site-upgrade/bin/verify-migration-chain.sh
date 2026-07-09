#!/usr/bin/env bash
# verify-migration-chain.sh — chain DB-UPGRADE test across version tiers
# (2.x → 3.x → … → 7.x). Companion to verify-migration-path.sh: where the path
# script proves each tier INSTALLS cleanly in isolation, this proves Elgg's
# UPGRADE scripts (core Phinx schema migrations + plugin Elgg\Upgrade\Batch jobs)
# actually run against carried-forward production-shape state.
#
# Per NEXT tier: dump PREV (mysqldump + tar dataroot) → preseed NEXT's named
# volumes (restore SQL in a throwaway mysql + extract dataroot) → boot NEXT (must
# SKIP install because tables exist) → optional CHAIN_PRE_PHINX_<VER>_SQL hook →
# `phinx migrate` (schema) → `elgg-cli upgrade` (Batch) →
# optional CHAIN_POST_UPGRADE_<VER>_SQL hook → optional plugin
# activation reconcile → verify HTTP + scan logs for fatals → PREV=NEXT.
#
# WARNING: `php upgrade.php` is a WEB bootstrap (emits an HTTP redirect), NOT a
# CLI runner — this uses `vendor/bin/phinx migrate` directly, as Elgg intends.
#
# Tiers: a --stacks file (label|compose-dir|project|elgg-port|db-port), or the
# skill's own infra/elgg{2..7}. The seed tier (first) is installed fresh unless
# CHAIN_SEED_SQL[/CHAIN_SEED_DATA] overlay a production dump (build one PII-safe
# with bin/build-anon-seed.sh).
#
# Env hooks:
#   CHAIN_SEED_SQL, CHAIN_SEED_DATA           overlay the seed tier with prod data
#   CHAIN_PRE_PHINX_<LABEL>_SQL               inject cleanup SQL before phinx (e.g.
#                                             CHAIN_PRE_PHINX_3X_SQL for a 2x→3x fix)
#   CHAIN_POST_UPGRADE_<LABEL>_SQL            inject data repair AFTER phinx + Batch
#                                             (e.g. CHAIN_POST_UPGRADE_4X_SQL=references/
#                                             4x-post-lowercase-plugin-settings.sql)
#   CHAIN_ACTIVE_PLUGINS_TSV                  reconcile prod-active plugins per tier
#                                             (uses the sibling restore-active-plugins.sh)
#   CHAIN_KEEP=1                              leave the last tier running
#
# Usage:
#   verify-migration-chain.sh [--stacks FILE] [--service NAME] [--db-service NAME]
#   verify-migration-chain.sh 3 4                 # only these NEXT tiers
#
# NOTE: needs a Docker host with asset-packagist network (image builds) plus a
# seed dump for a meaningful run; each tier MUTATES its own named volumes only.
# The generic plumbing is syntax-checked; a full chain run needs that infra.
set -u

SELF_DIR="$(cd "$(dirname "$0")" && pwd)"
SKILL_ROOT="$(cd "$SELF_DIR/.." && pwd)"
INFRA="$SKILL_ROOT/infra"
RESTORE_SH="$SELF_DIR/restore-active-plugins.sh"

STACKS_FILE=""; SERVICE="elgg"; DB_SERVICE="db"; WANT_NEXT=()
while [ $# -gt 0 ]; do
  case "$1" in
    --stacks) STACKS_FILE="$2"; shift 2 ;;
    --service) SERVICE="$2"; shift 2 ;;
    --db-service) DB_SERVICE="$2"; shift 2 ;;
    --*) echo "unknown flag: $1" >&2; exit 2 ;;
    *) WANT_NEXT+=("$1"); shift ;;
  esac
done

REPORT="${REPORT:-$(mktemp -t chain-report.XXXXXX)}"
CHAIN_DIR="$(mktemp -d -t chain.XXXXXX)"
SNAP_ROOT="$(mktemp -d -t chain-snap.XXXXXX)"
: > "$REPORT"
log() { echo "[$(date +%H:%M:%S)] $*" | tee -a "$REPORT"; }

SNAPSHOT_PATHS=(/ /login /members /activity)
# shellcheck disable=SC2206
[ -n "${SNAPSHOT_PATHS_EXTRA:-}" ] && SNAPSHOT_PATHS+=(${SNAPSHOT_PATHS_EXTRA})

# STACKS[i] = "label|dir|project|elgg_port|db_port"
STACKS=()
if [ -n "$STACKS_FILE" ]; then
  [ -f "$STACKS_FILE" ] || { echo "stacks file not found: $STACKS_FILE" >&2; exit 2; }
  while IFS= read -r line; do
    line="${line%%#*}"; [ -z "${line// }" ] && continue
    line="$(printf '%s' "$line" | tr '\t' '|' | tr -s ' ' ' ')"
    IFS='| ' read -r lbl dir proj eport dport <<< "$line"
    [ -n "$lbl" ] && [ -n "$dir" ] || continue
    STACKS+=("$lbl|$dir|${proj:-chain-$lbl}|${eport:-0}|${dport:-0}")
  done < "$STACKS_FILE"
else
  for n in 2 3 4 5 6 7; do
    [ -d "$INFRA/elgg$n" ] && STACKS+=("$n|$INFRA/elgg$n|chain-elgg$n|$((17000 + n * 10))|$((17100 + n * 10))")
  done
fi
[ ${#STACKS[@]} -ge 2 ] || { echo "need >=2 tiers to chain" >&2; exit 2; }

want_next() { [ ${#WANT_NEXT[@]} -eq 0 ] && return 0; for w in "${WANT_NEXT[@]}"; do [ "$w" = "$1" ] && return 0; done; return 1; }
app_c() { echo "$1-$SERVICE-1"; }
db_c()  { echo "$1-$DB_SERVICE-1"; }

dc_for() {
  local dir="$1" proj="$2" args="-f $dir/docker-compose.yml"
  [ -f "$dir/docker-compose.override.yml" ] && args="$args -f $dir/docker-compose.override.yml"
  echo "docker compose -p $proj $args"
}

export_tier_env() { export ELGG_PORT="$1" DB_PORT="$2" PLUGINS_DIR="${PLUGINS_DIR:-/tmp}" PLUGIN_ID="${PLUGIN_ID:-_chain}"; }

wait_http() {
  local port="$1" code="" t=0
  while [ $t -lt 120 ]; do
    code=$(curl -sL -m 10 -o /dev/null -w '%{http_code}' "http://localhost:$port/" 2>/dev/null)
    { [ "$code" = "200" ] || [ "$code" = "500" ]; } && break
    t=$((t+1)); sleep 5
  done
  echo "$code"
}

snapshot_pages() {
  local lbl="$1" port="$2" dir="$SNAP_ROOT/$lbl"; mkdir -p "$dir"; : > "$dir/pages.tsv"
  for p in "${SNAPSHOT_PATHS[@]}"; do
    local html="$dir/$(echo "${p#/}" | tr '/' '_' | tr -cd 'a-zA-Z0-9_.-').html" code title
    code=$(curl -sL -m 10 -o "$html" -w '%{http_code}' "http://localhost:${port}${p}" 2>/dev/null)
    title=$(grep -oP '<title>[^<]*</title>' "$html" 2>/dev/null | head -1 | sed -e 's|<title>||' -e 's|</title>||')
    log "        $p → HTTP $code '${title:--}'"
  done
}

# Seed the first tier fresh, optionally overlaying a production dump.
seed_tier() {
  local lbl="$1" dir="$2" proj="$3" eport="$4" dport="$5"
  export_tier_env "$eport" "$dport"
  local dc; dc=$(dc_for "$dir" "$proj")
  log "===== SEED tier $lbl ($proj :$eport) ====="
  $dc down -v --remove-orphans >/dev/null 2>&1 || true
  $dc build "$SERVICE" >"$CHAIN_DIR/build-$proj.log" 2>&1 || { log "  BUILD FAILED — $CHAIN_DIR/build-$proj.log"; return 1; }
  $dc up -d "$SERVICE" "$DB_SERVICE" >/dev/null 2>&1
  local code; code=$(wait_http "$eport"); log "  seed HTTP $code"
  if [ -n "${CHAIN_SEED_SQL:-}" ] && [ -f "${CHAIN_SEED_SQL}" ]; then
    log "  [chain-seed] overlaying $CHAIN_SEED_SQL"
    local cat_cmd=cat; case "$CHAIN_SEED_SQL" in *.gz) cat_cmd="gunzip -c";; esac
    docker exec "$(db_c "$proj")" mysql -uelgg -pelgg -e 'DROP DATABASE IF EXISTS elgg; CREATE DATABASE elgg;' 2>/dev/null || true
    $cat_cmd "$CHAIN_SEED_SQL" | docker exec -i "$(db_c "$proj")" mysql -uelgg -pelgg elgg 2>"$CHAIN_DIR/seed-$proj.err" \
      || { log "    CHAIN_SEED restore FAILED — $CHAIN_DIR/seed-$proj.err"; return 1; }
    [ -n "${CHAIN_SEED_DATA:-}" ] && [ -f "${CHAIN_SEED_DATA}" ] && \
      docker run --rm -v "${proj}_elgg-data:/d" -v "$(dirname "$CHAIN_SEED_DATA"):/host:ro" alpine \
        sh -c "tar xzf /host/$(basename "$CHAIN_SEED_DATA") -C /d && chown -R 33:33 /d" >/dev/null 2>&1
    $dc restart "$SERVICE" >/dev/null 2>&1; wait_http "$eport" >/dev/null
  fi
  snapshot_pages "$lbl" "$eport"
}

dump_tier() {
  local lbl="$1" proj="$2"
  docker exec "$(db_c "$proj")" sh -c 'mysqldump -uelgg -pelgg --single-transaction --no-tablespaces --routines --triggers --events elgg' \
    > "$CHAIN_DIR/db-$lbl.sql" 2>"$CHAIN_DIR/db-$lbl.err" || { log "  DUMP FAILED — $CHAIN_DIR/db-$lbl.err"; return 1; }
  docker run --rm -v "${proj}_elgg-data:/d:ro" -v "$CHAIN_DIR:/host" alpine tar czf "/host/data-$lbl.tar.gz" -C /d . >/dev/null 2>&1 || true
  log "  [dump] $lbl db+dataroot captured"
}

preseed_next() {
  local prev="$1" next_proj="$2"
  docker volume create "${next_proj}_db-data" >/dev/null 2>&1
  docker volume create "${next_proj}_elgg-data" >/dev/null 2>&1
  docker run --rm -v "${next_proj}_db-data:/d" alpine sh -c 'rm -rf /d/* /d/.[!.]* 2>/dev/null || true'
  docker run --rm -v "${next_proj}_elgg-data:/d" alpine sh -c 'rm -rf /d/* /d/.[!.]* 2>/dev/null || true'
  # Restore the SQL into the fresh db-data volume via a throwaway mysql:5.7.
  local rn="chain-restore-$next_proj"; docker rm -f "$rn" >/dev/null 2>&1 || true
  docker run --rm -d --name "$rn" -v "${next_proj}_db-data:/var/lib/mysql" \
    -e MYSQL_ROOT_PASSWORD=root -e MYSQL_DATABASE=elgg -e MYSQL_USER=elgg -e MYSQL_PASSWORD=elgg \
    mysql:5.7 --default-authentication-plugin=mysql_native_password >/dev/null
  local t=0; until docker exec "$rn" mysql -uroot -proot -N -e 'SELECT 1' >/dev/null 2>&1; do t=$((t+1)); [ $t -gt 60 ] && { log "  restore mysql never ready"; docker stop "$rn" >/dev/null; return 1; }; sleep 2; done
  local rc=0; while [ $rc -lt 2 ]; do rc=$(docker logs "$rn" 2>&1 | grep -cE 'mysqld:.*ready for connections' || true); [ $rc -ge 2 ] && break; sleep 2; done
  until docker exec "$rn" mysql -uroot -proot -N -e 'SELECT 1' >/dev/null 2>&1; do sleep 2; done
  docker exec -i "$rn" mysql -uroot -proot elgg < "$CHAIN_DIR/db-$prev.sql" 2>"$CHAIN_DIR/restore-$next_proj.err" \
    || { log "  RESTORE FAILED — $CHAIN_DIR/restore-$next_proj.err"; docker stop "$rn" >/dev/null; return 1; }
  docker stop "$rn" >/dev/null
  # Restore dataroot (drop caches; chown to Debian www-data uid 33).
  [ -f "$CHAIN_DIR/data-$prev.tar.gz" ] && docker run --rm -v "${next_proj}_elgg-data:/d" -v "$CHAIN_DIR:/host:ro" alpine \
    sh -c "tar xzf /host/data-$prev.tar.gz -C /d && rm -rf /d/caches /d/cache /d/system_cache /d/views_simplecache 2>/dev/null; chown -R 33:33 /d; find /d -type d -exec chmod 0775 {} \;" >/dev/null 2>&1
}

boot_and_upgrade() {
  local lbl="$1" dir="$2" proj="$3" eport="$4" dport="$5"
  export_tier_env "$eport" "$dport"
  local dc; dc=$(dc_for "$dir" "$proj")
  $dc build "$SERVICE" >"$CHAIN_DIR/build-$proj.log" 2>&1 || { log "  BUILD FAILED — $CHAIN_DIR/build-$proj.log"; return 1; }
  $dc up -d "$SERVICE" >/dev/null 2>&1
  local code; code=$(wait_http "$eport"); log "  [boot] $lbl HTTP $code"
  [ "$code" = "200" ] || [ "$code" = "500" ] || { log "    NEVER CAME UP"; return 1; }
  docker logs "$(app_c "$proj")" > "$CHAIN_DIR/entrypoint-$proj.log" 2>&1
  if grep -q 'Installing Elgg' "$CHAIN_DIR/entrypoint-$proj.log"; then
    log "    ⚠ entrypoint ran a FRESH install — chain state NOT carried forward"; return 1
  fi

  local hv="CHAIN_PRE_PHINX_$(echo "$lbl" | tr '[:lower:]' '[:upper:]')_SQL"; local hp="${!hv:-}"
  if [ -n "$hp" ] && [ -f "$hp" ]; then
    log "  [pre-phinx] applying $hp"
    docker exec -i "$(db_c "$proj")" mysql -uelgg -pelgg elgg < "$hp" >"$CHAIN_DIR/pre-phinx-$proj.log" 2>&1 \
      || { log "    PRE-PHINX SQL FAILED — $CHAIN_DIR/pre-phinx-$proj.log"; return 1; }
  fi

  local cfg; cfg=$(docker exec "$(app_c "$proj")" sh -c '
    for p in vendor/elgg/elgg/engine/schema/migrations.php vendor/elgg/elgg/engine/conf/migrations.php; do
      [ -f "$p" ] && { echo "$p"; exit 0; }; done; exit 1' 2>/dev/null)
  [ -n "$cfg" ] || { log "    NO PHINX CONFIG — schema migration impossible"; return 1; }
  log "  [upgrade 1/2] phinx migrate -c $cfg"
  if ! docker exec -u www-data "$(app_c "$proj")" php vendor/bin/phinx migrate -c "$cfg" >"$CHAIN_DIR/schema-$proj.log" 2>&1; then
    log "    PHINX NON-ZERO — tail:"; tail -20 "$CHAIN_DIR/schema-$proj.log" | sed 's/^/      /' | tee -a "$REPORT"
    grep -qE 'InstallationException|reserved subtype' "$CHAIN_DIR/schema-$proj.log" && log "    → set CHAIN_PRE_PHINX_${lbl}_SQL to a cleanup script."
    return 1
  fi
  grep -qE 'Phinx\\.*Exception|SQLSTATE|Fatal error|Uncaught|Unknown column|InstallationException' "$CHAIN_DIR/schema-$proj.log" \
    && { log "    ⚠ phinx fatals"; grep -E 'Exception|SQLSTATE|Fatal|Unknown column' "$CHAIN_DIR/schema-$proj.log" | head -5 | sed 's/^/      /' | tee -a "$REPORT"; return 1; }

  log "  [upgrade 2/2] elgg-cli upgrade (Batch)"
  docker exec -u www-data "$(app_c "$proj")" php vendor/elgg/elgg/elgg-cli upgrade -v --quiet >"$CHAIN_DIR/batch-$proj.log" 2>&1 || true
  # Include Symfony Console 'Upgrade class … was not found' — aborts non-zero
  # WITHOUT a PHP fatal, so a fatal-only grep would miss it (a real cutover breaker).
  local pat='Phinx\\.*Exception|SQLSTATE|Elgg\\Upgrade.*failed|Fatal error|Uncaught|Upgrade class .* was not found|Locator\.php'
  grep -cE "$pat" "$CHAIN_DIR/batch-$proj.log" 2>/dev/null | grep -qvx 0 \
    && { log "    ⚠ batch fatals:"; grep -E "$pat" "$CHAIN_DIR/batch-$proj.log" | head -5 | sed 's/^/      /' | tee -a "$REPORT"; return 1; }

  # Optional post-upgrade SQL hook — data repairs that can only run once BOTH the
  # schema and Batch upgrades have landed for this tier. The canonical case is the
  # 4.x plugin-id lowercasing, which strands every stored plugin setting on the old
  # camelCase plugin entity; the copy has to happen after `elgg-cli upgrade` has
  # created the lowercase twin, and before anything deletes the orphan.
  #   CHAIN_POST_UPGRADE_4X_SQL=references/4x-post-lowercase-plugin-settings.sql
  local uv="CHAIN_POST_UPGRADE_$(echo "$lbl" | tr '[:lower:]' '[:upper:]')_SQL"; local up="${!uv:-}"
  if [ -n "$up" ] && [ -f "$up" ]; then
    log "  [post-upgrade] applying $up"
    docker exec -i "$(db_c "$proj")" mysql -uelgg -pelgg elgg <"$up" >"$CHAIN_DIR/post-upgrade-$proj.log" 2>&1 \
      || { log "    POST-UPGRADE SQL FAILED — $CHAIN_DIR/post-upgrade-$proj.log"; return 1; }
  fi

  docker exec "$(app_c "$proj")" sh -c 'chown -R www-data:www-data /var/data/elgg 2>/dev/null || true' >/dev/null 2>&1

  if [ -n "${CHAIN_ACTIVE_PLUGINS_TSV:-}" ] && [ -f "${CHAIN_ACTIVE_PLUGINS_TSV}" ] && [ -x "$RESTORE_SH" ]; then
    log "  [activation] reconciling prod active set (case-insensitive ∩ on-disk)"
    "$RESTORE_SH" --tsv "$CHAIN_ACTIVE_PLUGINS_TSV" --db-container "$(db_c "$proj")" --app-container "$(app_c "$proj")" \
      >>"$CHAIN_DIR/activation-$proj.log" 2>&1 || log "    (activation reconcile reported issues — activation-$proj.log)"
    ( cd "$dir" && $dc restart "$SERVICE" >/dev/null 2>&1 ); wait_http "$eport" >/dev/null
  fi

  snapshot_pages "$lbl" "$eport"
  log "    tier $lbl upgraded ✓"
}

log "### migration-chain verification — DB carried forward per tier ###"
prev_lbl=""; prev_proj=""; rc=0
first=1
for s in "${STACKS[@]}"; do
  IFS='|' read -r lbl dir proj eport dport <<< "$s"
  if [ "$first" = 1 ]; then
    seed_tier "$lbl" "$dir" "$proj" "$eport" "$dport" || { rc=1; break; }
    prev_lbl="$lbl"; prev_proj="$proj"; first=0; continue
  fi
  if want_next "$lbl"; then
    log "===== NEXT tier $lbl (from $prev_lbl) ====="
    dump_tier "$prev_lbl" "$prev_proj" || { rc=1; break; }
    ( cd "$dir" && $(dc_for "$dir" "$proj") down -v --remove-orphans >/dev/null 2>&1 ) || true
    ( cd "$(dirname "$prev_proj")" 2>/dev/null; true )
    preseed_next "$prev_lbl" "$proj" || { rc=1; break; }
    boot_and_upgrade "$lbl" "$dir" "$proj" "$eport" "$dport" || { rc=1; break; }
  fi
  # tear down PREV to free ports/volumes (its dump is on the host)
  ( cd "$(dirname "$0")"; docker compose -p "$prev_proj" down -v --remove-orphans >/dev/null 2>&1 ) || true
  prev_lbl="$lbl"; prev_proj="$proj"
done
[ "${CHAIN_KEEP:-0}" = 1 ] || ( docker compose -p "$prev_proj" down -v --remove-orphans >/dev/null 2>&1 ) || true
log "### DONE — report: $REPORT  work: $CHAIN_DIR ###"
exit "$rc"
