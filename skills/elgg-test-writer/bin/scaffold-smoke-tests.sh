#!/usr/bin/env bash
#
# scaffold-smoke-tests.sh — generate the baseline + tests-first suite for a plugin.
#
# Usage:
#   scaffold-smoke-tests.sh [--plugin-dir=<path>] [--target-version=elggN] [--force]
#
# If --plugin-dir is omitted, the current working directory is used (must
# contain elgg-plugin.php).
#
# The script writes FIVE files:
#   <plugin>/tests/phpunit/integration/SmokeTest.php            (post-migration proof)
#   <plugin>/tests/phpunit/integration/BaselineTest.php         (GREEN before + after)
#   <plugin>/tests/phpunit/unit/RegressionTest.php             (7.x static guard)
#   <plugin>/tests/phpunit/unit/MigrationRegressionTest.php     (RED before, GREEN after)
#   <plugin>/tests/phpunit/integration/PerformanceRegressionTest.php (query-cost gate; entities only)
#
# TESTS-FIRST: BaselineTest + MigrationRegressionTest are generated so a
# migration can prove RED→GREEN. Run them BEFORE changing any plugin code:
#   - BaselineTest boots the CURRENT version → GREEN (records preserved behavior)
#   - MigrationRegressionTest scans source for the TARGET version's failure
#     classes → RED before migration, GREEN after.
#
# The SmokeTest asserts the contract every migrated plugin must satisfy:
#   - plugin is registered (elgg_get_plugin_from_id)
#   - plugin activates without throwing
#   - every action in elgg-plugin.php's 'actions' array is registered at runtime
#   - every (type, subtype) in 'entities' has a loadable class binding
#
# The current major is inferred from the elgg/elgg composer constraint; the
# migration target defaults to current+1 and can be pinned with
# --target-version=elggN (or --target-major=N).
#
# This is a baseline. Richer coverage (per-action 200/403, route reachability,
# view rendering) is added by the LLM-driven flow described in SKILL.md.
#
# Existing files are left alone unless --force is passed.

set -euo pipefail

SCRIPT_PATH="$(readlink -f "$0")"
BIN_DIR="$(dirname "$SCRIPT_PATH")"
SKILL_ROOT="$(dirname "$BIN_DIR")"
TEMPLATE="$SKILL_ROOT/templates/SmokeTest.php.template"
EXTRACTOR="$BIN_DIR/lib/extract-plugin-config.php"

die() { echo "error: $*" >&2; exit 1; }

plugin_dir=""
force=0
target_major=""

for arg in "$@"; do
    case "$arg" in
        --plugin-dir=*)     plugin_dir="${arg#*=}" ;;
        --target-version=*) target_major="$(echo "${arg#*=}" | tr -cd '0-9')" ;;
        --target-major=*)   target_major="$(echo "${arg#*=}" | tr -cd '0-9')" ;;
        --force)            force=1 ;;
        -h|--help)
            sed -n '3,33p' "$SCRIPT_PATH" | sed 's/^# \{0,1\}//'
            exit 0
            ;;
        *) die "unknown argument: $arg" ;;
    esac
done

if [ -z "$plugin_dir" ]; then
    plugin_dir="$(pwd)"
fi
plugin_dir="$(readlink -f "$plugin_dir")"
[ -d "$plugin_dir" ] || die "not a directory: $plugin_dir"
[ -f "$plugin_dir/elgg-plugin.php" ] || die "no elgg-plugin.php at $plugin_dir"

[ -f "$TEMPLATE" ] || die "template missing: $TEMPLATE"
[ -f "$EXTRACTOR" ] || die "extractor missing: $EXTRACTOR"

# Resolve PLUGIN_ID
plugin_id=""
if [ -f "$plugin_dir/composer.json" ] && command -v jq >/dev/null 2>&1; then
    name="$(jq -r '.name // ""' "$plugin_dir/composer.json")"
    if [ -n "$name" ] && [ "$name" != "null" ]; then
        plugin_id="${name##*/}"
    fi
