#!/usr/bin/env bash
# run-fleet-unit-tests.sh — run every plugin's authored PHPUnit UNIT suite against
# real Elgg core inside a running container, and report pass/fail per plugin.
#
# Unit suites are safe to run fleet-wide: they are static and write nothing to the
# database. (The integration suites are NOT — they need a disposable DB, and they
# currently trip a "Cannot redeclare" fatal for plugins that ship lib/functions.php.
# a "Cannot redeclare" fatal for plugins that ship lib/functions.php, because the
# live mod/<id> and the staged mod-test/<id> both get included.)
#
# Usage:
#   bin/run-fleet-unit-tests.sh [--container <name>] [--out <ndjson>]
#
# Env:
#   ELGG_APP_CONTAINER   running Elgg container (required)
#   PLUGINS_DIR          plugin source workspace (default: <repo>/plugins)
#   ELGG_MIGRATE_BIN     dir holding run-plugin-tests.sh
#
# Exit: 0 = every populated unit suite passed, 1 = at least one failed.
set -uo pipefail

SELF_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
PLUGINS_DIR="${PLUGINS_DIR:-${ELGG_MIGRATE_PLUGINS:-}}"
[ -n "$PLUGINS_DIR" ] || { echo "ERROR: set PLUGINS_DIR or ELGG_MIGRATE_PLUGINS" >&2; exit 2; }
CONTAINER="${ELGG_APP_CONTAINER:-}"
RUNNER="${ELGG_MIGRATE_BIN:-$SELF_DIR}/run-plugin-tests.sh"
OUT=""

while [ $# -gt 0 ]; do
  case "$1" in
    --container) CONTAINER="$2"; shift 2 ;;
    --out) OUT="$2"; shift 2 ;;
    *) echo "unknown arg: $1" >&2; exit 2 ;;
  esac
done

[ -n "$CONTAINER" ] || { echo "ERROR: set ELGG_APP_CONTAINER (or pass --container)" >&2; exit 2; }
[ -f "$RUNNER" ] || { echo "ERROR: runner not found at $RUNNER" >&2; exit 2; }
docker inspect "$CONTAINER" >/dev/null 2>&1 || { echo "ERROR: container $CONTAINER not running" >&2; exit 2; }
[ -n "$OUT" ] && : > "$OUT"

# Only plugins the container actually installs can be tested against it: the suite
# runs inside the app, so a plugin absent from mod/ has no registered classes/ dir
# and every test errors with "Class ... not found" — a fixture artifact, not a
# migration defect. A plugin workspace usually holds a whole vendor catalogue, most
# of which any given site never deploys.
mapfile -t DEPLOYED < <(docker exec "$CONTAINER" sh -c 'ls /var/www/html/mod')
is_deployed() { local n="$1"; for d in "${DEPLOYED[@]}"; do [ "$d" = "$n" ] && return 0; done; return 1; }

pass=0; fail=0; empty=0; skipped=0
failed_plugins=()

for dir in "$PLUGINS_DIR"/*/; do
  id="$(basename "$dir")"
  [ -d "$dir/.git" ] || continue
  if ! is_deployed "$id"; then skipped=$((skipped+1)); continue; fi

  # A populated unit dir under either authored layout.
  unit=""
  for cand in "tests/Unit" "tests/phpunit/unit"; do
    if [ -d "$dir$cand" ] && [ -n "$(find "$dir$cand" -name '*Test.php' -print -quit 2>/dev/null)" ]; then
      unit="$cand"; break
    fi
  done
  [ -n "$unit" ] || { empty=$((empty+1)); continue; }

  log="$(ELGG_MIGRATE_PLUGINS="$PLUGINS_DIR" ELGG_APP_CONTAINER="$CONTAINER" ELGG_DB_PREFIX=elgg_ \
         bash "$RUNNER" "$id" --suite=unit 2>&1)"
  rc=$?

  # PHPUnit's own summary is the source of truth; the runner's exit code alone
  # can be masked by docker exec.
  summary="$(printf '%s\n' "$log" | grep -E '^(OK|FAILURES|ERRORS|Tests:)' | tail -1)"
  if [ "$rc" -eq 0 ] && printf '%s' "$summary" | grep -q '^OK'; then
    pass=$((pass+1))
    printf '  PASS  %-28s %s\n' "$id" "$summary"
    [ -n "$OUT" ] && printf '{"plugin":"%s","suite":"unit","status":"pass","summary":"%s"}\n' "$id" "$summary" >> "$OUT"
  else
    fail=$((fail+1)); failed_plugins+=("$id")
    printf '  FAIL  %-28s %s\n' "$id" "${summary:-no phpunit summary}"
    printf '%s\n' "$log" | tail -12 | sed 's/^/        /'
    [ -n "$OUT" ] && printf '{"plugin":"%s","suite":"unit","status":"fail","summary":"%s"}\n' "$id" "${summary:-none}" >> "$OUT"
  fi
done

echo
echo "fleet unit suites: $pass passed, $fail failed, $empty deployed plugins with no unit tests, $skipped not deployed in $CONTAINER"
if [ "$fail" -gt 0 ]; then
  printf 'failed: %s\n' "${failed_plugins[*]}" >&2
fi
[ "$fail" -eq 0 ]
