#!/usr/bin/env bash
#
# scaffold-docker.sh — copy the per-plugin test stack into a plugin repo.
#
# Usage:
#   scaffold-docker.sh [--plugin-dir=<path>] [--elgg-version=<elgg2..elgg7>] [--force]
#
# If --plugin-dir is omitted, the current working directory is used (must
# contain elgg-plugin.php or start.php). If --elgg-version is omitted, the
# script infers it from the plugin's composer.json "require" block
# (elgg/elgg ^4.x → elgg4, etc.), defaulting to elgg4 when nothing is
# declared.
#
# The script writes to:
#   <plugin>/docker/Dockerfile
#   <plugin>/docker/docker-compose.yml
#   <plugin>/docker/elgg-install.sh
#   <plugin>/docker/elgg-composer.json
#   <plugin>/docker/index.php
#   <plugin>/docker/.env.example
#   <plugin>/docker/.env             (only if missing, with PLUGIN_ID filled in)
#   <plugin>/DEVELOPMENT.md          (only if missing)
#
# Existing files are left alone unless --force is passed.
#
# PLUGIN_ID resolution order:
#   1. composer.json "name" field, lowercased, basename after the slash
#   2. manifest.xml <id> element
#   3. basename of the plugin directory, lowercased
#
# The script is intentionally deterministic — no prompts, no LLM fallbacks.
# Run it from the plugin root or point it at one and it either succeeds or
# prints exactly which precondition failed.

set -euo pipefail

SCRIPT_PATH="$(readlink -f "$0")"
BIN_DIR="$(dirname "$SCRIPT_PATH")"
SKILL_ROOT="$(dirname "$BIN_DIR")"
TEMPLATES="$SKILL_ROOT/templates"

die() { echo "error: $*" >&2; exit 1; }

plugin_dir=""
elgg_version=""
force=0

for arg in "$@"; do
    case "$arg" in
        --plugin-dir=*)  plugin_dir="${arg#*=}" ;;
        --elgg-version=*) elgg_version="${arg#*=}" ;;
        --force)         force=1 ;;
        -h|--help)
            sed -n '3,30p' "$SCRIPT_PATH" | sed 's/^# \{0,1\}//'
            exit 0
            ;;
        *) die "unknown argument: $arg" ;;
    esac
done

# Resolve plugin directory
if [ -z "$plugin_dir" ]; then
    plugin_dir="$(pwd)"
fi
plugin_dir="$(readlink -f "$plugin_dir")"
[ -d "$plugin_dir" ] || die "not a directory: $plugin_dir"
if [ ! -f "$plugin_dir/elgg-plugin.php" ] && [ ! -f "$plugin_dir/start.php" ]; then
    die "not an Elgg plugin (no elgg-plugin.php or start.php): $plugin_dir"
fi

# Resolve PLUGIN_ID
plugin_id=""
if [ -f "$plugin_dir/composer.json" ]; then
    if command -v jq >/dev/null 2>&1; then
        name="$(jq -r '.name // ""' "$plugin_dir/composer.json")"
        if [ -n "$name" ] && [ "$name" != "null" ]; then
            plugin_id="${name##*/}"
        fi
    fi
fi
if [ -z "$plugin_id" ] && [ -f "$plugin_dir/manifest.xml" ]; then
    plugin_id="$(grep -oE '<id>[^<]+</id>' "$plugin_dir/manifest.xml" | head -n1 | sed -E 's|</?id>||g' || true)"
fi
if [ -z "$plugin_id" ]; then
    plugin_id="$(basename "$plugin_dir")"
fi
plugin_id="$(echo "$plugin_id" | tr '[:upper:]' '[:lower:]')"
[ -n "$plugin_id" ] || die "could not resolve PLUGIN_ID"

