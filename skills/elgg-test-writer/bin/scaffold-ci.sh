#!/usr/bin/env bash
#
# scaffold-ci.sh — copy the per-plugin GitHub Actions workflows into a plugin repo.
#
# Usage:
#   scaffold-ci.sh [--plugin-dir=<path>] [--force]
#
# If --plugin-dir is omitted, the current working directory is used (must
# contain elgg-plugin.php or start.php).
#
# The script writes:
#   <plugin>/.github/workflows/tests.yml
#   <plugin>/.github/workflows/lint.yml
#
# Existing files are left alone unless --force is passed.
#
# The workflows in references/ci/ are copied verbatim — they resolve
# PLUGIN_ID at runtime from composer.json so no per-plugin substitution
# is needed at scaffold time. See references/ci/README.md for what they do.

set -euo pipefail

SCRIPT_PATH="$(readlink -f "$0")"
BIN_DIR="$(dirname "$SCRIPT_PATH")"
SKILL_ROOT="$(dirname "$BIN_DIR")"
REFERENCES="$SKILL_ROOT/references/ci"

die() { echo "error: $*" >&2; exit 1; }

plugin_dir=""
force=0

for arg in "$@"; do
    case "$arg" in
        --plugin-dir=*) plugin_dir="${arg#*=}" ;;
        --force)        force=1 ;;
        -h|--help)
            sed -n '3,18p' "$SCRIPT_PATH" | sed 's/^# \{0,1\}//'
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
if [ ! -f "$plugin_dir/elgg-plugin.php" ] && [ ! -f "$plugin_dir/start.php" ]; then
    die "not an Elgg plugin (no elgg-plugin.php or start.php): $plugin_dir"
fi

[ -d "$REFERENCES" ] || die "references/ci/ not found at $REFERENCES"

workflows_dir="$plugin_dir/.github/workflows"
mkdir -p "$workflows_dir"

echo "plugin_dir = $plugin_dir"
echo "references = $REFERENCES"

copy_if_missing() {
    local src="$1" dst="$2"
    if [ -e "$dst" ] && [ "$force" -ne 1 ]; then
        echo "  skip (exists): ${dst#$plugin_dir/}"
        return
    fi
    cp "$src" "$dst"
    echo "  wrote: ${dst#$plugin_dir/}"
}

for f in tests.yml lint.yml; do
    [ -f "$REFERENCES/$f" ] || die "reference workflow missing: $REFERENCES/$f"
    copy_if_missing "$REFERENCES/$f" "$workflows_dir/$f"
done

cat <<NEXT

CI scaffold complete. Next steps:

  cd "$plugin_dir"
  git add .github/workflows/
  git commit -m "ci: add PHPUnit, Playwright, and lint workflows"
  git push

The workflows trigger on push to main/master and on every PR. See
references/ci/README.md for what each job does and how to debug failures.
NEXT
