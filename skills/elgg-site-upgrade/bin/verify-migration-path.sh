#!/usr/bin/env bash
# verify-migration-path.sh — clean-REBUILD every version tier of a migration path
# in isolation and verify each one boots, activates, renders, and does not drift
# from the previous tier. This is the per-tier "does a FRESH install of tier N
# still render what tier N-1 did" net — complementary to verify-parity.sh (which
# diffs the SAME site before/after an in-place upgrade).
#
# For each tier: tear down + drop volumes (fresh every run) → rebuild image →
# boot → wait for install → check homepage 200 + simplecache CSS non-empty →
# snapshot a fixed set of pages (HTTP code, size, title) → optionally capture a
# route-render golden master (bin/baseline-golden-master.sh) and forward-diff it
# against the prior tier. After all tiers, a SNAPSHOT COMPARISON flags any page
# that drifts from the baseline tier (HTTP code change, >±N% byte drift, or a
# "Fatal Error" title where the baseline had a real one).
#
# Tiers come from a stacks file (--stacks), or default to this skill's own
# infra/elgg{2..7} clean-Elgg stacks (a self-test of the infra itself). A real
# site supplies its per-version compose dirs:
#
#   stacks file: one tier per line, TAB- or |-separated:
#     <label>  <compose-dir>  <project>  <elgg-port>  [db-port]
#   e.g.
#     3x  /srv/site-3x  site3x  8380  3380
#     4x  /srv/site-4x  site4x  8480  3480
#
# Usage:
#   verify-migration-path.sh [--stacks FILE] [--service NAME] [--golden]
#                            [--baseline LABEL] [--drift-pct N] [label ...]
#   verify-migration-path.sh 3 4            # default infra self-test, elgg3+elgg4
#
# Env: SNAPSHOT_PATHS_EXTRA (space-separated extra paths), GM_USER/GM_PASS
#      (authenticated golden-master crawl), INSTALL_TIMEOUT (default 480s).
#
# NOTE: each tier is a full `docker compose build` (composer install) + boot, so
# a network-capable Docker host is required — an Elgg image pulls npm-asset deps
# from asset-packagist at build time. The generic tier plumbing (stacks parse,
# ${PLUGIN_ID}/${PLUGINS_DIR} interpolation, build/boot/snapshot/diff, drift
# compare) is exercised by bin/ tests; a full multi-tier boot needs that network.
set -u

SELF_DIR="$(cd "$(dirname "$0")" && pwd)"
SKILL_ROOT="$(cd "$SELF_DIR/.." && pwd)"
INFRA="$SKILL_ROOT/infra"
GM_SCRIPT="$SELF_DIR/baseline-golden-master.sh"

STACKS_FILE=""
SERVICE="${ELGG_SERVICE:-elgg}"
# The db service name is a compose detail, not a constant: a site's stack may call
# it "mysql" or "mariadb". Everything else here is already parameterised.
DB_SERVICE="${ELGG_DB_SERVICE:-db}"
DO_GOLDEN=0
BASELINE=""
DRIFT_PCT="${SNAPSHOT_DRIFT_PCT:-50}"
INSTALL_TIMEOUT="${INSTALL_TIMEOUT:-480}"
WANT=()

while [ $# -gt 0 ]; do
  case "$1" in
    --stacks) STACKS_FILE="$2"; shift 2 ;;
    --service) SERVICE="$2"; shift 2 ;;
    --golden) DO_GOLDEN=1; shift ;;
    --baseline) BASELINE="$2"; shift 2 ;;
    --drift-pct) DRIFT_PCT="$2"; shift 2 ;;
    --*) echo "unknown flag: $1" >&2; exit 2 ;;
    *) WANT+=("$1"); shift ;;
  esac
done

REPORT="${REPORT:-$(mktemp -t migpath-report.XXXXXX)}"
SNAP_ROOT="$(mktemp -d -t migpath-snap.XXXXXX)"
: > "$REPORT"
log() { echo "[$(date +%H:%M:%S)] $*" | tee -a "$REPORT"; }

# Core anonymous-reachable pages present in stock Elgg. Missing (404) is treated
# as drift: a page that rendered on the baseline tier must render on later ones.
SNAPSHOT_PATHS=(/ /login /members /activity /blog/all /file/all /bookmarks/all /search /this-route-should-not-exist-404-control)
# shellcheck disable=SC2206
[ -n "${SNAPSHOT_PATHS_EXTRA:-}" ] && SNAPSHOT_PATHS+=(${SNAPSHOT_PATHS_EXTRA})

