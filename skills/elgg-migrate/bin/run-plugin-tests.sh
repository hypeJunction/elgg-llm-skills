#!/usr/bin/env bash
# run-plugin-tests.sh — run a migrated plugin's authored PHPUnit suite inside
# the running Elgg 7 container, against real Elgg core, and exit non-zero on
# any test failure/error.
#
# Usage:
#   run-plugin-tests.sh <plugin-id> [--suite=unit|integration|all]
#
# Options:
#   --suite=unit         run the unit tests only (default). Static, no DB writes.
#   --suite=integration  run the integration tests. These extend
#                        Elgg\IntegrationTestCase, WRITE real entities and have NO
#                        transaction rollback. They run against $ELGG_DB_PREFIX,
#                        which defaults to the disposable c_i_elgg_ table set —
#                        create it with bin/provision-test-db.sh. The script
#                        refuses to run against the site's live prefix.
#   --suite=all          run whichever of unit/integration dirs are populated.
#
# The plugin's populated test directory is targeted explicitly (tests/Unit or
# tests/phpunit/unit for unit; tests/Integration or tests/phpunit/integration for
# integration), so testsuite-name/casing drift between authored layouts is moot.
#
# Path resolution (NO absolute /home/<user> paths are baked in — this ships as a
# vendorable skill). Roots are read from env with sensible fallbacks:
#   ELGG_MIGRATE_PLUGINS  workspace holding <plugin-id>/ source dirs.
#                         Falls back to ~/.config/elgg-migrate/config.json
#                         (plugins_source), same contract as discover-plugins.sh.
#   ELGG_APP_CONTAINER    running Elgg 7 container name. Default: elgg7-elgg-1
#                         (the skill's own infra/elgg7 stack); set it to your
#                         site's app container when testing against a real site.
#   ELGG_DB_PREFIX        DB table prefix for integration tests. Default: c_i_elgg_
#                         (Elgg\BaseTestCase's own default). NEVER set this to the
#                         site's live prefix: IntegrationTestCase writes real
#                         entities and has no transaction rollback. Provision the
#                         test tables once with bin/provision-test-db.sh.
#
# Why a scratch copy: the container's /var/www/html/mod/<plugin> is a baked,
# possibly-stale snapshot that the LIVE site serves. We must not mutate it (other
# agents probe the running site). Instead we stage the *current* workspace source
# at /var/www/html/mod-test/<plugin> — a sibling that is still exactly three
# directories below /var/www/html, so the plugin's tests/bootstrap.php resolves
# $elggRoot to /var/www/html and loads real Elgg core. mod-test/ is NOT under
# mod/, so Elgg never scans it and the live site is untouched. It is removed on
# exit.
#
# phpunit: obtained ONCE as a pinned 9.6 PHAR at /usr/local/lib/phpunit-9.phar
# inside the container (downloaded if absent). The site composer.json is never
# touched. PHPUnit 9's flag for a disposable result cache is --do-not-cache-result
# (the historical --cache-result=false spelling is rejected by 9.x); it makes
# concurrent runs of different plugins safe against .phpunit.result.cache races.
#
# Concurrency: each plugin stages into its own mod-test/<plugin-id>, so distinct
# plugins can run in parallel. The PHAR is read-only once present.
#
# Exit codes:
#   0  all tests passed
#   1  phpunit reported failures/errors, or a setup problem (missing plugin,
#      no tests, container down)
#   2  usage error
#   3  the plugin redeclares a global function: the staged copy and the container's
#      live mod/<id> both declare it. Not runnable this way — use an isolated stack.
#   4  refused: $ELGG_DB_PREFIX is the site's LIVE table prefix
#   5  the disposable test schema is missing (run bin/provision-test-db.sh)

set -uo pipefail

PHAR_URL="https://phar.phpunit.de/phpunit-9.phar"
PHAR_PATH="/usr/local/lib/phpunit-9.phar"

