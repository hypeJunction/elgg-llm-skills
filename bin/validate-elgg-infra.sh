#!/usr/bin/env bash
# validate-elgg-infra.sh — bring up each bundled Elgg infra, verify it
# installs, and tear it down. One version at a time to avoid port/DB
# collisions. Reports PASS/FAIL per version with the failure reason.
#
# Usage:
#   bin/validate-elgg-infra.sh              # validate all versions 2..7
#   bin/validate-elgg-infra.sh 4            # validate just elgg4
#   bin/validate-elgg-infra.sh 2 5 6        # validate a subset
#
# What counts as PASS:
#   1. Image builds.
#   2. `docker compose up -d` returns without error.
#   3. Within the install timeout, the elgg container creates
#      /var/www/html/.elgg-installed (set by elgg-install.sh on
#      successful ElggInstaller::batchInstall).
#   4. HTTP GET / on the mapped host port returns 200 or a redirect
#      (Elgg typically 302s to /action/... or /login for unauth).
set -uo pipefail

ROOT="$(cd "$(dirname "$0")/.." && pwd)"
INFRA="$ROOT/skills/elgg-migrate/infra"
INSTALL_TIMEOUT="${INSTALL_TIMEOUT:-420}"   # 7 min for build + install
LOG_DIR="$ROOT/tmp/validate-elgg-infra"
mkdir -p "$LOG_DIR"

VERSIONS=("$@")
if [[ ${#VERSIONS[@]} -eq 0 ]]; then
  VERSIONS=(2 3 4 5 6 7)
fi

# Map each version to a unique project name so parallel runs don't
# trample each other; still sequential within a single run.
project_name() { echo "elggv$1-validate"; }

teardown() {
  local ver="$1"
  local dir="$INFRA/elgg${ver}"
  [[ -d "$dir" ]] || return 0
  ( cd "$dir" && docker compose -p "$(project_name "$ver")" down -v --remove-orphans ) >/dev/null 2>&1 || true
}

# Poll the elgg container for the install-complete sentinel file.
wait_for_install() {
  local ver="$1" deadline cid
  deadline=$(( SECONDS + INSTALL_TIMEOUT ))
  while (( SECONDS < deadline )); do
    cid="$(docker compose -p "$(project_name "$ver")" ps -q elgg 2>/dev/null || true)"
    if [[ -n "$cid" ]]; then
      if docker exec "$cid" test -f /var/www/html/.elgg-installed 2>/dev/null; then
        return 0
      fi
      # Also bail early if container has exited/errored
      local state
      state="$(docker inspect -f '{{.State.Status}}' "$cid" 2>/dev/null || echo gone)"
      if [[ "$state" == "exited" || "$state" == "dead" ]]; then
        return 2
      fi
    fi
    sleep 3
  done
  return 1
}

# Poll the host port for an HTTP response that looks like a live Elgg.
wait_for_http() {
  local ver="$1" port="$2" deadline code
  deadline=$(( SECONDS + 60 ))
  while (( SECONDS < deadline )); do
    code="$(curl -sS -o /dev/null -w '%{http_code}' "http://localhost:${port}/" 2>/dev/null || echo 000)"
    if [[ "$code" =~ ^(200|301|302|303|307|308)$ ]]; then
      echo "$code"
      return 0
    fi
    sleep 2
  done
  echo "$code"
  return 1
}

# Discover the host port the compose file binds elgg:80 to.
discover_port() {
  local ver="$1" file="$INFRA/elgg${ver}/docker-compose.yml"
  awk '/ELGG_PORT:-/ { match($0, /ELGG_PORT:-[0-9]+/); if (RSTART) { print substr($0, RSTART+11, RLENGTH-11); exit } }' "$file"
}

validate_one() {
  local ver="$1"
  local dir="$INFRA/elgg${ver}"
  local log="$LOG_DIR/elgg${ver}.log"
  : > "$log"

  if [[ ! -d "$dir" ]]; then
    echo "SKIP elgg${ver} (no infra dir at $dir)"
    return 0
  fi
  teardown "$ver"

  # Fresh build each time to catch Dockerfile drift.
  # PLUGINS_DIR is only used by the `node` test-profile service, which
  # we never start here — but compose still interpolates all services
  # at parse time, so export a harmless placeholder.
  #
  # ELGG_PORT and DB_PORT are overridden into a high range to avoid
  # colliding with the user's dev containers (which typically use the
  # defaults 8380/8480/3307 etc.). Each version gets its own slot.
  export PLUGINS_DIR="${PLUGINS_DIR:-/tmp}"
  export ELGG_PORT="$((19000 + ver * 10))"
  export DB_PORT="$((19100 + ver * 10))"
  local port="$ELGG_PORT"
  echo "=== elgg${ver} (host port ${port}) ==="
  if ! ( cd "$dir" && docker compose -p "$(project_name "$ver")" up -d --build elgg db ) >>"$log" 2>&1; then
    echo "FAIL elgg${ver} — compose up failed; see $log"
    teardown "$ver"
    return 1
  fi

  local install_rc
  wait_for_install "$ver"
  install_rc=$?
  if (( install_rc == 1 )); then
    echo "FAIL elgg${ver} — install timeout after ${INSTALL_TIMEOUT}s; see $log"
    docker compose -p "$(project_name "$ver")" logs --tail 120 elgg >>"$log" 2>&1 || true
    teardown "$ver"
    return 1
  fi
  if (( install_rc == 2 )); then
    echo "FAIL elgg${ver} — container exited before install completed; see $log"
    docker compose -p "$(project_name "$ver")" logs --tail 120 elgg >>"$log" 2>&1 || true
    teardown "$ver"
    return 1
  fi

  local http_code
  http_code="$(wait_for_http "$ver" "$port")" || {
    echo "FAIL elgg${ver} — HTTP check failed (last code: ${http_code}); see $log"
    docker compose -p "$(project_name "$ver")" logs --tail 60 elgg >>"$log" 2>&1 || true
    teardown "$ver"
    return 1
  }

  echo "PASS elgg${ver} — installed, HTTP ${http_code} on :${port}"
  teardown "$ver"
  return 0
}

declare -A RESULTS
rc_overall=0
for ver in "${VERSIONS[@]}"; do
  if validate_one "$ver"; then
    RESULTS[$ver]=PASS
  else
    RESULTS[$ver]=FAIL
    rc_overall=1
  fi
done

echo
echo "=== summary ==="
for ver in "${VERSIONS[@]}"; do
  printf '  elgg%s : %s\n' "$ver" "${RESULTS[$ver]:-?}"
done
echo "logs: $LOG_DIR"
exit $rc_overall
