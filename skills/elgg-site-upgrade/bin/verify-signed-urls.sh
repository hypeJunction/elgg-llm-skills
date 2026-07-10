#!/usr/bin/env bash
# verify-signed-urls.sh — HTTP-level gate for Elgg's HMAC-signed URLs and CSRF.
#
# WHY THIS EXISTS: signed-URL endpoints are only reachable over HTTP. Nothing in a
# unit or integration suite exercises Elgg\Application::run()'s /serve-file/ branch,
# the HmacFactory, the expiry check, or the session-cookie binding — so a migration
# can silently turn every file download into a 403, or (far worse) stop verifying the
# MAC at all, and every render gate stays green. Same for CSRF: an action posted
# without __elgg_token must be refused by the real router + gatekeeper.
#
# It drives CORE only, so it runs against any Elgg site:
#
#   1. a public (cookie-independent) signed download URL returns 200 and the bytes
#   2. tampering with the MAC returns 403 "HMAC mismatch"
#   3. an expired signature returns 403 "URL has expired"
#   4. removing the MAC segment returns 400 (malformed), not 200
#   5. a cookie-BOUND URL fetched without the session cookie returns 403
#      (the MAC covers the cookie: a leaked URL must not be replayable)
#   6. POST /action/login without __elgg_token is refused by the CSRF gatekeeper
#
# The probe file is created and deleted inside the container; nothing is left behind.
#
# Usage:
#   ELGG_APP_CONTAINER=<app> verify-signed-urls.sh [--base http://localhost]
#
# Exit: 0 all gates pass, 1 a gate failed, 2 usage/setup.
set -uo pipefail

APP_CONTAINER="${ELGG_APP_CONTAINER:-}"
BASE="http://localhost"
while [ $# -gt 0 ]; do
  case "$1" in
    --base) BASE="${2:-}"; shift 2 ;;
    --container) APP_CONTAINER="${2:-}"; shift 2 ;;
    -h|--help) sed -n '2,28p' "$0" | sed 's/^# \{0,1\}//'; exit 0 ;;
    *) echo "unknown arg: $1" >&2; exit 2 ;;
  esac
done

[ -n "$APP_CONTAINER" ] || { echo "ERROR: set ELGG_APP_CONTAINER (or --container)" >&2; exit 2; }
docker exec "$APP_CONTAINER" true 2>/dev/null || { echo "ERROR: container '$APP_CONTAINER' not running" >&2; exit 2; }

PASS_N=0; FAIL_N=0
ok()  { PASS_N=$((PASS_N+1)); printf '  [PASS] %-42s %s\n' "$1" "${2:-}"; }
bad() { FAIL_N=$((FAIL_N+1)); printf '  [FAIL] %-42s %s\n' "$1" "${2:-}"; }

in_container() { docker exec "$APP_CONTAINER" "$@"; }
http_code() { in_container curl -s -o /dev/null -w '%{http_code}' "$BASE$1" 2>/dev/null; }
http_body() { in_container curl -s "$BASE$1" 2>/dev/null; }

