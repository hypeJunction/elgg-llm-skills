#!/usr/bin/env bash
# run-fleet-verification.sh [plugins_dir] [output_file]
#
# Runs verify-plugin-branches.py on every plugin in the fleet and aggregates
# NDJSON results into a single file for review / beads filing.
#
# Plugins directory is resolved from (in order):
#   1. First positional argument
#   2. $ELGG_PLUGINS_DIR environment variable
#   3. bin/discover-plugins.sh output
#
# Defaults:
#   output_file  = /tmp/fleet-verification-results.ndjson

set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "$0")" && pwd)"
VERIFY="${SCRIPT_DIR}/verify-plugin-branches.py"

# Resolve plugins dir
PLUGINS_DIR=""
if [[ -n "${1:-}" ]] && [[ -d "$1" ]]; then
    PLUGINS_DIR="$1"
    shift
elif [[ -n "${ELGG_PLUGINS_DIR:-}" ]]; then
    PLUGINS_DIR="$ELGG_PLUGINS_DIR"
elif [[ -x "$SCRIPT_DIR/discover-plugins.sh" ]]; then
    _disc_out="$(bash "$SCRIPT_DIR/discover-plugins.sh" 2>/dev/null || true)"
    PLUGINS_DIR="$(echo "$_disc_out" | grep 'PLUGINS_DIR=' | head -1 | cut -d= -f2-)"
    unset _disc_out
fi

if [[ ! -d "${PLUGINS_DIR:-}" ]]; then
    echo "ERROR: Set ELGG_PLUGINS_DIR=/path/to/your/plugins or run:" >&2
    echo "  bin/discover-plugins.sh --root /path/to/plugins --save-config" >&2
    exit 1
fi

OUTPUT="${1:-/tmp/fleet-verification-results.ndjson}"

if [[ ! -x "$VERIFY" ]]; then
  chmod +x "$VERIFY"
fi

# Collect all plugin dirs (must contain a .git dir)
PLUGINS=()
while IFS= read -r -d '' d; do
  if [[ -d "${d}/.git" ]]; then
    PLUGINS+=("$d")
  fi
done < <(find "$PLUGINS_DIR" -mindepth 1 -maxdepth 1 -type d -print0 | sort -z)

TOTAL=${#PLUGINS[@]}
echo "Verifying $TOTAL plugins in $PLUGINS_DIR ..."
echo "" > "$OUTPUT"  # truncate / create

PASS=0
FAIL=0
IDX=0
for plugin_dir in "${PLUGINS[@]}"; do
  IDX=$(( IDX + 1 ))
  name=$(basename "$plugin_dir")
  printf "[%3d/%d] %-40s " "$IDX" "$TOTAL" "$name"
  if python3 "$VERIFY" "$plugin_dir" >> "$OUTPUT"; then
    echo "OK"
    PASS=$(( PASS + 1 ))
  else
    COUNT=$(grep -c "\"plugin\":\"${name}\"" "$OUTPUT" 2>/dev/null || true)
    echo "ISSUES ($COUNT)"
    FAIL=$(( FAIL + 1 ))
  fi
done

# Summary line
ISSUE_COUNT=$(wc -l < "$OUTPUT" | tr -d ' ')
echo ""
echo "Done. $PASS/$TOTAL plugins clean, $FAIL with issues."
echo "Total issues: $ISSUE_COUNT"
echo "Results: $OUTPUT"