fi
if [ -z "$plugin_id" ] && [ -f "$plugin_dir/manifest.xml" ]; then
    plugin_id="$(grep -oE '<id>[^<]+</id>' "$plugin_dir/manifest.xml" | head -n1 | sed -E 's|</?id>||g' || true)"
fi
if [ -z "$plugin_id" ]; then
    plugin_id="$(basename "$plugin_dir")"
fi
plugin_id="$(echo "$plugin_id" | tr '[:upper:]' '[:lower:]')"

# Derive a PSR-style namespace from the plugin id (foo_bar → FooBar).
plugin_ns="$(echo "$plugin_id" | awk -F'[_-]' '{
    out=""
    for (i=1; i<=NF; i++) {
        out = out toupper(substr($i,1,1)) substr($i,2)
    }
    print out
}')"
[ -n "$plugin_ns" ] || plugin_ns="Plugin"

# Infer the CURRENT major from the elgg/elgg composer constraint (first digit
# after the ^/~ operator, e.g. "~4.0" → 4). Fall back to 4 if unknown.
current_major=""
if [ -f "$plugin_dir/composer.json" ]; then
    current_major="$(grep -oE '"elgg/elgg"[[:space:]]*:[[:space:]]*"[~^><= ]*[0-9]+' "$plugin_dir/composer.json" \
        | grep -oE '[0-9]+$' | head -n1 || true)"
fi
[ -n "$current_major" ] || current_major=4

# BaselineTest boots the CURRENT major. Elgg 2.x has no \Elgg\IntegrationTestCase
# (it first ships in 3.0), so a hardcoded base class fataled at class-load on
# exactly the 2.x->3.x step — the one the baseline exists to protect. 2.x uses
# plain PHPUnit\Framework\TestCase over the plugin's custom tests/bootstrap.php,
# which loads Elgg core, so the elgg_* assertions in the body still run.
# NOTE: these strings are sed REPLACEMENT text, where a literal backslash must be
# written '\\'. Single-quoted here so the shell passes both characters through;
# with "\\Framework" sed would consume the escape and emit 'PHPUnitFrameworkTestCase'.
if [ "$current_major" -le 2 ] 2>/dev/null; then
    base_class="TestCase"
    base_class_use='use PHPUnit\\Framework\\TestCase;'
else
    base_class="IntegrationTestCase"
    base_class_use='use Elgg\\IntegrationTestCase;'
fi

# Migration target: explicit flag wins, else current+1 (capped at 7).
if [ -z "$target_major" ]; then
    target_major="$((current_major + 1))"
fi
[ "$target_major" -gt 7 ] 2>/dev/null && target_major=7
[ "$target_major" -lt 3 ] 2>/dev/null && target_major=3

dst_dir="$plugin_dir/tests/phpunit/integration"
dst="$dst_dir/SmokeTest.php"

if [ -e "$dst" ] && [ "$force" -ne 1 ]; then
    echo "skip (exists): ${dst#$plugin_dir/} — use --force to overwrite"
    exit 0
fi
mkdir -p "$dst_dir"

# Extract config (actions, entities) via the AST helper.
config_json="$(php "$EXTRACTOR" "$plugin_dir")" || die "extractor failed for $plugin_dir"

# Build the dataProvider rows. Two passes through the JSON via php -r.
action_rows="$(printf '%s' "$config_json" | php -r '
$j = json_decode(stream_get_contents(STDIN), true);
foreach (($j["actions"] ?? []) as $a) {
    printf("            [%s],\n", var_export($a, true));
}
')"
entity_rows="$(printf '%s' "$config_json" | php -r '
$j = json_decode(stream_get_contents(STDIN), true);
foreach (($j["entities"] ?? []) as $e) {
    if (empty($e["class"])) continue;
    printf("            [%s, %s, %s],\n",
        var_export($e["type"], true),
        var_export($e["subtype"], true),
        var_export($e["class"], true)
    );
}
')"

