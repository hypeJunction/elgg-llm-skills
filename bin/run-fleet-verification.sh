#!/usr/bin/env bash
# run-fleet-verification.sh [plugins_dir] [output_file]
#
# Runs verify-plugin-branches.py on every plugin in the fleet and aggregates
# NDJSON results into a single file for review / beads filing.
#
# Defaults:
#   plugins_dir  = ~/Data/hypejunction/bodyology/plugins
#   output_file  = /tmp/fleet-verification-results.ndjson

set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "$0")" && pwd)"
VERIFY="${SCRIPT_DIR}/verify-plugin-branches.py"

PLUGINS_DIR="${1:-${HOME}/Data/hypejunction/bodyology/plugins}"
OUTPUT="${2:-/tmp/fleet-verification-results.ndjson}"

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