APP_CONTAINER="${ELGG_APP_CONTAINER:-elgg7-elgg-1}"
# Elgg\BaseTestCase already defaults to 'c_i_elgg_'; passing 'elgg_' here silently
# overrode that and aimed integration tests at the LIVE tables.
DB_PREFIX="${ELGG_DB_PREFIX:-c_i_elgg_}"

usage() {
    sed -n '2,40p' "$0" | sed 's/^# \{0,1\}//'
}

# --- args -----------------------------------------------------------------
PLUGIN_ID=""
SUITE="unit"
for arg in "$@"; do
    case "$arg" in
        --suite=*) SUITE="${arg#--suite=}" ;;
        -h|--help) usage; exit 0 ;;
        --*)       echo "unknown option: $arg" >&2; exit 2 ;;
        *)
            if [ -z "$PLUGIN_ID" ]; then
                PLUGIN_ID="$arg"
            else
                echo "unexpected argument: $arg" >&2; exit 2
            fi
            ;;
    esac
done

if [ -z "$PLUGIN_ID" ]; then
    echo "ERROR: plugin-id required." >&2
    usage
    exit 2
fi

case "$SUITE" in
    unit|integration|all) : ;;
    *) echo "ERROR: --suite must be unit|integration|all (got '$SUITE')" >&2; exit 2 ;;
esac

# --- resolve plugin source root -------------------------------------------
resolve_plugins_root() {
    if [ -n "${ELGG_MIGRATE_PLUGINS:-}" ]; then
        echo "$ELGG_MIGRATE_PLUGINS"
        return
    fi
    local cfg="${XDG_CONFIG_HOME:-$HOME/.config}/elgg-migrate/config.json"
    if [ -f "$cfg" ]; then
        local cached
        cached=$(python3 -c 'import json,sys; print(json.load(open(sys.argv[1])).get("plugins_source",""))' "$cfg" 2>/dev/null || true)
        if [ -n "$cached" ]; then
            echo "$cached"
            return
        fi
    fi
    echo "ERROR: plugin workspace unknown. Set \$ELGG_MIGRATE_PLUGINS or populate $cfg (plugins_source)." >&2
    exit 1
}

PLUGINS_ROOT="$(resolve_plugins_root)"
PLUGIN_SRC="$PLUGINS_ROOT/$PLUGIN_ID"

if [ ! -d "$PLUGIN_SRC" ]; then
    echo "ERROR: plugin source not found: $PLUGIN_SRC" >&2
    exit 1
fi
if [ ! -d "$PLUGIN_SRC/tests" ]; then
    echo "ERROR: $PLUGIN_ID has no tests/ directory — nothing to run." >&2
    exit 1
fi

# Two authored layouts exist:
#   old  : phpunit.xml at plugin root; suites in tests/Unit, tests/Integration
#   new  : tests/phpunit.xml; suites in tests/phpunit/unit, tests/phpunit/integration
# (some plugins carry a vestigial second config, so we do NOT trust suite names).
# We pick the config whose bootstrap loads Elgg core, then point phpunit at the
# real test *directory* explicitly — sidestepping suite-name/casing drift.
if [ -f "$PLUGIN_SRC/tests/phpunit.xml" ]; then
    CONFIG_SUBDIR="tests"
elif [ -f "$PLUGIN_SRC/phpunit.xml" ]; then
    CONFIG_SUBDIR="."
else
    echo "ERROR: $PLUGIN_ID has no phpunit.xml (root or tests/) — nothing to run." >&2
    exit 1
fi

# Locate the populated unit / integration directories (relative to plugin root).
# First existing, *Test.php-bearing candidate wins.
find_suite_dir() {
    # $@ = candidate dirs relative to plugin root
    local c
    for c in "$@"; do
        if [ -d "$PLUGIN_SRC/$c" ] && \
           [ -n "$(find "$PLUGIN_SRC/$c" -name '*Test.php' -print -quit 2>/dev/null)" ]; then
            printf '%s' "$c"
            return 0
        fi
    done
    return 1
}

UNIT_DIR="$(find_suite_dir tests/Unit tests/phpunit/unit || true)"
INTEGRATION_DIR="$(find_suite_dir tests/Integration tests/phpunit/integration || true)"