# Substitute placeholders in the template.
sed \
    -e "s|__PLUGIN_ID__|$plugin_id|g" \
    -e "s|__PLUGIN_NAMESPACE__|$plugin_ns|g" \
    "$TEMPLATE" > "$dst.tmp"

# Inject the rows (sed-with-multiline is gnarly; use awk).
awk -v actions="$action_rows" -v entities="$entity_rows" '
    /__ACTION_ROWS__/  { print actions; next }
    /__ENTITY_ROWS__/  { print entities; next }
    { print }
' "$dst.tmp" > "$dst"
rm -f "$dst.tmp"

# --- RegressionTest: static source-scan guard for recurring runtime-fatal
# bug classes (signature-incompat, null-title, legacy-language, removed
# instance method, css-orphan). Pure source scan → no Elgg boot, runs as a
# plain unit test without the Docker stack. ---
reg_template="$SKILL_ROOT/templates/RegressionTest.php.template"
reg_dst_dir="$plugin_dir/tests/phpunit/unit"
reg_dst="$reg_dst_dir/RegressionTest.php"
reg_written=""
if [ -f "$reg_template" ]; then
    if [ -e "$reg_dst" ] && [ "$force" -ne 1 ]; then
        echo "skip (exists): ${reg_dst#$plugin_dir/} — use --force to overwrite"
    else
        mkdir -p "$reg_dst_dir"
        sed \
            -e "s|__PLUGIN_ID__|$plugin_id|g" \
            -e "s|__PLUGIN_NAMESPACE__|$plugin_ns|g" \
            "$reg_template" > "$reg_dst"
        reg_written="${reg_dst#$plugin_dir/}"
    fi
fi

# --- BaselineTest: boots the CURRENT version and captures the observable
# behavior the migration must PRESERVE (GREEN before AND after). Shares the
# action/entity rows with SmokeTest. ---
base_template="$SKILL_ROOT/templates/BaselineTest.php.template"
base_dst="$plugin_dir/tests/phpunit/integration/BaselineTest.php"
base_written=""
if [ -f "$base_template" ]; then
    if [ -e "$base_dst" ] && [ "$force" -ne 1 ]; then
        echo "skip (exists): ${base_dst#$plugin_dir/} — use --force to overwrite"
    else
        mkdir -p "$plugin_dir/tests/phpunit/integration"
        sed \
            -e "s|__PLUGIN_ID__|$plugin_id|g" \
            -e "s|__PLUGIN_NAMESPACE__|$plugin_ns|g" \
            -e "s|__BASE_CLASS_USE__|$base_class_use|g" \
            -e "s|__BASE_CLASS__|$base_class|g" \
            "$base_template" > "$base_dst.tmp"
        awk -v actions="$action_rows" -v entities="$entity_rows" '
            /__ACTION_ROWS__/  { print actions; next }
            /__ENTITY_ROWS__/  { print entities; next }
            { print }
        ' "$base_dst.tmp" > "$base_dst"
        rm -f "$base_dst.tmp"
        base_written="${base_dst#$plugin_dir/}"
    fi
fi

# --- MigrationRegressionTest: static source scan asserting the TARGET major's
# failure classes are ABSENT (RED before migration, GREEN after). Parameterized
# by the target major. Runs WITHOUT the docker stack. ---
mig_template="$SKILL_ROOT/templates/MigrationRegressionTest.php.template"
mig_dst="$plugin_dir/tests/phpunit/unit/MigrationRegressionTest.php"
mig_written=""
if [ -f "$mig_template" ]; then
    if [ -e "$mig_dst" ] && [ "$force" -ne 1 ]; then
        echo "skip (exists): ${mig_dst#$plugin_dir/} — use --force to overwrite"
    else
        mkdir -p "$plugin_dir/tests/phpunit/unit"
        sed \
            -e "s|__PLUGIN_ID__|$plugin_id|g" \
            -e "s|__PLUGIN_NAMESPACE__|$plugin_ns|g" \
            -e "s|__TARGET_MAJOR__|$target_major|g" \
            "$mig_template" > "$mig_dst"
        mig_written="${mig_dst#$plugin_dir/}"
    fi
