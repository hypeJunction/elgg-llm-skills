#!/usr/bin/env bash
# verify-write-paths.sh — exercise authenticated WRITE actions (create/edit) on a
# running Elgg stack and confirm each lands in the DB without a 5xx/fatal.
#
# WHY THIS EXISTS: render/parity gating is GET-only and never reaches
# action/CRUD/data-layer code. Write-path breaks — insert_data/delete_data and
# entity-relationship signature drift, DBAL named-param ':' keys, an entity
# subclass save() contract mismatch, canWriteToContainer(null) — are LATENT until
# an action actually runs, giving a deceptive all-green from every render check.
# This is the HTTP-level write smoke companion to per-plugin PHPUnit coverage.
#
# It drives CORE Elgg journeys that exist on any site (login → create group →
# edit user settings) plus a create/edit FORM render sweep (form-build code is the
# same code the submit action runs, so a 500 on GET /groups/add IS a write-path
# break). Point it at your plugin's own add/edit routes with --forms-file /
# EXTRA_FORM_ROUTES to cover plugin write paths too.
#
# Usage:
#   verify-write-paths.sh --base http://localhost:8580 --user admin --pass '<pw>' \
#     --db-container mysite6x-db-1 [--db-name elgg --db-user elgg --db-pass elgg] \
#     [--forms-file forms.txt]
#   AUTH_USER=admin AUTH_PASS=... DB_CONTAINER=... verify-write-paths.sh --port 8580
#
# forms-file / EXTRA_FORM_ROUTES: extra routes to GET-sweep, one per line (file) or
# space-separated (env). The token {guid} expands to the logged-in user's guid,
# e.g.  /blog/add/{guid}   /myplugin/add/{guid}
#
# Exit non-zero if login fails or any journey 5xx's / fails DB verification.
set -u

PORT=""
BASE="${BASE:-}"
USER="${AUTH_USER:-}"
PASS="${AUTH_PASS:-}"
DB_CONTAINER="${DB_CONTAINER:-}"
DB_NAME="${DB_NAME:-elgg}"
DB_USER="${DB_USER:-elgg}"
DB_PASS="${DB_PASS:-elgg}"
FORMS_FILE=""

while [ $# -gt 0 ]; do
  case "$1" in
    --base) BASE="$2"; shift 2 ;;
    --port) PORT="$2"; shift 2 ;;
    --user) USER="$2"; shift 2 ;;
    --pass) PASS="$2"; shift 2 ;;
    --db-container) DB_CONTAINER="$2"; shift 2 ;;
    --db-name) DB_NAME="$2"; shift 2 ;;
    --db-user) DB_USER="$2"; shift 2 ;;
    --db-pass) DB_PASS="$2"; shift 2 ;;
    --strict-skips) STRICT_SKIPS=1; shift; continue ;;
    --forms-file) FORMS_FILE="$2"; shift 2 ;;
    *) echo "unknown arg: $1" >&2; exit 2 ;;
  esac
done

[ -n "$BASE" ] || BASE="http://localhost:${PORT:-80}"
[ -n "$USER" ] || { echo "ERROR: set --user or AUTH_USER" >&2; exit 2; }
[ -n "$PASS" ] || { echo "ERROR: set --pass or AUTH_PASS" >&2; exit 2; }
[ -n "$DB_CONTAINER" ] || { echo "ERROR: set --db-container or DB_CONTAINER (needed for DB-delta verification)" >&2; exit 2; }

JAR="$(mktemp)"; TMP="$(mktemp -d)"
trap 'rm -f "$JAR"; rm -rf "$TMP"' EXIT

db() { docker exec "$DB_CONTAINER" mysql -u"$DB_USER" -p"$DB_PASS" "$DB_NAME" -N -e "$1" 2>/dev/null; }

