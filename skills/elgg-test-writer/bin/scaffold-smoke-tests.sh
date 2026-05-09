#!/usr/bin/env bash
#
# scaffold-smoke-tests.sh — generate a baseline SmokeTest.php for an Elgg plugin.
#
# Usage:
#   scaffold-smoke-tests.sh [--plugin-dir=<path>] [--force]
#
# If --plugin-dir is omitted, the current working directory is used (must
# contain elgg-plugin.php).
#
# The script writes:
#   <plugin>/tests/phpunit/integration/SmokeTest.php
#
# The smoke test asserts the contract every migrated plugin must satisfy:
#   - plugin is registered (elgg_get_plugin_from_id)
#   - plugin activates without throwing
#   - every action in elgg-plugin.php's 'actions' array is registered at runtime
#   - every (type, subtype) in 'entities' has a loadable class binding
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

for arg in "$@"; do
    case "$arg" in
        --plugin-dir=*) plugin_dir="${arg#*=}" ;;
        --force)        force=1 ;;
        -h|--help)
            sed -n '3,22p' "$SCRIPT_PATH" | sed 's/^# \{0,1\}//'
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

action_count="$(printf '%s' "$action_rows" | grep -c '^' || true)"
entity_count="$(printf '%s' "$entity_rows" | grep -c '^' || true)"
[ -z "$action_rows" ] && action_count=0
[ -z "$entity_rows" ] && entity_count=0

cat <<NEXT
plugin_id    = $plugin_id
namespace    = $plugin_ns
actions      = $action_count
entities     = $entity_count
wrote        = ${dst#$plugin_dir/}

Next steps:
  cd "$plugin_dir"
  # Run inside the docker test stack (scaffold-docker.sh first if needed):
  docker compose -f docker/docker-compose.yml run --rm elgg \\
      vendor/bin/phpunit tests/phpunit/integration/SmokeTest.php
  git add tests/phpunit/integration/SmokeTest.php
  git commit -m "test: add baseline smoke tests"
NEXT