fi

# --- PerformanceRegressionTest: integration test that locks the Handler_read_next
# cost of this plugin's own entity shapes against tests/.perf-baseline.json, so a
# later index/getter change can't silently 10x the row scan. Boots Elgg; uses the
# delta method (no elevated DB privilege). Only meaningful when the plugin owns
# entities — with none, the test skips itself. ---
perf_template="$SKILL_ROOT/templates/PerformanceRegressionTest.php.template"
perf_dst="$plugin_dir/tests/phpunit/integration/PerformanceRegressionTest.php"
perf_written=""
if [ -f "$perf_template" ] && [ -n "$entity_rows" ]; then
    if [ -e "$perf_dst" ] && [ "$force" -ne 1 ]; then
        echo "skip (exists): ${perf_dst#$plugin_dir/} — use --force to overwrite"
    else
        mkdir -p "$plugin_dir/tests/phpunit/integration"
        sed \
            -e "s|__PLUGIN_ID__|$plugin_id|g" \
            -e "s|__PLUGIN_NAMESPACE__|$plugin_ns|g" \
            "$perf_template" > "$perf_dst.tmp"
        awk -v entities="$entity_rows" '
            /__ENTITY_ROWS__/  { print entities; next }
            { print }
        ' "$perf_dst.tmp" > "$perf_dst"
        rm -f "$perf_dst.tmp"
        perf_written="${perf_dst#$plugin_dir/}"
    fi
fi

action_count="$(printf '%s' "$action_rows" | grep -c '^' || true)"
entity_count="$(printf '%s' "$entity_rows" | grep -c '^' || true)"
[ -z "$action_rows" ] && action_count=0
[ -z "$entity_rows" ] && entity_count=0

cat <<NEXT
plugin_id      = $plugin_id
namespace      = $plugin_ns
current_major  = $current_major
target_major   = $target_major
actions        = $action_count
entities       = $entity_count
smoke          = ${dst#$plugin_dir/}
baseline       = ${base_written:-"(skipped)"}
regression     = ${reg_written:-"(skipped)"}
migration      = ${mig_written:-"(skipped)"}
performance    = ${perf_written:-"(skipped)"}

TESTS-FIRST — run these BEFORE changing any plugin code:
  cd "$plugin_dir"

  # 1. RED gate — static source scan for Elgg ${target_major}.x failure classes.
  #    Runs WITHOUT the docker stack. Expect FAILURES pre-migration; they turn
  #    GREEN as you fix each class. Do NOT start migrating until you've seen it RED.
  vendor/bin/phpunit tests/phpunit/unit/MigrationRegressionTest.php

  # 2. Behavior baseline — boot the CURRENT version (Elgg ${current_major}.x) stack and
  #    capture what works today. Must be GREEN before migration AND after.
  docker compose -f docker/docker-compose.yml run --rm elgg \\
      vendor/bin/phpunit tests/phpunit/integration/BaselineTest.php

  # 3. RegressionTest (7.x static guard) also runs without the stack:
  vendor/bin/phpunit tests/phpunit/unit/RegressionTest.php

  # 4. SmokeTest boots Elgg — run it on the TARGET stack AFTER migrating:
  docker compose -f docker/docker-compose.yml run --rm elgg \\
      vendor/bin/phpunit tests/phpunit/integration/SmokeTest.php

  git add tests/phpunit
  git commit -m "test: add tests-first baseline + migration-regression suite"
NEXT