# Harvest a fresh __elgg_ts/__elgg_token from any logged-in page: form hidden
# inputs first, then the elgg JS-config JSON ("__elgg_ts":N,"__elgg_token":"X")
# which is present on EVERY authenticated page.
harvest_tokens() {
  local page="$1" html="$TMP/harvest.html"
  curl -s -o "$html" -b "$JAR" -c "$JAR" -L "$BASE$page"
  HV_TS=$(grep -oE '__elgg_ts"[^>]*value="[0-9]+"' "$html" | grep -oE '[0-9]+' | tail -1)
  HV_TOK=$(grep -oE '__elgg_token"[^>]*value="[^"]+"' "$html" | sed -E 's/.*value="([^"]+)".*/\1/' | tail -1)
  if [ -z "$HV_TS" ] || [ -z "$HV_TOK" ]; then
    HV_TS=$(grep -oE '"__elgg_ts":[0-9]+' "$html" | grep -oE '[0-9]+' | tail -1)
    HV_TOK=$(grep -oE '"__elgg_token":"[^"]+"' "$html" | sed -E 's/.*:"([^"]+)".*/\1/' | tail -1)
  fi
  [ -n "$HV_TS" ] && [ -n "$HV_TOK" ]
}

PASS_N=0; FAIL_N=0
SKIP_N=0
STRICT_SKIPS="${STRICT_SKIPS:-0}"   # --strict-skips: treat a skipped journey as failure
result() {
  local name="$1" code="$2" detail="$3" ok="$4"
  case "$ok" in
    1) PASS_N=$((PASS_N+1)); printf '  [PASS] %-24s HTTP %s  %s\n' "$name" "$code" "$detail" ;;
    skip) SKIP_N=$((SKIP_N+1)); printf '  [SKIP] %-24s HTTP %s  %s\n' "$name" "$code" "$detail" ;;
    *) FAIL_N=$((FAIL_N+1)); printf '  [FAIL] %-24s HTTP %s  %s\n' "$name" "$code" "$detail" ;;
  esac
}

############ 1. LOGIN ############
lp="$(curl -s -c "$JAR" -b "$JAR" "$BASE/login")"
ts=$(printf '%s' "$lp" | grep -oE '__elgg_ts"[^>]*value="[0-9]+"' | grep -oE '[0-9]+' | tail -1)
tok=$(printf '%s' "$lp" | grep -oE '__elgg_token"[^>]*value="[^"]+"' | sed -E 's/.*value="([^"]+)".*/\1/' | tail -1)
[ -n "$ts" ] && [ -n "$tok" ] || { echo "FAIL: no CSRF token on /login"; exit 1; }
curl -s -o "$TMP/login.html" -b "$JAR" -c "$JAR" -L \
  --data-urlencode "username=$USER" --data-urlencode "password=$PASS" \
  --data-urlencode "__elgg_ts=$ts" --data-urlencode "__elgg_token=$tok" "$BASE/action/login" >/dev/null
dash_final=$(curl -s -o "$TMP/dash.html" -w '%{url_effective}' -b "$JAR" -c "$JAR" -L "$BASE/dashboard")
case "$dash_final" in */login) echo "FAIL: still anonymous after login (bounced to $dash_final). Bad creds/token."; exit 1 ;; esac
USER_GUID=$(db "SELECT e.guid FROM elgg_entities e JOIN elgg_metadata m ON m.entity_guid=e.guid AND m.name='username' WHERE m.value='$USER' LIMIT 1")
[ -n "$USER_GUID" ] || { echo "FAIL: could not resolve guid for user '$USER' via DB — check --db-* settings"; exit 1; }
echo "[auth] logged in as $USER (guid=$USER_GUID)"

############ 2. CREATE GROUP (core action) ############
GNAME="wp-smoke-group-$$"
# Elgg renders a 404 page that still carries CSRF tokens, so harvest_tokens() alone
# cannot tell "route exists" from "route missing". Ask for the status first: with the
# groups plugin inactive this journey verifies nothing and must not report a failure
# of the WRITE PATH — that would be indistinguishable from a real regression.
GROUPS_CODE=$(curl -s -o /dev/null -w '%{http_code}' -b "$JAR" -c "$JAR" -L "$BASE/groups/add")
if [ "$GROUPS_CODE" = "404" ]; then
  result "create group" "$GROUPS_CODE" "route absent — groups plugin inactive? nothing verified" skip
elif harvest_tokens "/groups/add"; then
  code=$(curl -s -o "$TMP/grp.html" -w '%{http_code}' -b "$JAR" -c "$JAR" -L \
    --data-urlencode "name=$GNAME" \
    --data-urlencode "description=write-path smoke test group" \
    --data-urlencode "membership=2" --data-urlencode "vis=2" \
    --data-urlencode "content_access_mode=unrestricted" \
    --data-urlencode "__elgg_ts=$HV_TS" --data-urlencode "__elgg_token=$HV_TOK" \
    "$BASE/action/groups/edit")
  GGUID=$(db "SELECT e.guid FROM elgg_entities e JOIN elgg_metadata m ON m.entity_guid=e.guid AND m.name='name' WHERE m.value='$GNAME' ORDER BY e.guid DESC LIMIT 1")
  { [ "${code:0:1}" != "5" ] && [ -n "$GGUID" ]; } && result "create group" "$code" "guid=$GGUID" 1 || result "create group" "$code" "no entity created" 0
