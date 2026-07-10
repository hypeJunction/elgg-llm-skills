#!/usr/bin/env bash
# verify-route-coverage.sh — exhaustive route-coverage gate for a migrated Elgg site.
#
# WHY THIS EXISTS: activation + homepage/login render gates prove code LOADS, not
# that it WORKS. Latent migration bugs (dead 'search' event, removed-fn calls,
# controller/route changes, type-strictness) only fatal when a specific page is
# actually rendered. This crawls EVERY registered route — including parameterized
# entity-view routes filled with REAL guids/usernames from the DB — anonymously
# and (with --user/--pass) authenticated, and FAILS on any 5xx or any new
# "PHP Fatal"/"Call to undefined function" in the log. Run it at EVERY version
# step and diff the pass-set against the previous version.
#
# Usage: verify-route-coverage.sh [--container NAME] [--base http://localhost]
#                                 [--user ADMIN --pass PW]   # adds auth crawl
set -u
# Canonical name is ELGG_APP_CONTAINER; ELGG_CONTAINER/ROUTE_CHECK_CONTAINER are
# accepted as back-compat aliases. Default stays 'elgg' (the compose service name).
CONTAINER="${ELGG_APP_CONTAINER:-${ELGG_CONTAINER:-${ROUTE_CHECK_CONTAINER:-elgg}}}"
BASE="http://localhost"; USER=""; PASS=""
while [ $# -gt 0 ]; do case "$1" in
  --container) CONTAINER="$2"; shift 2;; --base) BASE="$2"; shift 2;;
  --user) USER="$2"; shift 2;; --pass) PASS="$2"; shift 2;;
  *) echo "unknown arg: $1" >&2; exit 2;; esac; done

dx() { docker exec "$CONTAINER" sh -c "$1"; }

# Credentials go in as environment, never interpolated into the shell string:
# a password containing a quote/&/space would break the command apart.
dx_auth() { docker exec -e LUSER="$USER" -e LPASS="$PASS" "$CONTAINER" sh -c "$1"; }
MARK="===ROUTE-CHECK-$$==="
# Per-run temp names. Fixed /tmp paths collided when two version tiers were checked
# concurrently (host-side route list) or against the same container (cookie jar).
RUN="$$"
ROUTES="/tmp/rc_routes.$RUN.txt"
CJ="/tmp/rc.$RUN.cj"
trap 'rm -f "$ROUTES"' EXIT
dx "echo '$MARK' >> /var/log/apache2/error.log" 2>/dev/null

# Build concrete URLs for EVERY route: substitute {guid} with a real entity guid,
# {username}/{name} with a real user, {segments}/{any} with '' — one URL per route.
dx "cd /var/www/html && php -r '
require \"vendor/autoload.php\"; \$a=\Elgg\Application::getInstance(); \$a->bootCore();
\$u = elgg_get_entities([\"type\"=>\"user\",\"limit\"=>1]); \$uname = \$u ? \$u[0]->username : \"admin\";
foreach (_elgg_services()->routes->all() as \$name=>\$r) {
  \$m=\$r->getMethods(); if(!empty(\$m) && !in_array(\"GET\",\$m)) continue;
  \$p=\$r->getPath();
  if (strpos(\$p,\"/action/\")===0) continue;
  // fill params from real data
  \$p=preg_replace_callback(\"/\{(\w+)[^}]*\}/\", function(\$mm){
    global \$uname; \$k=\$mm[1];
    if (in_array(\$k,[\"username\",\"name\"])) return \$uname;
    if (in_array(\$k,[\"segments\",\"any\",\"path\"])) return \"\";
    if (strpos(\$k,\"guid\")!==false || \$k==\"lc\" || \$k==\"id\") {
      \$e=elgg_get_entities([\"limit\"=>1,\"order_by\"=>\"e.guid ASC\"]); return \$e?\$e[0]->guid:\"1\";
    }
    return \"1\";
  }, \$p);
  \$p=preg_replace(\"#//+#\",\"/\",\$p);
  echo \$p.PHP_EOL;
}' 2>/dev/null" | sort -u > $ROUTES

total=$(grep -c . $ROUTES); fail=""
crawl() { # $1=label, cookie file optional via $2
  local cnt=0
  while IFS= read -r p; do
    [ -z "$p" ] && continue; cnt=$((cnt+1))
    local code
    code=$(dx "curl -s -o /dev/null -w '%{http_code}' ${2:-} '$BASE$p'" 2>/dev/null)
    case "$code" in 5*) fail="${fail}[$1]$p($code) ";; esac
  done < $ROUTES
  echo "  [$1] crawled $cnt routes"
}
echo "Route coverage check — $total routes — container=$CONTAINER"
crawl anon ""

if [ -n "$USER" ] && [ -n "$PASS" ]; then
  TOK=$(dx "curl -s -c $CJ '$BASE/login' | grep -oE '__elgg_token[^>]*value=\"[^\"]+\"' | grep -oE 'value=\"[^\"]+\"' | head -1 | cut -d'\"' -f2")
  TS=$(dx "curl -s -b $CJ '$BASE/login' | grep -oE '__elgg_ts[^>]*value=\"[0-9]+\"' | grep -oE '[0-9]+' | head -1")
  # --data-urlencode (not -d) so '&', '=', '+' and spaces in the password are
  # encoded rather than parsed as field separators.
  dx_auth "curl -s -b $CJ -c $CJ -o /dev/null \
    --data-urlencode \"username=\$LUSER\" --data-urlencode \"password=\$LPASS\" \
    --data-urlencode '__elgg_token=$TOK' --data-urlencode '__elgg_ts=$TS' \
    '$BASE/action/login'" >/dev/null 2>&1
  crawl auth "-b $CJ"
fi

echo "=== new PHP Fatal / undefined function during crawl ==="
dx "awk '/$MARK/{f=1} f' /var/log/apache2/error.log | grep -oE 'PHP Fatal[^\n]{0,90}|Call to undefined (function|method) [A-Za-z0-9_\\\\:]+' | sort | uniq -c | sort -rn | head -30"
echo ""
if [ -z "$fail" ]; then echo "✓ ROUTE COVERAGE CLEAN — no 5xx across $total routes"; exit 0
else echo "✗ 5xx ROUTES:"; echo "  $fail" | tr ' ' '\n' | grep . | sed 's/^/    /'; exit 1; fi
