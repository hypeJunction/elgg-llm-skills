#!/usr/bin/env bash
# verify-parity.sh — the EXECUTABLE definition of "done" for a site upgrade.
#
# WHY THIS EXISTS: a site upgrade is only finished when the migrated site renders
# what the old one did. "All plugins activate" + "homepage returns a title" is not
# that — a walled-garden community renders almost nothing anonymously, and a route
# can 500 for logged-in users while every activation gate stays green. This gate
# captures a route-render GOLDEN MASTER (every registered GET route, crawled anon
# AND authenticated, HTTP status per route) at each version and diffs FORWARD:
# any route that was 2xx/3xx before and is 5xx/unreachable after is a regression
# and fails the gate. Expected, reviewed changes go in a whitelist so the gate
# stays meaningful instead of being switched off.
#
# It wraps bin/baseline-golden-master.sh (the capture/diff engine, shared with the
# elgg-migrate skill). Baselines are stored in the SITE repo (via GM_BASELINE_DIR)
# so they are versioned alongside the code they describe.
#
# Usage:
#   verify-parity.sh capture <label>            # snapshot the running site (e.g. 5.x)
#   verify-parity.sh check   <prev> <label>     # capture <label>, diff vs <prev>, gate
#   verify-parity.sh diff    <prev> <label>     # gate two ALREADY-captured labels (no re-capture;
#                                               # use to re-evaluate after editing the whitelist)
#
# Typical loop, one per version step (run on the target-version stack):
#   verify-parity.sh capture 5.x                # BEFORE upgrading, on the 5.x stack
#   ... upgrade to 6.x, boot the 6.x stack ...
#   verify-parity.sh check 5.x 6.x              # fails if any route regressed
#
# Options / env (forwarded to the engine):
#   --base URL / GM_BASE        site URL inside the crawl context (default http://localhost)
#   --user U --pass P /         credentials for the authenticated crawl (REQUIRED for a
#     GM_USER / GM_PASS         walled-garden site — without them the auth battery is blind)
#   --container N / ELGG_CONTAINER   docker container to exec the crawl in (default: elgg)
#   --baseline-dir DIR / GM_BASELINE_DIR   where golden files live (default: ./baselines)
#   --whitelist FILE            routes allowed to change (default: <baseline-dir>/parity-whitelist.tsv)
#
# Whitelist format: one TSV key per line matching the golden file's column 1,
# i.e. "<anon|auth> <METHOD> <path>" (a leading '#' comments a line). A regression
# whose key is whitelisted is reported as EXPECTED and does not fail the gate.
#
# Exit codes: 0 = parity (no unexpected regressions); 1 = regressions; 2 = usage.
set -u

SELF_DIR="$(cd "$(dirname "$0")" && pwd)"
ENGINE="${GM_ENGINE:-$SELF_DIR/baseline-golden-master.sh}"
[ -x "$ENGINE" ] || { echo "verify-parity: engine not found/executable: $ENGINE" >&2; exit 2; }

BASELINE_DIR="${GM_BASELINE_DIR:-$PWD/baselines}"
WHITELIST=""
ENGINE_ARGS=()

cmd="${1:-}"; shift || true

# Split leading positionals (labels) from --flags.
POS=()
while [ $# -gt 0 ]; do
  case "$1" in
    --baseline-dir) BASELINE_DIR="$2"; shift 2;;
    --whitelist)    WHITELIST="$2"; shift 2;;
    --base|--user|--pass|--container) ENGINE_ARGS+=("$1" "$2"); shift 2;;
    --*) echo "verify-parity: unknown flag $1" >&2; exit 2;;
    *) POS+=("$1"); shift;;
  esac
done

export GM_BASELINE_DIR="$BASELINE_DIR"
mkdir -p "$BASELINE_DIR"
[ -n "$WHITELIST" ] || WHITELIST="$BASELINE_DIR/parity-whitelist.tsv"

golden_file() { echo "$BASELINE_DIR/golden-routes-$1.tsv"; }

do_capture() {
  local label="${1:-}"
  [ -n "$label" ] || { echo "capture: missing <label>" >&2; exit 2; }
  "$ENGINE" capture "$label" "${ENGINE_ARGS[@]}"
}

# Gate two already-captured golden files through the whitelist. No capture.
do_diff_gate() {
  local prev="${1:-}" label="${2:-}"
  [ -n "$prev" ] && [ -n "$label" ] || { echo "usage: verify-parity.sh diff <prev> <label>" >&2; exit 2; }

  local prevf curf; prevf="$(golden_file "$prev")"; curf="$(golden_file "$label")"
  [ -f "$prevf" ] || { echo "verify-parity: no baseline for '$prev' at $prevf" >&2; echo "  Capture it BEFORE upgrading:  verify-parity.sh capture $prev" >&2; exit 2; }
  [ -f "$curf" ]  || { echo "verify-parity: no baseline for '$label' at $curf (run capture/check first)" >&2; exit 2; }

  echo "== render parity: $prev -> $label ==" >&2
  local raw; raw="$("$ENGINE" diff "$prevf" "$curf" || true)"
  echo "$raw"

  # Filter REGRESSED/NEW-5XX lines through the whitelist (engine key is column 3).
  local unexpected=0 expected=0
  while IFS=$'\t' read -r kind plugin key rest; do
    case "$kind" in REGRESSED|NEW-5XX) ;; *) continue;; esac
    if [ -f "$WHITELIST" ] && grep -Fxq "$key" <(grep -v '^[[:space:]]*#' "$WHITELIST"); then
      echo "EXPECTED (whitelisted): $key ($plugin)" >&2
      expected=$((expected+1))
    else
      unexpected=$((unexpected+1))
    fi
  done <<< "$raw"

  echo "-- parity summary: unexpected regressions=$unexpected  whitelisted=$expected --" >&2
  if [ "$unexpected" -gt 0 ]; then
    echo "FAIL: $unexpected route(s) regressed without a whitelist entry. Fix them, or (if intended) add the exact key to $WHITELIST." >&2
    return 1
  fi
  echo "PASS: render parity holds ($label matches $prev, modulo $expected whitelisted change(s))." >&2
  return 0
}

do_check() {
  local prev="${1:-}" label="${2:-}"
  [ -n "$prev" ] && [ -n "$label" ] || { echo "check: usage: verify-parity.sh check <prev> <label>" >&2; exit 2; }

  local prevf; prevf="$(golden_file "$prev")"
  if [ ! -f "$prevf" ]; then
    echo "verify-parity: no baseline for '$prev' at $prevf" >&2
    echo "  Capture it BEFORE upgrading:  verify-parity.sh capture $prev" >&2
    exit 2
  fi

  do_capture "$label"
  do_diff_gate "$prev" "$label"
}

case "$cmd" in
  capture) do_capture "${POS[0]:-}";;
  check)   do_check "${POS[0]:-}" "${POS[1]:-}";;
  diff)    do_diff_gate "${POS[0]:-}" "${POS[1]:-}";;
  *)
    echo "usage: verify-parity.sh capture <label>" >&2
    echo "       verify-parity.sh check <prev> <label> [--user U --pass P] [--base URL] [--whitelist FILE]" >&2
    echo "       verify-parity.sh diff  <prev> <label> [--whitelist FILE]" >&2
    exit 2;;
esac