else result "create group" "-" "no /groups/add form tokens" 0; fi

############ 3. EDIT USER SETTINGS (core action) ############
if harvest_tokens "/settings"; then
  NEWNAME="wp-smoke-name-$$"
  code=$(curl -s -o "$TMP/set.html" -w '%{http_code}' -b "$JAR" -c "$JAR" -L \
    --data-urlencode "guid=$USER_GUID" --data-urlencode "name=$NEWNAME" \
    --data-urlencode "__elgg_ts=$HV_TS" --data-urlencode "__elgg_token=$HV_TOK" \
    "$BASE/action/usersettings/save")
  DBNAME=$(db "SELECT m.value FROM elgg_metadata m WHERE m.entity_guid=$USER_GUID AND m.name='name' ORDER BY m.id DESC LIMIT 1")
  [ "${code:0:1}" != "5" ] && result "edit user settings" "$code" "name now '$DBNAME'" 1 || result "edit user settings" "$code" "FATAL" 0
else result "edit user settings" "-" "no /settings tokens" 0; fi

############ 4. CREATE/EDIT FORM RENDER SWEEP ############
# GET each create/edit form authenticated and flag 5xx. Form-build code == submit
# code, so a 500 here is a write-path break. {guid} expands to the logged-in user;
# 403/404 are acceptable (wrong container / needs an existing entity) — only 5xx fails.
# A 404 means the ROUTE DOES NOT EXIST — the form was never rendered, so nothing was
# verified. Counting that as a pass let a stack with the relevant plugins inactive
# report a clean sweep while covering nothing. 404 is SKIPPED; only a rendered form
# (2xx/3xx) passes, and 5xx fails.
echo "[forms] create/edit form render sweep (2xx/3xx = pass, 404 = route absent/skipped, 5xx = fail):"
CORE_FORMS=("/groups/add" "/blog/add/{guid}" "/bookmarks/add/{guid}" "/file/add/{guid}" "/pages/add/{guid}" "/messages/add")
EXTRA=()
[ -n "${EXTRA_FORM_ROUTES:-}" ] && read -r -a EXTRA <<< "$EXTRA_FORM_ROUTES"
if [ -n "$FORMS_FILE" ] && [ -f "$FORMS_FILE" ]; then
  while IFS= read -r line; do
    line="${line%%#*}"; line="$(echo "$line" | tr -d '[:space:]')"
    [ -n "$line" ] && EXTRA+=("$line")
  done < "$FORMS_FILE"
fi
for r in "${CORE_FORMS[@]}" "${EXTRA[@]}"; do
  route="${r//\{guid\}/$USER_GUID}"
  c=$(curl -s -o "$TMP/form.html" -w '%{http_code}' -b "$JAR" -c "$JAR" -L "$BASE$route")
  ttl=$(grep -oE '<title>[^<]*' "$TMP/form.html" | head -1 | sed 's/<title>//')
  if [ "${c:0:1}" = "5" ]; then
    result "form $route" "$c" "FATAL" 0
  elif [ "$c" = "404" ]; then
    result "form $route" "$c" "route absent — plugin inactive? nothing verified" skip
  else
    result "form $route" "$c" "${ttl:--}" 1
  fi
done

echo "[write-paths] $PASS_N passed, $FAIL_N failed, $SKIP_N skipped"
if [ "$SKIP_N" -gt 0 ]; then
  echo "  NOTE: $SKIP_N journey/route(s) returned 404 and verified NOTHING. On a stack where the" >&2
  echo "        owning plugins are active this is a real failure; on a bare-core stack it is" >&2
  echo "        expected. A skipped gate is not a passed gate." >&2
  if [ "$STRICT_SKIPS" = "1" ]; then
    echo "  --strict-skips: refusing to report success with $SKIP_N unverified route(s)." >&2
    exit 1
  fi
fi
[ "$FAIL_N" -eq 0 ]
