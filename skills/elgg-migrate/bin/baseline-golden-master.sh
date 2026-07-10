#!/usr/bin/env bash
# baseline-golden-master.sh — site-wide route-render GOLDEN MASTER harness.
#
# WHY THIS EXISTS: the unit tests under tests/ exercise entity CRUD and pass even
# while VIEWS/ROUTES fatal — so there was NO regression net for the render layer,
# and every migration break was found by reactive crawling. This builds that net:
# it enumerates EVERY registered GET route from the live container, fills
# {guid}/{username}/{segments} with REAL values, crawls each route anonymously AND
# authenticated, and records the HTTP status + owning plugin for each. The snapshot
# is a versioned golden file; `diff` between two versions reports routes whose
# status REGRESSED (2xx/3xx -> 5xx) and attributes each regression to its plugin.
#
# The ORACLE for what "should" work is the snapshot of the previous (passing)
# tier — capture one per version step and diff forward.
#
# Usage:
#   baseline-golden-master.sh capture <label> [--container N] [--base URL]
#                                             [--user U --pass P]
#   baseline-golden-master.sh diff <fileA> <fileB>
#
# Env: ELGG_APP_CONTAINER (aliases: ELGG_CONTAINER, ROUTE_CHECK_CONTAINER),
#      GM_USER, GM_PASS, GM_BASE.
#
# TSV line format (3 tab-separated columns, sorted, stable):
#   <context> <method> <path> \t <owning_plugin> \t <http_status>
# where <context> is "anon" or "auth"; embedding the context in column 1 keeps
# anon and auth as distinct, diffable keys while honoring the 3-column contract.
set -u

SELF_DIR="$(cd "$(dirname "$0")" && pwd)"
REPO_ROOT="$(cd "$SELF_DIR/.." && pwd)"
# Where versioned golden files live. Override with GM_BASELINE_DIR so a site
# upgrade can keep baselines in the SITE repo instead of the skill's tree.
BASELINE_DIR="${GM_BASELINE_DIR:-$REPO_ROOT/baselines}"

# Canonical name is ELGG_APP_CONTAINER; ELGG_CONTAINER/ROUTE_CHECK_CONTAINER are
# accepted as back-compat aliases. Default stays 'elgg' (the compose service name).
CONTAINER="${ELGG_APP_CONTAINER:-${ELGG_CONTAINER:-${ROUTE_CHECK_CONTAINER:-elgg}}}"
BASE="${GM_BASE:-http://localhost}"
USER="${GM_USER:-}"
PASS="${GM_PASS:-}"

dx() { docker exec "$CONTAINER" sh -c "$1"; }

# Same, but hands the login credentials to the container as ENVIRONMENT rather
# than splicing them into the shell string. A password containing a quote, an
# ampersand or a space would otherwise break the command apart (or worse).
# Inside the script body they are read as "$LUSER" / "$LPASS".
dx_auth() { docker exec -e LUSER="$USER" -e LPASS="$PASS" "$CONTAINER" sh -c "$1"; }

# ---------------------------------------------------------------------------
# diff mode: report status regressions (A passing -> B 5xx), attributed to plugin
# ---------------------------------------------------------------------------
do_diff() {
  local A="$1" B="$2"
  [ -f "$A" ] || { echo "diff: missing file: $A" >&2; exit 2; }
  [ -f "$B" ] || { echo "diff: missing file: $B" >&2; exit 2; }
  # join on column 1 (context+method+path). Emit regressions where A was 2xx/3xx
  # and B is 5xx (or B unreachable=000). Also surface NEW 5xx not present in A.
  awk -F'\t' '
    function passing(c){ return (c ~ /^[23]/) }
    function broken(c){ return (c ~ /^5/ || c=="000") }
    FNR==NR { plugA[$1]=$2; codeA[$1]=$3; next }
    {
      key=$1; plugB=$2; codeB=$3
      seen[key]=1
      if (key in codeA) {
        if (passing(codeA[key]) && broken(codeB)) {
          printf "REGRESSED\t%s\t%s\t%s -> %s\n", plugB, key, codeA[key], codeB
          regr++
        }
      } else if (broken(codeB)) {
        printf "NEW-5XX\t%s\t%s\t(absent) -> %s\n", plugB, key, codeB
        nw++
      }
    }
    END {
      # routes that vanished from B entirely
      printf "" > "/dev/stderr"
      print ""
      print "regressions: " (regr+0) "   new-5xx: " (nw+0)
    }
  ' "$A" "$B" | {
    # group output: regressions first, sorted by plugin
    grep -E '^(REGRESSED|NEW-5XX)' | sort -t$'\t' -k1,1 -k2,2
    echo ""
    grep -E '^regressions:' || true
  }
  # exit non-zero if any regression/new-5xx
  if join_count="$(awk -F'\t' '
      function passing(c){ return (c ~ /^[23]/) }
      function broken(c){ return (c ~ /^5/ || c=="000") }
      FNR==NR { codeA[$1]=$3; next }
      { if (($1 in codeA) && passing(codeA[$1]) && broken($3)) n++;
        else if (!($1 in codeA) && broken($3)) n++ }
      END { print n+0 }' "$A" "$B")"; [ "$join_count" -gt 0 ] 2>/dev/null; then
    return 1
  fi
  return 0
}