# Express a plugin-root-relative dir relative to the phpunit cwd (CONFIG_SUBDIR).
rel_to_cfg() {
    local d="$1"
    if [ "$CONFIG_SUBDIR" = "tests" ]; then
        printf '%s' "${d#tests/}"
    else
        printf '%s' "$d"
    fi
}

TARGET_DIRS=()
case "$SUITE" in
    unit)
        [ -n "$UNIT_DIR" ] || { echo "ERROR: $PLUGIN_ID has no populated unit test dir." >&2; exit 1; }
        TARGET_DIRS+=("$(rel_to_cfg "$UNIT_DIR")")
        ;;
    integration)
        [ -n "$INTEGRATION_DIR" ] || { echo "ERROR: $PLUGIN_ID has no populated integration test dir." >&2; exit 1; }
        TARGET_DIRS+=("$(rel_to_cfg "$INTEGRATION_DIR")")
        ;;
    all)
        [ -n "$UNIT_DIR" ]        && TARGET_DIRS+=("$(rel_to_cfg "$UNIT_DIR")")
        [ -n "$INTEGRATION_DIR" ] && TARGET_DIRS+=("$(rel_to_cfg "$INTEGRATION_DIR")")
        [ ${#TARGET_DIRS[@]} -gt 0 ] || { echo "ERROR: $PLUGIN_ID has no populated test dirs." >&2; exit 1; }
        ;;
esac

# --- container reachable? -------------------------------------------------
if ! docker exec "$APP_CONTAINER" true 2>/dev/null; then
    echo "ERROR: container '$APP_CONTAINER' is not running/execable." >&2
    exit 1
fi

# --- obtain phpunit PHAR once (never touch site composer.json) ------------
docker exec "$APP_CONTAINER" sh -c '
    set -e
    if [ ! -s "'"$PHAR_PATH"'" ]; then
        tmp="'"$PHAR_PATH"'.tmp.$$"
        curl -sSL -o "$tmp" "'"$PHAR_URL"'"
        chmod +x "$tmp"
        mv -f "$tmp" "'"$PHAR_PATH"'"
    fi
' || { echo "ERROR: could not obtain phpunit PHAR in container." >&2; exit 1; }

# --- stage current source into an isolated, non-mod scratch dir -----------
SCRATCH="/var/www/html/mod-test/$PLUGIN_ID"
cleanup() {
    # SCRATCH lives under mod-test/, NEVER under mod/ — safe to remove and it is
    # container-local (not bind-mounted). Iron rule: never delete under mod/.
    docker exec "$APP_CONTAINER" rm -rf "$SCRATCH" 2>/dev/null || true
}
trap cleanup EXIT

docker exec "$APP_CONTAINER" sh -c "rm -rf '$SCRATCH' && mkdir -p '$SCRATCH'" \
    || { echo "ERROR: could not create scratch dir $SCRATCH" >&2; exit 1; }

# Ship only source + tests (skip .git/vendor/node_modules) so the tests scan the
# real plugin code, and stream it straight into the container.
tar -C "$PLUGIN_SRC" \
    --exclude=.git --exclude=vendor --exclude=node_modules \
    -cf - . \
  | docker exec -i "$APP_CONTAINER" tar -C "$SCRATCH" -xf - \
  || { echo "ERROR: failed to stage $PLUGIN_ID source into container." >&2; exit 1; }

# --- preflight: never let an integration suite touch the live tables --------
#
# Elgg\IntegrationTestCase writes real entities and has no transaction rollback;
# cleanup is whatever each test's down() remembers to do. Pointed at the site's
# prefix it mutates the running site.
#
# Elgg\BaseTestCase resolves the prefix as getenv('ELGG_DB_PREFIX') ?: 'c_i_elgg_',
# so those tables must EXIST or every test errors with "table doesn't exist" and the
# run reads like a code failure. bin/provision-test-db.sh creates them.

if [ "$SUITE" != "unit" ]; then
    LIVE_PREFIX="$(docker exec -e KEY=dbprefix "$APP_CONTAINER" php -r '
        $s = @file_get_contents("/var/www/html/elgg-config/settings.php");
        echo preg_match("/dbprefix[^=]*=\s*\x27([^\x27]*)/", $s, $m) ? $m[1] : "";
    ' 2>/dev/null)"

    if [ -n "$LIVE_PREFIX" ] && [ "$DB_PREFIX" = "$LIVE_PREFIX" ]; then
        echo "ERROR: refusing to run integration tests against the LIVE table prefix '$LIVE_PREFIX'." >&2
        echo "       Elgg\\IntegrationTestCase writes real entities and does not roll back." >&2
        echo "       Provision the disposable set once:" >&2
        echo "         ELGG_DB_CONTAINER=<db-container> bin/provision-test-db.sh" >&2
        echo "       then re-run (ELGG_DB_PREFIX defaults to c_i_elgg_)." >&2
        exit 4
    fi

    # NB: capture the probe's own exit status. Piping it (e.g. into sed) would make
    # the test read the LAST command's status instead.
    probe_err="$(docker exec -e TP="$DB_PREFIX" "$APP_CONTAINER" php -r '
        $s = @file_get_contents("/var/www/html/elgg-config/settings.php");
        $c = [];
        foreach (["dbhost", "dbname", "dbuser", "dbpass"] as $k) {
            preg_match("/" . $k . "[^=]*=\s*\x27([^\x27]*)/", $s, $m);
            $c[$k] = $m[1] ?? "";
        }
        try {
            $pdo = new PDO("mysql:host=" . $c["dbhost"] . ";dbname=" . $c["dbname"], $c["dbuser"], $c["dbpass"]);
            $q = $pdo->query("SHOW TABLES LIKE " . $pdo->quote(getenv("TP") . "entities"));
            exit($q && $q->fetchAll() ? 0 : 1);
        } catch (Throwable $e) {
            fwrite(STDERR, $e->getMessage());
            exit(2);
        }
    ' 2>&1)"
    probe_rc=$?

    if [ "$probe_rc" -eq 2 ]; then
        echo "ERROR: could not probe the test schema: $probe_err" >&2
        exit 5
    fi
    if [ "$probe_rc" -ne 0 ]; then
        echo "ERROR: no '${DB_PREFIX}entities' table — the disposable test schema is missing." >&2
        echo "       Every integration test would error with \"table doesn't exist\"." >&2
        echo "       Create it once:  ELGG_DB_CONTAINER=<db-container> bin/provision-test-db.sh" >&2
        exit 5
    fi
fi

# --- preflight: duplicate global declarations ------------------------------
#
# An INTEGRATION suite boots real Elgg, which loads the LIVE plugin from mod/<id>.
# If that plugin declares global functions from a file it require_once's off its
# own plugin root — `require_once __DIR__ . '/lib/functions.php'` in elgg-plugin.php,
# or a Bootstrap::load() doing `dirname(__DIR__, 3) . '/lib/functions.php'` — then
# the STAGED copy under mod-test/<id> is a second, distinct realpath. require_once
# de-duplicates by realpath, so both files are included and PHP dies with
#
#   Fatal error: Cannot redeclare foo() (previously declared in
#   /var/www/html/mod/<id>/lib/functions.php:12) in
#   /var/www/html/mod-test/<id>/lib/functions.php on line 12
#
# ...part-way through the run, after some tests have already reported.
#
# This is not a bug in the staging that can be patched here: the whole point of
# mod-test/ is to exercise the CURRENT workspace source while the container keeps
# serving its baked mod/ copy, and both copies of a global-declaring file cannot
# coexist in one PHP process. Detect it and say so, instead of failing obscurely.
# The sound way to test such a plugin is one process, one copy: an isolated stack
# whose mod/<id> IS the workspace source (bin/elgg-migrate-run).
if [ "$SUITE" != "unit" ]; then
    _globals_file=""
    for _f in lib/functions.php autoloader.php; do
        if [ -f "$PLUGIN_SRC/$_f" ] \
           && docker exec "$APP_CONTAINER" test -f "/var/www/html/mod/$PLUGIN_ID/$_f" 2>/dev/null \
           && grep -qE '^[[:space:]]*function[[:space:]]+[a-zA-Z_]' "$PLUGIN_SRC/$_f" 2>/dev/null; then
            _globals_file="$_f"
            break
        fi
    done
    if [ -n "$_globals_file" ]; then
        echo "ERROR: $PLUGIN_ID declares global functions in $_globals_file, and the container" >&2
        echo "       already loads mod/$PLUGIN_ID/$_globals_file when Elgg boots." >&2
        echo "       Staging a second copy at mod-test/ would redeclare them and fatal mid-run." >&2
        echo "" >&2
        echo "       An integration suite for this plugin needs ONE copy in the process." >&2
        echo "       Run it against an isolated stack whose mod/$PLUGIN_ID is the workspace" >&2
        echo "       source:  bin/elgg-migrate-run   (see the skill's SKILL.md)" >&2
        echo "       The unit suite (--suite=unit) does not boot Elgg and is unaffected." >&2
        exit 3
    fi
fi

# --- run -------------------------------------------------------------------
if [ "$CONFIG_SUBDIR" = "tests" ]; then
    RUN_DIR="$SCRATCH/tests"
else
    RUN_DIR="$SCRATCH"
fi
echo ">> $PLUGIN_ID  (suite=$SUITE, config=${CONFIG_SUBDIR}/phpunit.xml, dirs=${TARGET_DIRS[*]})"

# Stream the run AND keep a copy, so a duplicate-global fatal can be classified.
PHPUNIT_LOG="$(mktemp "${TMPDIR:-/tmp}/run-plugin-tests.$PLUGIN_ID.XXXXXX")"
trap 'rm -f "$PHPUNIT_LOG"' EXIT

docker exec \
    -e ELGG_DB_PREFIX="$DB_PREFIX" \
    -w "$RUN_DIR" \
    "$APP_CONTAINER" \
    php "$PHAR_PATH" -c phpunit.xml "${TARGET_DIRS[@]}" --do-not-cache-result 2>&1 | tee "$PHPUNIT_LOG"
STATUS="${PIPESTATUS[0]}"

# The preflight above catches the common shape (a globals file the plugin includes
# off its own root). It cannot catch every one: plugins include such files through
# Includer::requireFileOnce($this->getRoot() . '/lib/x.php'), from a Bootstrap, or
# straight from a test — and the filename is arbitrary (lib/tokeninput.php,
# lib/hooks.php). Rather than broaden the preflight into false positives (forms_api
# and private_profiles carry lib/ globals and run fine), classify the fatal itself.
# It is unambiguous, and it costs nothing when it does not occur.
if [ "$STATUS" -ne 0 ] && grep -qi 'Cannot redeclare' "$PHPUNIT_LOG"; then
    _fn="$(grep -oiE 'Cannot redeclare [a-zA-Z_][a-zA-Z0-9_]*' "$PHPUNIT_LOG" | head -1 | awk '{print $3}')"
    _file="$(grep -oE '/var/www/html/mod-test/[^ :]+' "$PHPUNIT_LOG" | head -1)"
    echo "" >&2
    echo "ERROR: $PLUGIN_ID redeclared ${_fn:-a global function} — the staged copy and the" >&2
    echo "       container's live mod/$PLUGIN_ID both declare it." >&2
    [ -n "$_file" ] && echo "       staged file: $_file" >&2
    echo "       Elgg boots the live plugin, and something in this plugin (Bootstrap," >&2
    echo "       elgg-plugin.php, or a test) also includes the file from the STAGED root." >&2
    echo "       Two realpaths, two includes, one fatal — see the note above the preflight." >&2
    echo "" >&2
    echo "       Run this suite against an isolated stack whose mod/$PLUGIN_ID IS the" >&2
    echo "       workspace source:  bin/elgg-migrate-run" >&2
    exit 3
fi

exit "$STATUS"
