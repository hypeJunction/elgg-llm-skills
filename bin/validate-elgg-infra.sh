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
# The per-plugin test stacks that scaffold-docker.sh actually SHIPS into plugins.
# These are validated statically below so they can't drift away from the booted
# infra/ tree unnoticed (the elgg6/7 templates once silently lost the core-plugin
# symlink + dep-ordered activation while this validator stayed green — bd efa6m).
TEMPLATES="$ROOT/skills/elgg-test-writer/templates"
INSTALL_TIMEOUT="${INSTALL_TIMEOUT:-420}"   # 7 min for build + install
LOG_DIR="$ROOT/tmp/validate-elgg-infra"
mkdir -p "$LOG_DIR"

TEMPLATES_ONLY=0
VERSIONS=()
for arg in "$@"; do
  case "$arg" in
    --templates-only) TEMPLATES_ONLY=1 ;;   # static template checks, no docker
    *) VERSIONS+=("$arg") ;;
  esac
done
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

# Static invariants the SHIPPED per-plugin templates must encode. These are the
# landmines that were each discovered painfully and must never silently regress
# out of the templates agents actually receive. No docker needed.
check_template_invariants() {
  local ver="$1"
  local script="$TEMPLATES/elgg${ver}/elgg-install.sh"
  [[ -f "$script" ]] || { echo "FAIL template elgg${ver} — missing $script"; return 1; }
  if ! bash -n "$script" 2>/dev/null; then
    echo "FAIL template elgg${ver} — elgg-install.sh has a bash syntax error"; return 1
  fi

  local -a missing=()
  # Every version: correct DB prefix (BaseTestCase default is c_i_elgg_, so a
  # stack that omits dbprefix=elgg_ silently runs phpunit against the wrong schema).
  grep -q "dbprefix" "$script" || missing+=("dbprefix")

  # Vendor-core era (4.x+): core plugins live in vendor/elgg/elgg/mod and must be
  # symlinked into mod/; transitive deps need dep-ordered activation via
  # setPriority('last'); and the PhpFastCache-root-owned-dirs landmine needs a
  # chown of the data root. (2.x/3.x predate this layout — intentionally exempt.)
  if [[ "$ver" -ge 4 ]]; then
    grep -q "vendor/elgg/elgg/mod" "$script" || missing+=("core-plugin symlink")
    grep -q "setPriority('last')" "$script" || missing+=("dep-ordered activation setPriority('last')")
    grep -q "chown.*www-data" "$script" || missing+=("chown www-data on data root")
  fi

  if [[ ${#missing[@]} -gt 0 ]]; then
    echo "FAIL template elgg${ver} — missing invariants: ${missing[*]}"
    return 1
  fi
  echo "PASS template elgg${ver} — landmine invariants present"
  return 0
}

# --- engine mirror drift gate (fast, no docker) -----------------------------
# gen-elgg-infra.sh mirrors the AST engine from skills/elgg-migrate into every
# sibling skill. If someone edits src/rules/references without re-running the
# generator, the siblings silently ship a stale engine: their bundled
# migrate.php --check and PostMigrationVerifier read data files that no longer
# match the rules, and whole-site upgrades skip rules the canonical skill has.
# That happened for 034-migrate-camelcase-plugin-settings + 035-strict-string-params
# (bd elgg-migrate-7jgbj). Fail loudly instead.
# Must match gen-elgg-infra.sh's SIBLING_SKILLS. elgg-js-test-writer is excluded:
# it ships no PHP engine.
SIBLING_SKILLS=(elgg-site-upgrade elgg-test-writer)
# Mirrored with --delete: must be byte-identical. references/ is merge-mirrored
# (siblings own extra files there), so it is checked one-way below.
MIRRORED_TREES=(src rules tests infra/migrate)
MIRRORED_FILES=(bin/migrate.php bin/migrate-plugin.sh bin/scan-frontend-residue.sh
                bin/check-release-lag.sh composer.json phpunit.xml)
# The reference data the mirrored engine loads at runtime.
ENGINE_REFS=(removed-functions.json removed-function-renames.json class-renames.json
             string-renames.json changed-class-contracts.json migration-failure-catalog.md)

check_engine_mirror() {
  local canon="$ROOT/skills/elgg-migrate" rc=0 s t f
  for s in "${SIBLING_SKILLS[@]}"; do
    local dst="$ROOT/skills/$s"
    for t in "${MIRRORED_TREES[@]}"; do
      if ! diff -rq "$canon/$t" "$dst/$t" >/dev/null 2>&1; then
        echo "  DRIFT: skills/$s/$t differs from canonical" >&2
        rc=1
      fi
    done
    for f in "${MIRRORED_FILES[@]}"; do
      if ! cmp -s "$canon/$f" "$dst/$f"; then
        echo "  DRIFT: skills/$s/$f differs from canonical" >&2
        rc=1
      fi
    done
    # references/: engine-consumed files must be present and identical; the
    # sibling may legitimately carry additional files of its own.
    for f in "${ENGINE_REFS[@]}"; do
      if ! cmp -s "$canon/references/$f" "$dst/references/$f"; then
        echo "  DRIFT: skills/$s/references/$f missing or stale" >&2
        rc=1
      fi
    done
  done
  if [[ $rc -ne 0 ]]; then
    echo "engine mirror: FAIL — run bin/gen-elgg-infra.sh and commit the result" >&2
  else
    echo "engine mirror: PASS (${#SIBLING_SKILLS[@]} siblings identical to canonical)"
  fi
  return $rc
}

declare -A RESULTS
declare -A TRESULTS
rc_overall=0

if ! check_engine_mirror; then
  rc_overall=1
fi

# Static template gate first (fast, no docker).
for ver in "${VERSIONS[@]}"; do
  if check_template_invariants "$ver"; then
    TRESULTS[$ver]=PASS
  else
    TRESULTS[$ver]=FAIL
    rc_overall=1
  fi
done

if [[ "$TEMPLATES_ONLY" -eq 1 ]]; then
  echo
  echo "=== template summary ==="
  for ver in "${VERSIONS[@]}"; do
    printf '  templates/elgg%s : %s\n' "$ver" "${TRESULTS[$ver]:-?}"
  done
  exit $rc_overall
fi

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
  printf '  elgg%s : infra=%s  templates=%s\n' "$ver" "${RESULTS[$ver]:-?}" "${TRESULTS[$ver]:-?}"
done
echo "logs: $LOG_DIR"
exit $rc_overall
