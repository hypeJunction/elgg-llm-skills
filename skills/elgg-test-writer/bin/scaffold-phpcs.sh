#!/usr/bin/env bash
#
# scaffold-phpcs.sh — backfill phpcs (Elgg coding standard) into an
# existing per-plugin docker stack. Idempotent: re-running is a no-op.
#
# Usage:
#   scaffold-phpcs.sh [--plugin-dir=<path>]
#
# If --plugin-dir is omitted, the current working directory is used (must
# contain a docker/Dockerfile + docker/elgg-composer.json — i.e. the
# plugin already has a docker stack scaffolded).
#
# The script:
#   1. Adds squizlabs/php_codesniffer ^3.9 + elgg/sniffs dev-master to
#      docker/elgg-composer.json under require-dev (creates the section
#      if absent; leaves existing entries untouched).
#   2. Inserts a `RUN vendor/bin/phpcs --config-set installed_paths ...`
#      line into docker/Dockerfile after the composer install step, so
#      `phpcs --standard=Elgg` is registered at image build time.
#   3. Generates phpcs.xml at the plugin root with the Elgg standard and
#      all required exclude-patterns (vendor/, vendors/, tests/, etc.), so
#      `vendor/bin/phpcbf` picks them up automatically without CLI flags.
#
# All steps are idempotent — re-running is a no-op where content exists.
# The script reports each step as wrote/skipped.
#
# After running, rebuild the docker image and run phpcbf:
#   docker compose -f docker/docker-compose.yml build --no-cache elgg
#   docker compose -f docker/docker-compose.yml up -d
#   docker compose -f docker/docker-compose.yml exec elgg \
#     vendor/bin/phpcbf mod/<plugin-id>/

set -euo pipefail

SCRIPT_PATH="$(readlink -f "$0")"

die() { echo "error: $*" >&2; exit 1; }

plugin_dir=""

for arg in "$@"; do
    case "$arg" in
        --plugin-dir=*) plugin_dir="${arg#*=}" ;;
        -h|--help)
            sed -n '3,31p' "$SCRIPT_PATH" | sed 's/^# \{0,1\}//'
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

dockerfile="$plugin_dir/docker/Dockerfile"
composer_json="$plugin_dir/docker/elgg-composer.json"

[ -f "$dockerfile" ] || die "missing $dockerfile (run scaffold-docker.sh first)"
[ -f "$composer_json" ] || die "missing $composer_json (run scaffold-docker.sh first)"

command -v jq >/dev/null 2>&1 || die "jq is required but not installed"

echo "plugin_dir = $plugin_dir"

# Step 1: patch elgg-composer.json (require-dev) ----------------------------

needs_phpcs=0
needs_sniffs=0

if ! jq -e '.["require-dev"]["squizlabs/php_codesniffer"]' "$composer_json" >/dev/null 2>&1; then
    needs_phpcs=1
fi
if ! jq -e '.["require-dev"]["elgg/sniffs"]' "$composer_json" >/dev/null 2>&1; then
    needs_sniffs=1
fi

if [ "$needs_phpcs" -eq 1 ] || [ "$needs_sniffs" -eq 1 ]; then
    tmp="$(mktemp)"
    jq --indent 4 '
      (."require-dev" //= {})
      | ."require-dev"."squizlabs/php_codesniffer" //= "^3.9"
      | ."require-dev"."elgg/sniffs" //= "dev-master"
    ' "$composer_json" > "$tmp"
    mv "$tmp" "$composer_json"
    echo "  wrote: docker/elgg-composer.json (added phpcs deps to require-dev)"
else
    echo "  skip (phpcs deps already present): docker/elgg-composer.json"
fi

# Step 2: patch Dockerfile (RUN phpcs --config-set ...) ---------------------

phpcs_run='RUN vendor/bin/phpcs --config-set installed_paths /var/www/html/vendor/elgg/sniffs/src'

if grep -qF "$phpcs_run" "$dockerfile"; then
    echo "  skip (phpcs config-set already present): docker/Dockerfile"
else
    if ! grep -qE 'composer install' "$dockerfile"; then
        die "Dockerfile has no \`composer install\` step — cannot anchor phpcs insertion. Run scaffold-docker.sh first."
    fi
    tmp="$(mktemp)"
    awk -v marker='RUN cd /var/www/html && composer install' \
        -v block="\n# Register Elgg coding standard with phpcs\n${phpcs_run}" '
        { print }
        $0 ~ marker && !inserted { print block; inserted=1 }
    ' "$dockerfile" > "$tmp"

    if ! diff -q "$dockerfile" "$tmp" >/dev/null 2>&1; then
        mv "$tmp" "$dockerfile"
        echo "  wrote: docker/Dockerfile (added phpcs config-set step)"
    else
        rm -f "$tmp"
        die "could not locate composer-install line to anchor phpcs insertion in Dockerfile"
    fi
fi

# Step 3: generate phpcs.xml at plugin root -----------------------------------
#
# */vendors/* (trailing s) is required alongside */vendor/* — some legacy
# hypeJunction plugins ship 3rd-party libraries under vendors/ (e.g. WideImage
# in hypefilestore/hypegallery). Without it phpcbf rewrites those files.

phpcs_xml="$plugin_dir/phpcs.xml"
if [ -f "$phpcs_xml" ]; then
    echo "  skip (already present): phpcs.xml"
else
    cat > "$phpcs_xml" <<'XML'
<?xml version="1.0"?>
<ruleset name="Elgg plugin">
    <rule ref="Elgg"/>
    <exclude-pattern>*/vendor/*</exclude-pattern>
    <exclude-pattern>*/vendors/*</exclude-pattern>
    <exclude-pattern>*/tests/*</exclude-pattern>
    <exclude-pattern>*/node_modules/*</exclude-pattern>
    <exclude-pattern>*/docker/*</exclude-pattern>
</ruleset>
XML
    echo "  wrote: phpcs.xml"
fi

cat <<NEXT

phpcs scaffold complete. Next steps:

  cd "$plugin_dir"
  docker compose -f docker/docker-compose.yml build --no-cache elgg
  docker compose -f docker/docker-compose.yml up -d
  docker compose -f docker/docker-compose.yml exec elgg \\
    vendor/bin/phpcbf mod/\$(jq -r '.name' composer.json | sed 's|.*/||')/

  git add docker/Dockerfile docker/elgg-composer.json phpcs.xml
  git commit -m "style: add phpcs to docker stack and fix Elgg coding standard violations"
  git push

See ui_tabs commit 4621ee1 in the bodyology workspace for a worked example.
NEXT
