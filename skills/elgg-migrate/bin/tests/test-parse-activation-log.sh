#!/usr/bin/env bash
# Unit tests for parse_activation_log in elgg-migrate-run.
#
# The activation log is what elgg-install.sh writes when activating each
# plugin in mod/.plugin-order.txt. Format (from infra/elgg7/elgg-install.sh):
#   "  + <id>"          for a plugin that activated cleanly
#   "  - <id>: <err>"   for one that threw at activate() time
# The parser must:
#   1. Return 0 when "  + <plugin>" is present.
#   2. Return 1 and print the error message when "  - <plugin>: ..." is present.
#   3. Return 2 when the plugin id never appears in activation output.
#   4. Not match a substring or prefix (e.g. "hype" should not match "hypeseo").
#
# Run: ./test-parse-activation-log.sh
set -uo pipefail

THIS_DIR="$(cd "$(dirname "$0")" && pwd)"
RUN_SCRIPT="$THIS_DIR/../elgg-migrate-run"

# shellcheck source=../elgg-migrate-run
# Sourcing only loads function definitions; the BASH_SOURCE guard at the
# bottom of elgg-migrate-run skips main() when not executed directly.
source "$RUN_SCRIPT"

# Sourcing inherits `set -euo pipefail` from the run script. The parser
# legitimately returns non-zero (1 = failed, 2 = not-attempted), so disable
# errexit in the test harness — we capture rc into a variable instead.
set +e

PASS=0
FAIL=0

assert_eq() {
  local name="$1" expected="$2" actual="$3"
  if [[ "$expected" == "$actual" ]]; then
    printf "  \033[32m✓\033[0m  %s\n" "$name"
    PASS=$(( PASS + 1 ))
  else
    printf "  \033[31m✗\033[0m  %s\n      expected: %s\n      actual:   %s\n" "$name" "$expected" "$actual"
    FAIL=$(( FAIL + 1 ))
  fi
}

# Fixtures match the output of `docker compose logs --no-log-prefix` — the
# fleet_check_activation wrapper passes --no-log-prefix so the parser sees the
# install-script output without the "<service>-<idx>  | " column.

# --- case 1: plugin activated cleanly ---------------------------------------
LOG_OK=$(cat <<'EOF'
Activating plugins...
  + members
  + hypefolders
  + hypewall
3 plugin(s) activated.
EOF
)
out=$(parse_activation_log "hypefolders" <<< "$LOG_OK")
rc=$?
assert_eq "activated: exit 0" "0" "$rc"
assert_eq "activated: no stdout" "" "$out"

# --- case 2: plugin activation failed ---------------------------------------
LOG_FAIL=$(cat <<'EOF'
Activating plugins...
  + members
  + hypewall
2 plugin(s) activated.
1 plugin(s) failed:
  - hypefolders: Declaration of MainFolder::save() must be compatible with ElggEntity::save(): bool
EOF
)
out=$(parse_activation_log "hypefolders" <<< "$LOG_FAIL")
rc=$?
assert_eq "failed: exit 1" "1" "$rc"
assert_eq "failed: captures error message" \
  "Declaration of MainFolder::save() must be compatible with ElggEntity::save(): bool" \
  "$out"

# --- case 3: plugin not in activation log -----------------------------------
LOG_MISS=$(cat <<'EOF'
Activating plugins...
  + members
  + hypewall
2 plugin(s) activated.
EOF
)
out=$(parse_activation_log "hypefolders" <<< "$LOG_MISS")
rc=$?
assert_eq "not_attempted: exit 2" "2" "$rc"
assert_eq "not_attempted: no stdout" "" "$out"

# --- case 4: prefix must not match (hype !~ hypeseo) ------------------------
LOG_PREFIX=$(cat <<'EOF'
  + hypeseo
1 plugin(s) activated.
EOF
)
out=$(parse_activation_log "hype" <<< "$LOG_PREFIX")
rc=$?
assert_eq "prefix non-match: exit 2" "2" "$rc"

# --- case 5: plugin id with hyphens / underscores ---------------------------
LOG_DASH=$(cat <<'EOF'
  + community_spam_tools
  - my-plugin: Class not found
EOF
)
out=$(parse_activation_log "community_spam_tools" <<< "$LOG_DASH")
rc=$?
assert_eq "underscored id: exit 0" "0" "$rc"

out=$(parse_activation_log "my-plugin" <<< "$LOG_DASH")
rc=$?
assert_eq "hyphenated id: exit 1" "1" "$rc"
assert_eq "hyphenated id: captures error" "Class not found" "$out"

# --- case 6: leading whitespace variants ------------------------------------
# elgg-install.sh writes two leading spaces but a future change might use
# tabs or none; the parser uses [[:space:]]* which should tolerate both.
LOG_TABS=$(printf "\t+ tabbed_plugin\n%s\n" "+ no_indent_plugin")
out=$(parse_activation_log "tabbed_plugin" <<< "$LOG_TABS")
rc=$?
assert_eq "tab-indented activation line" "0" "$rc"
out=$(parse_activation_log "no_indent_plugin" <<< "$LOG_TABS")
rc=$?
assert_eq "no-indent activation line" "0" "$rc"

# --- summary ----------------------------------------------------------------
echo ""
printf "  \033[32m%d PASS\033[0m  \033[31m%d FAIL\033[0m\n" "$PASS" "$FAIL"
(( FAIL == 0 ))