# --- create a throwaway file and mint its URLs -------------------------------
read -r GUID URL_PUBLIC URL_COOKIE <<EOF
$(in_container php -r '
require "/var/www/html/vendor/autoload.php";
$a = \Elgg\Application::getInstance(); $a->bootCore();
$users = elgg_get_entities(["type" => "user", "limit" => 1]);
if (!$users) { fwrite(STDERR, "no user on this site\n"); exit(1); }
$u = $users[0];
_elgg_services()->session_manager->setLoggedInUser($u);

$f = new \ElggFile();
$f->owner_guid = $u->guid;
$f->container_guid = $u->guid;
$f->access_id = ACCESS_PUBLIC;
$f->setFilename("verify-signed-urls-probe.txt");
$f->save();
$f->open("write"); $f->write("hello signed world"); $f->close();

// use_cookie=false -> the MAC does not cover the session cookie (public link)
// use_cookie=true  -> the MAC covers it; the URL must not work without the cookie
echo $f->guid, " ", elgg_get_download_url($f, false), " ", elgg_get_download_url($f, true), "\n";
' 2>/dev/null)
EOF

# The probe file must never outlive the run. `docker exec` does NOT inherit the host's
# environment, so the guid is passed with -e (an earlier version read getenv() inside
# the container, found nothing, and silently leaked a file on every run).
cleanup() {
  [ -n "${GUID:-}" ] || return 0
  docker exec -e PROBE_GUID="$GUID" "$APP_CONTAINER" php -r '
    require "/var/www/html/vendor/autoload.php";
    $a = \Elgg\Application::getInstance(); $a->bootCore();
    elgg_call(ELGG_IGNORE_ACCESS, function () {
      $e = get_entity((int) getenv("PROBE_GUID"));
      if ($e) { $e->delete(true); }
    });
  ' >/dev/null 2>&1 || true
}
trap cleanup EXIT

if [ -z "${GUID:-}" ] || [ -z "${URL_PUBLIC:-}" ]; then
  echo "ERROR: could not create the probe file / mint signed URLs in $APP_CONTAINER" >&2
  exit 2
fi

# Strip the scheme+host: everything is fetched from inside the container.
path_of() { printf '%s' "${1#http*://*/}" | sed 's|^|/|'; }
P_PUBLIC="$(path_of "$URL_PUBLIC")"
P_COOKIE="$(path_of "$URL_COOKIE")"

# The MAC is the 5th path segment after serve-file/: e../l../d./c./<MAC>/<path>
mac_of() { printf '%s' "$1" | sed -nE 's|.*/c[01]/([A-Za-z0-9_-]+)/.*|\1|p'; }
MAC="$(mac_of "$P_PUBLIC")"
[ -n "$MAC" ] || { echo "ERROR: could not locate the MAC segment in $P_PUBLIC" >&2; exit 2; }

# Flip the MAC's first character to something different but still in the alphabet.
first="${MAC:0:1}"
if [ "$first" = "A" ]; then repl="B"; else repl="A"; fi
P_TAMPERED="${P_PUBLIC/\/$MAC\//\/$repl${MAC:1}\/}"
P_STRIPPED="${P_PUBLIC/\/$MAC\//\/}"
P_EXPIRED="$(printf '%s' "$P_PUBLIC" | sed -E 's|/e[0-9]+/|/e1000000000/|')"

echo "[signed-urls] probe file guid=$GUID"

# 1. the valid, cookie-independent URL serves the bytes
code="$(http_code "$P_PUBLIC")"
body="$(http_body "$P_PUBLIC")"
if [ "$code" = "200" ] && [ "$body" = "hello signed world" ]; then
  ok "public signed URL serves content" "HTTP $code"
else
  bad "public signed URL serves content" "HTTP $code body='${body:0:40}'"
fi

# 2. a tampered MAC must be refused
code="$(http_code "$P_TAMPERED")"
[ "$code" = "403" ] && ok "tampered MAC refused" "HTTP $code" \
                    || bad "tampered MAC refused" "HTTP $code (expected 403)"

# 3. an expired signature must be refused
code="$(http_code "$P_EXPIRED")"
[ "$code" = "403" ] && ok "expired signature refused" "HTTP $code" \
                    || bad "expired signature refused" "HTTP $code (expected 403)"

# 4. a missing MAC must not fall through to the file
code="$(http_code "$P_STRIPPED")"
case "$code" in
  400|403) ok "MAC-less URL refused" "HTTP $code" ;;
  *)       bad "MAC-less URL refused" "HTTP $code (expected 400/403)" ;;
esac

# 5. a cookie-bound URL must not be replayable without the session cookie
code="$(http_code "$P_COOKIE")"
[ "$code" = "403" ] && ok "cookie-bound URL not replayable" "HTTP $code" \
                    || bad "cookie-bound URL not replayable" "HTTP $code (expected 403)"

# 6. CSRF: an action posted without __elgg_token must be refused by the gatekeeper
code="$(in_container curl -s -o /dev/null -w '%{http_code}' \
        --data-urlencode 'username=admin' --data-urlencode 'password=wrong' \
        "$BASE/action/login" 2>/dev/null)"
body="$(in_container curl -s -L --data-urlencode 'username=admin' --data-urlencode 'password=wrong' \
        "$BASE/action/login" 2>/dev/null | grep -ioE 'form is missing|token|csrf' | head -1)"
if [ "$code" != "200" ] || [ -n "$body" ]; then
  ok "action without CSRF token refused" "HTTP $code ${body:+($body)}"
else
  bad "action without CSRF token refused" "HTTP $code — the gatekeeper let it through"
fi

echo "[signed-urls] $PASS_N passed, $FAIL_N failed"
[ "$FAIL_N" -eq 0 ]
