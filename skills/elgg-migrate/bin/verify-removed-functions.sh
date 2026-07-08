#!/usr/bin/env bash
# verify-removed-functions.sh — check, against a REAL Elgg core, whether each
# candidate global function still exists at a given major version. This is how
# references/removed-functions.json is kept honest: a function is "removed at N"
# only if function_exists() is false in an N.x core (a static grep is fooled by
# plugin-provided helpers). Run it per version to VERIFY the JSON's removal
# placement and to discover functions removed EARLIER than recorded.
#
# It runs inside a FULLY-INSTALLED, running container (any of this repo's
# infra/elggN stacks after boot, or a live site container). Elgg's procedural API
# is loaded by \Elgg\Application::loadCore() (a bare `require vendor/autoload.php`
# does NOT define the elgg_* functions), which needs a valid settings.php + DB —
# hence a booted container, not just a built image.
#
# Usage:
#   verify-removed-functions.sh --container <name> --version <N> [--fns "a b c"]
#   verify-removed-functions.sh --container mp-elgg3-elgg-1 --version 3
#
# With no --fns, the candidate set is every call-shaped key across all version
# blocks of removed-functions.json (so each is checked at each core). Output:
#   <fn>  EXISTS|REMOVED   at N.x
# and a summary of which JSON[N.x]-claimed removals are contradicted (still
# EXIST) or confirmed, plus functions REMOVED at N.x but recorded only later.
set -u

SELF_DIR="$(cd "$(dirname "$0")" && pwd)"
JSON="$SELF_DIR/../references/removed-functions.json"
CONTAINER=""
VERSION=""
FNS=""
PHP_VENDOR="/var/www/html/vendor/autoload.php"

while [ $# -gt 0 ]; do
  case "$1" in
    --container) CONTAINER="$2"; shift 2 ;;
    --version) VERSION="$2"; shift 2 ;;
    --fns) FNS="$2"; shift 2 ;;
    --vendor) PHP_VENDOR="$2"; shift 2 ;;
    *) echo "unknown arg: $1" >&2; exit 2 ;;
  esac
done
[ -n "$CONTAINER" ] || { echo "ERROR: --container required" >&2; exit 2; }
[ -n "$VERSION" ]   || { echo "ERROR: --version required (e.g. 3)" >&2; exit 2; }

# Candidate set: --fns, or every call-shaped key in the JSON.
if [ -z "$FNS" ]; then
  FNS="$(python3 - "$JSON" <<'PY'
import json,sys
d=json.load(open(sys.argv[1]))
out=set()
for v,block in d.items():
    if not isinstance(block,dict) or not v[0].isdigit(): continue
    for k in block:
        if not k.isupper() and '::' not in k: out.add(k)
print(' '.join(sorted(out)))
PY
)"
fi

echo "### verify-removed-functions: ${VERSION}.x core (container=$CONTAINER) ###"

# Run function_exists() inside the container. autoload.files loads the core
# procedural API without a DB.
RESULT="$(docker exec -e FNS="$FNS" -w "$(dirname "$(dirname "$PHP_VENDOR")")" "$CONTAINER" php -r '
  $vendor = "'"$PHP_VENDOR"'";
  if (!is_file($vendor)) { fwrite(STDERR, "no autoload at $vendor\n"); exit(3); }
  require $vendor;
  try { \Elgg\Application::loadCore(); }
  catch (\Throwable $e) { fwrite(STDERR, "loadCore failed: ".$e->getMessage()."\n"); exit(4); }
  foreach (explode(" ", trim(getenv("FNS"))) as $f) {
    if ($f === "") continue;
    echo $f . "\t" . (function_exists($f) ? "EXISTS" : "REMOVED") . "\n";
  }
' 2>/tmp/vrf-err.$$ )" || { echo "php run failed:"; cat /tmp/vrf-err.$$ >&2; rm -f /tmp/vrf-err.$$; exit 3; }
rm -f /tmp/vrf-err.$$

echo "$RESULT" | sed "s/\$/\t${VERSION}.x/" | sort

# Cross-check against the JSON's claimed removals for this major (cumulative:
# anything removed at <= this major should be REMOVED here).
echo
echo "--- reconciliation vs removed-functions.json (cumulative <= ${VERSION}.x) ---"
SWEEP="$RESULT" python3 - "$JSON" "$VERSION" <<'PY'
import json,os,sys
json_path, ver = sys.argv[1], int(sys.argv[2])
d=json.load(open(json_path))
claimed=set()
for v,block in d.items():
    if not isinstance(block,dict) or not v[0].isdigit(): continue
    if int(v[0])<=ver:
        claimed |= {k for k in block if not k.isupper() and '::' not in k}
exists=set(); removed=set()
for line in os.environ.get('SWEEP','').splitlines():
    if '\t' not in line: continue
    f,st=line.split('\t')[:2]
    (exists if st=='EXISTS' else removed).add(f)
contradicted = sorted(claimed & exists)   # JSON says removed <=ver, but core still HAS it
confirmed    = sorted(claimed & removed)
earlier      = sorted(removed - claimed)  # gone at ver but NOT recorded as removed <=ver
print(f"claimed-removed <= {ver}.x : {len(claimed)}")
print(f"CONFIRMED removed          : {len(confirmed)}")
if contradicted:
    print(f"CONTRADICTED (still EXISTS despite JSON): {contradicted}")
if earlier:
    print(f"REMOVED at {ver}.x but NOT recorded as removed <= {ver}.x: {earlier}")
if not contradicted and not earlier:
    print("all reconciled for this core")
PY