# ---------------------------------------------------------------------------
# capture mode
# ---------------------------------------------------------------------------
do_capture() {
  local LABEL="$1"
  [ -n "$LABEL" ] || { echo "capture: missing <label>" >&2; exit 2; }
  mkdir -p "$BASELINE_DIR"
  local OUT="$BASELINE_DIR/golden-routes-$LABEL.tsv"

  echo "Golden master capture — label=$LABEL container=$CONTAINER base=$BASE" >&2

  # 1. Enumerate every GET route, fill params with REAL values, attribute plugin.
  #    Emit: <method>\t<path>\t<plugin>   (one line per route) to /tmp/gm_routes.tsv
  dx 'cd /var/www/html && php -r '"'"'
require "vendor/autoload.php"; $a=\Elgg\Application::getInstance(); $a->bootCore();
$views = _elgg_services()->views;
$u = elgg_get_entities(["type"=>"user","limit"=>1]); $uname = $u ? $u[0]->username : "admin";

function plugin_of($path) {
  if (!$path) return "?";
  if (preg_match("#/mod/([^/]+)/#", $path, $m)) return $m[1];
  if (strpos($path, "vendor/elgg/elgg") !== false) return "core";
  return "?";
}

foreach (_elgg_services()->routes->all() as $name=>$r) {
  $methods = $r->getMethods();
  if (!empty($methods) && !in_array("GET", $methods)) continue;
  $method = empty($methods) ? "GET" : "GET";
  $path = $r->getPath();
  if (strpos($path, "/action/") === 0) continue;
  // Skip endpoints that require a signed/MAC URL — crawling them raw trips the
  // UpgradeGatekeeper (security_protect_upgrade) and renders a benign 500 that
  // is NOT a migration defect. They are exercised via their own signed flow.
  if (strpos($path, "/upgrade") === 0) continue;

  // resolve owning plugin via resource view file, then controller/handler/file
  $d = $r->getDefaults();
  $file = "";
  if (!empty($d["_resource"])) {
    $file = $views->findViewFile("resources/" . $d["_resource"], "default");
  }
  if (!$file && !empty($d["_controller"]) && is_string($d["_controller"]) && class_exists($d["_controller"])) {
    try { $file = (new ReflectionClass($d["_controller"]))->getFileName(); } catch (\Throwable $e) {}
  }
  if (!$file && !empty($d["_file"]) && is_string($d["_file"])) { $file = $d["_file"]; }
  $plugin = plugin_of($file);

  // fill route params from real data
  $path = preg_replace_callback("/\{(\w+)[^}]*\}/", function($mm) use ($uname) {
    $k = $mm[1];
    if (in_array($k, ["username","name"])) return $uname;
    if (in_array($k, ["segments","any","path"])) return "";
    if (strpos($k,"guid")!==false || $k=="lc" || $k=="id") {
      $e = elgg_get_entities(["limit"=>1,"order_by"=>"e.guid ASC"]);
      return $e ? $e[0]->guid : "1";
    }
    return "1";
  }, $path);
  $path = preg_replace("#//+#","/",$path);

  echo $method . "\t" . $path . "\t" . $plugin . "\n";
}
'"'"' 2>/dev/null | sort -u > /tmp/gm_routes.tsv'

  local total
  total=$(dx "grep -c . /tmp/gm_routes.tsv" 2>/dev/null)
  echo "  enumerated $total GET routes" >&2

  # 2. Crawl anon, then (if creds) auth — emit context\tmethod path\tplugin\tstatus
  #    Done in ONE in-container shell pass for speed.
  local DO_AUTH=0
  [ -n "$USER" ] && [ -n "$PASS" ] && DO_AUTH=1

  dx_auth "BASE='$BASE'; DO_AUTH='$DO_AUTH'
crawl() {
  ctx=\"\$1\"; cookie=\"\$2\"
  while IFS=\$(printf '\t') read -r method path plugin; do
    [ -z \"\$path\" ] && continue
    code=\$(curl -s -o /dev/null -w '%{http_code}' \$cookie \"\$BASE\$path\" 2>/dev/null)
    printf '%s %s %s\t%s\t%s\n' \"\$ctx\" \"\$method\" \"\$path\" \"\$plugin\" \"\$code\"
  done < /tmp/gm_routes.tsv
}
crawl anon ''
if [ \"\$DO_AUTH\" = '1' ]; then
  # Fetch /login ONCE — __elgg_token is an HMAC over __elgg_ts, so they MUST
  # come from the same page load or CSRF validation rejects the login.
  curl -s -c /tmp/gm.cj \"\$BASE/login\" > /tmp/gm_login.html
  TOK=\$(grep -oE '__elgg_token[^>]*value=\"[^\"]+\"' /tmp/gm_login.html | grep -oE 'value=\"[^\"]+\"' | head -1 | cut -d'\"' -f2)
  TS=\$(grep -oE '__elgg_ts[^>]*value=\"[0-9]+\"' /tmp/gm_login.html | grep -oE '[0-9]+' | head -1)
  curl -s -b /tmp/gm.cj -c /tmp/gm.cj -o /dev/null --data-urlencode \"username=\$LUSER\" --data-urlencode \"password=\$LPASS\" --data-urlencode \"__elgg_token=\$TOK\" --data-urlencode \"__elgg_ts=\$TS\" \"\$BASE/action/login\" >/dev/null 2>&1
  AUTHCHK=\$(curl -s -b /tmp/gm.cj -o /dev/null -w '%{http_code}' \"\$BASE/dashboard\")
  [ \"\$AUTHCHK\" = '200' ] || echo \"  WARN: auth login FAILED (dashboard=\$AUTHCHK) — auth crawl mirrors anon; check creds/username\" >&2
  crawl auth '-b /tmp/gm.cj'
fi" | LC_ALL=C sort > "$OUT"

  local lines anon auth p5 p3 p2
  lines=$(grep -c . "$OUT")
  anon=$(grep -c '^anon ' "$OUT")
  auth=$(grep -c '^auth ' "$OUT")
  p5=$(awk -F'\t' '$3 ~ /^5/' "$OUT" | wc -l)
  p2=$(awk -F'\t' '$3 ~ /^2/' "$OUT" | wc -l)
  p3=$(awk -F'\t' '$3 ~ /^3/' "$OUT" | wc -l)
  echo "  wrote $OUT" >&2
  echo "  rows=$lines (anon=$anon auth=$auth)  2xx=$p2 3xx=$p3 5xx=$p5" >&2
  if [ "$p5" -gt 0 ]; then
    echo "  --- 5xx routes in this snapshot (by plugin) ---" >&2
    awk -F'\t' '$3 ~ /^5/ {print "    "$2"\t"$1"\t"$3}' "$OUT" | sort | head -40 >&2
  fi
  echo "$OUT"
}

# ---------------------------------------------------------------------------
case "${1:-}" in
  capture)
    shift
    LABEL="${1:-}"; [ $# -gt 0 ] && shift
    while [ $# -gt 0 ]; do case "$1" in
      --container) CONTAINER="$2"; shift 2;;
      --base) BASE="$2"; shift 2;;
      --user) USER="$2"; shift 2;;
      --pass) PASS="$2"; shift 2;;
      *) echo "unknown arg: $1" >&2; exit 2;;
    esac; done
    do_capture "$LABEL"
    ;;
  diff)
    shift
    [ $# -eq 2 ] || { echo "usage: $0 diff <fileA> <fileB>" >&2; exit 2; }
    do_diff "$1" "$2"
    ;;
  *)
    echo "usage: $0 capture <label> [--container N] [--base URL] [--user U --pass P]" >&2
    echo "       $0 diff <fileA> <fileB>" >&2
    exit 2
    ;;
esac