# Resolve elgg version from composer.json if not supplied
if [ -z "$elgg_version" ] && [ -f "$plugin_dir/composer.json" ] && command -v jq >/dev/null 2>&1; then
    constraint="$(jq -r '.require["elgg/elgg"] // ""' "$plugin_dir/composer.json")"
    case "$constraint" in
        *"2."*|"^2"*|"~2"*) elgg_version="elgg2" ;;
        *"3."*|"^3"*|"~3"*) elgg_version="elgg3" ;;
        *"4."*|"^4"*|"~4"*) elgg_version="elgg4" ;;
        *"5."*|"^5"*|"~5"*) elgg_version="elgg5" ;;
        *"6."*|"^6"*|"~6"*) elgg_version="elgg6" ;;
        *"7."*|"^7"*|"~7"*) elgg_version="elgg7" ;;
    esac
fi
[ -n "$elgg_version" ] || elgg_version="elgg4"
case "$elgg_version" in
    elgg2|elgg3|elgg4|elgg5|elgg6|elgg7) ;;
    *) die "unknown --elgg-version: $elgg_version (expected elgg2..elgg7)" ;;
esac

template_dir="$TEMPLATES/$elgg_version"
[ -d "$template_dir" ] || die "no template for $elgg_version at $template_dir"

echo "plugin_dir   = $plugin_dir"
echo "plugin_id    = $plugin_id"
echo "elgg_version = $elgg_version"
echo "template     = $template_dir"

# Copy template files
docker_dir="$plugin_dir/docker"
mkdir -p "$docker_dir"

copy_if_missing() {
    local src="$1" dst="$2"
    if [ -e "$dst" ] && [ "$force" -ne 1 ]; then
        echo "  skip (exists): ${dst#$plugin_dir/}"
        return
    fi
    cp "$src" "$dst"
    echo "  wrote: ${dst#$plugin_dir/}"
}

for f in Dockerfile docker-compose.yml elgg-install.sh elgg-composer.json index.php .env.example; do
    [ -f "$template_dir/$f" ] || die "template missing file: $template_dir/$f"
    copy_if_missing "$template_dir/$f" "$docker_dir/$f"
done
chmod +x "$docker_dir/elgg-install.sh"