# --- Build the tier list -----------------------------------------------------
# STACKS[i] = "label|dir|project|elgg_port|db_port"
STACKS=()
if [ -n "$STACKS_FILE" ]; then
  [ -f "$STACKS_FILE" ] || { echo "stacks file not found: $STACKS_FILE" >&2; exit 2; }
  while IFS= read -r line; do
    line="${line%%#*}"; [ -z "${line// }" ] && continue
    # accept TAB or | separators
    line="$(printf '%s' "$line" | tr '\t' '|' | tr -s ' ' ' ')"
    IFS='| ' read -r lbl dir proj eport dport <<< "$line"
    [ -n "$lbl" ] && [ -n "$dir" ] || continue
    STACKS+=("$lbl|$dir|${proj:-mp-$lbl}|${eport:-0}|${dport:-0}")
  done < "$STACKS_FILE"
else
  # Default: this skill's infra/elgg{2..7} clean stacks. High host ports to dodge
  # the user's dev containers; unique per version.
  for n in 2 3 4 5 6 7; do
    [ -d "$INFRA/elgg$n" ] || continue
    STACKS+=("$n|$INFRA/elgg$n|mp-elgg$n|$((18000 + n * 10))|$((18100 + n * 10))")
  done
fi

want() { [ ${#WANT[@]} -eq 0 ] && return 0; for w in "${WANT[@]}"; do [ "$w" = "$1" ] && return 0; done; return 1; }
# Baseline defaults to the FIRST tier that will actually run (respects a subset).
if [ -z "$BASELINE" ]; then
  for s in "${STACKS[@]}"; do IFS='|' read -r v _ _ _ _ <<< "$s"; if want "$v"; then BASELINE="$v"; break; fi; done
fi

path_slug() { [ "$1" = "/" ] && echo index || { echo "${1#/}" | tr '/' '_' | tr -cd 'a-zA-Z0-9_.-'; }; }

snapshot_pages() {
  local lbl="$1" port="$2" dir="$SNAP_ROOT/$lbl"
  mkdir -p "$dir"; : > "$dir/pages.tsv"
  log "  [snap] ${#SNAPSHOT_PATHS[@]} pages → $dir"
  for p in "${SNAPSHOT_PATHS[@]}"; do
    local html="$dir/$(path_slug "$p").html" code size title
    code=$(curl -sL -m 10 -o "$html" -w '%{http_code}' "http://localhost:${port}${p}" 2>/dev/null)
    size=$(wc -c < "$html" 2>/dev/null | tr -d ' ')
    title=$(grep -oP '<title>[^<]*</title>' "$html" 2>/dev/null | head -1 | sed -e 's|<title>||' -e 's|</title>||')
    printf '%s\t%s\t%s\t%s\n' "$p" "$code" "$size" "$title" >> "$dir/pages.tsv"
    log "        $p → HTTP $code ${size}B '${title:--}'"
  done
}

verify_stack() {
  local lbl="$1" dir="$2" proj="$3" eport="$4" dport="$5"
  log "===== tier $lbl ($proj :$eport) dir=$dir ====="
  [ -d "$dir" ] || { log "  SKIP — dir missing"; return; }
  local dc="docker compose -p $proj -f $dir/docker-compose.yml"
  # The infra compose declares a `node` test-profile service whose volume uses
  # ${PLUGINS_DIR:?}/${PLUGIN_ID:?}; compose interpolates every service at parse
  # time even though we never start `node`, so export harmless placeholders.
  export ELGG_PORT="$eport" DB_PORT="$dport" \
         PLUGINS_DIR="${PLUGINS_DIR:-/tmp}" PLUGIN_ID="${PLUGIN_ID:-_migpath}"

  log "  [1/5] down -v"; $dc down -v --remove-orphans >/dev/null 2>&1 || true
  log "  [2/5] build $SERVICE"
  if ! $dc build "$SERVICE" >"$SNAP_ROOT/build-$proj.log" 2>&1; then
    log "  BUILD FAILED — see $SNAP_ROOT/build-$proj.log"; tail -4 "$SNAP_ROOT/build-$proj.log" | sed 's/^/    /' | tee -a "$REPORT"; return 1
  fi
  log "  [3/5] up -d"; $dc up -d "$SERVICE" "$DB_SERVICE" >/dev/null 2>&1

  log "  [4/5] waiting for install (sentinel or homepage, ${INSTALL_TIMEOUT}s)"
  local cid code="" waited=0
  cid="$($dc ps -q "$SERVICE" 2>/dev/null)"
  while [ "$waited" -lt "$INSTALL_TIMEOUT" ]; do
    if [ -n "$cid" ] && docker exec "$cid" test -f /var/www/html/.elgg-installed 2>/dev/null; then break; fi
    code=$(curl -sL -m 10 -o /dev/null -w '%{http_code}' "http://localhost:$eport/" 2>/dev/null)
    { [ "$code" = "200" ] || [ "$code" = "500" ]; } && break
    waited=$((waited+5)); sleep 5
  done

  local title
  title=$(curl -sL -m 15 "http://localhost:$eport/" 2>/dev/null | grep -oP '<title>[^<]*</title>' | head -1)
  code=$(curl -sL -m 10 -o /dev/null -w '%{http_code}' "http://localhost:$eport/" 2>/dev/null)
  log "  [5/5] homepage HTTP $code ${title:+$title}"
  local ts size
  ts=$(curl -sL -m 15 "http://localhost:$eport/" 2>/dev/null | grep -oP 'cache/\K[0-9]+' | head -1)
  if [ -n "$ts" ]; then
    size=$(curl -sL -m 15 -o /dev/null -w '%{size_download}' "http://localhost:$eport/cache/${ts}/default/elgg.css" 2>/dev/null)
    log "        simplecache elgg.css ${size}B $([ "${size:-0}" -lt 1000 ] && echo '⚠ TOO SMALL')"
  fi

  snapshot_pages "$lbl" "$eport"

  if [ "$DO_GOLDEN" = "1" ] && [ -x "$GM_SCRIPT" ]; then
    GM_BASELINE_DIR="$SNAP_ROOT/golden" ELGG_CONTAINER="$cid" \
      "$GM_SCRIPT" capture "$lbl" --container "$cid" --base http://localhost \
        ${GM_USER:+--user "$GM_USER"} ${GM_PASS:+--pass "$GM_PASS"} >>"$REPORT" 2>&1 || log "        [gm] capture failed (see report)"
  fi

  log "  [down] $proj"; $dc down -v --remove-orphans >/dev/null 2>&1 || true
  log "  ----- tier $lbl done -----"
}

compare_snapshots() {
  local base="$SNAP_ROOT/$BASELINE/pages.tsv"
  [ -f "$base" ] || { log "baseline snapshot '$BASELINE' missing — no comparison"; return; }
  log "========= SNAPSHOT COMPARISON (baseline=$BASELINE, band ±${DRIFT_PCT}%) ========="
  local drifted=0
  while IFS=$'\t' read -r path b_code b_size b_title; do
    for s in "${STACKS[@]}"; do
      IFS='|' read -r v _ _ _ _ <<< "$s"; [ "$v" = "$BASELINE" ] && continue
      local row; row=$(grep -P "^${path}\t" "$SNAP_ROOT/$v/pages.tsv" 2>/dev/null | head -1) || true
      [ -z "$row" ] && continue
      local v_code v_size v_title st="OK"; IFS=$'\t' read -r _ v_code v_size v_title <<< "$row"
      local lo=$(( b_size * (100 - DRIFT_PCT) / 100 )) hi=$(( b_size * (100 + DRIFT_PCT) / 100 ))
      if [ "$v_code" != "$b_code" ]; then st="DRIFT http $v_code vs $b_code"
      elif [ "$b_size" -gt 0 ] && { [ "$v_size" -lt "$lo" ] || [ "$v_size" -gt "$hi" ]; }; then st="DRIFT size ${v_size}B outside ${lo}-${hi}"
      elif [ -n "$b_title" ] && [ "$v_title" = "Fatal Error." ]; then st="DRIFT fatal-title"; fi
      [ "$st" = "OK" ] || { drifted=$((drifted+1)); log "  $path @ $v: $st"; }
    done
  done < "$base"
  log "snapshot comparison: $drifted drift(s)"
  return "$drifted"
}

log "### migration-path verification — clean rebuild per tier ###"
[ ${#STACKS[@]} -gt 0 ] || { echo "no tiers to run" >&2; exit 2; }
rc=0
for s in "${STACKS[@]}"; do
  IFS='|' read -r lbl dir proj eport dport <<< "$s"
  want "$lbl" && { verify_stack "$lbl" "$dir" "$proj" "$eport" "$dport" || rc=1; }
done
compare_snapshots || rc=1
log "### DONE — report: $REPORT  snapshots: $SNAP_ROOT ###"
exit "$rc"