# Inject dep volume mounts into docker-compose.yml from tests/deps.local.txt.
#
# deps.local.txt (gitignored) maps plugin IDs to local paths:
#   <plugin-id> <path>   (absolute, or relative to plugin root)
#
# Plugin dep IDs are declared in the plugin's elgg-plugin.php or manifest.xml —
# deps.local.txt only maps those IDs to local checkout paths for volume mounts.
# elgg-install.sh reads the plugin metadata directly; deps.local.txt is only
# consumed by this scaffold script.
local_deps_file="$plugin_dir/tests/deps.local.txt"
compose_file="$docker_dir/docker-compose.yml"
if [ -f "$local_deps_file" ]; then
    dep_mounts=""
    while IFS= read -r line; do
        line="${line%%#*}"   # strip inline comments
        line="$(echo "$line" | sed 's/^[[:space:]]*//; s/[[:space:]]*$//')"
        [ -z "$line" ] && continue
        dep_id="${line%% *}"
        dep_path_raw="${line#* }"
        [ "$dep_path_raw" = "$dep_id" ] && { echo "  WARNING: deps.local.txt line '$dep_id' has no path — skipping"; continue; }
        # Resolve relative paths relative to plugin root
        case "$dep_path_raw" in
            /*) dep_path="$dep_path_raw" ;;
            *)  dep_path="$plugin_dir/$dep_path_raw" ;;
        esac
        dep_path="$(readlink -f "$dep_path" 2>/dev/null || echo "$dep_path")"
        if [ ! -d "$dep_path" ]; then
            echo "  WARNING: dep '$dep_id' path not found: $dep_path — skipping volume mount"
            continue
        fi
        # Make path relative to docker/ dir for docker-compose (must be relative to compose file)
        rel_path="$(python3 -c "import os,sys; print(os.path.relpath(sys.argv[1], sys.argv[2]))" "$dep_path" "$docker_dir")"
        dep_mounts="${dep_mounts}      - ${rel_path}:/var/www/html/mod/${dep_id}:ro\n"
        echo "  dep mount: ${dep_id} → ${rel_path}"
    done < "$local_deps_file"

    if [ -n "$dep_mounts" ]; then
        if ! grep -q "# Dep plugins from deps.local.txt" "$compose_file"; then
            python3 - "$compose_file" "$dep_mounts" <<'PYEOF'
import sys, re

compose_path = sys.argv[1]
dep_mounts_raw = sys.argv[2]
dep_lines = dep_mounts_raw.replace("\\n", "\n")

with open(compose_path) as f:
    content = f.read()

insertion = (
    "      # Dep plugins from deps.local.txt (read-only — not under test here).\n"
    + dep_lines
)

pattern = r"(      - \.\.:/var/www/html/mod/\$\{PLUGIN_ID\})"
replacement = r"\1\n" + insertion.rstrip("\n")
new_content = re.sub(pattern, replacement, content, count=1)

with open(compose_path, "w") as f:
    f.write(new_content)

print("  injected dep volume mounts into docker-compose.yml")
PYEOF
        fi
    fi
fi

# Write .env with PLUGIN_ID filled in (only if missing)
env_file="$docker_dir/.env"
if [ -f "$env_file" ] && [ "$force" -ne 1 ]; then
    echo "  skip (exists): docker/.env"
else
    {
        echo "# Generated by elgg-test-writer/bin/scaffold-docker.sh"
        echo "PLUGIN_ID=$plugin_id"
    } > "$env_file"
    echo "  wrote: docker/.env (PLUGIN_ID=$plugin_id)"
fi

# DEVELOPMENT.md at plugin root
dev_md_src="$TEMPLATES/DEVELOPMENT.md"
dev_md_dst="$plugin_dir/DEVELOPMENT.md"
if [ -f "$dev_md_src" ]; then
    copy_if_missing "$dev_md_src" "$dev_md_dst"
fi

# Extend .gitignore so local / generated test files don't get committed.
#
# This is the single owner of the test-stack .gitignore entries — the
# phpunit/playwright artifacts used to be appended ad-hoc by agents, which
# left files without a trailing newline and produced glued lines like
# `tests/playwright/test-results/docker/.env`.
gitignore="$plugin_dir/.gitignore"

# Append a newline only if the file is non-empty and its last byte isn't one.
# `$(tail -c1 ...)` strips a trailing newline, so a final newline byte yields
# an empty string. Pure shell — no dependency on xxd/od.
ensure_trailing_newline() {
    local f="$1"
    [ -s "$f" ] || return 0
    if [ -n "$(tail -c1 "$f")" ]; then
        printf '\n' >> "$f"
    fi
}

# Idempotently ensure each entry is present as its own exact line. `grep -Fxq`
# matches the whole line literally, so a glued or partial line is correctly
# treated as missing and the proper entry gets appended.
gitignore_entries=(
    'docker/.env'
    'tests/deps.local.txt'
    'tests/.phpunit.result.cache'
    'tests/.phpunit.cache/'
    'tests/playwright/node_modules/'
    'tests/playwright/package-lock.json'
    'tests/playwright/playwright-report/'
    'tests/playwright/test-results/'
)
for entry in "${gitignore_entries[@]}"; do
    if [ -f "$gitignore" ] && grep -Fxq "$entry" "$gitignore"; then
        continue
    fi
    ensure_trailing_newline "$gitignore"
    printf '%s\n' "$entry" >> "$gitignore"
    echo "  appended to .gitignore: $entry"
done

cat <<NEXT

Scaffold complete. Next steps:

  cd "$plugin_dir"
  docker compose -f docker/docker-compose.yml up -d
  # see DEVELOPMENT.md for how to run PHPUnit / Playwright / Vitest
NEXT
