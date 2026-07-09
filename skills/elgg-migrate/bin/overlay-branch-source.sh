#!/usr/bin/env bash
# overlay-branch-source.sh — overlay plugin BRANCH source onto a running Elgg container.
#
# Why: a site whose Dockerfile installs composer-managed plugins from the PINNED
# TAGS in composer.lock will never see a fix that is committed to a migration
# branch but not yet tagged — rebuilding the image does not help. This script
# stages the working-tree source of every plugin repo that also exists in the
# container's mod/ directory, so a fix can be verified BEFORE a public tag is cut.
# Pair it with check-release-lag.sh, which detects the drift in the first place.
#
# This is a VERIFICATION tool. The ship path stays: cut a tag -> bump composer.lock
# -> rebuild the image.
#
# Safety: only ever touches /var/www/html/mod/<plugin>/ subdirectories, never mod/
# itself — some Elgg stacks bind-mount mod/ to the host plugin tree, and wiping it
# destroys the source. The container's own vendor/ dir inside each plugin is kept.
#
# Usage:
#   CONTAINER=myapp-1 overlay-branch-source.sh                  # all matching plugins
#   CONTAINER=myapp-1 overlay-branch-source.sh --only=hypegallery
#   CONTAINER=myapp-1 overlay-branch-source.sh --list            # dry inventory
#
# Env:
#   CONTAINER      target Elgg container (required)
#   PLUGINS_DIR    plugin source workspace (default: <script>/../plugins)
#   PLUGIN_BRANCH  branch each repo must be on (default: migrate/elgg-7.x)

set -euo pipefail

BDY_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
PLUGINS_DIR="${PLUGINS_DIR:-$BDY_ROOT/plugins}"
CONTAINER="${CONTAINER:-}"
[ -n "$CONTAINER" ] || { echo "ERROR: set CONTAINER to the target Elgg container" >&2; exit 2; }
BRANCH="${PLUGIN_BRANCH:-migrate/elgg-7.x}"
MOD_ROOT="/var/www/html/mod"

only=""
list_only=0
for arg in "$@"; do
  case "$arg" in
    --only=*) only="${arg#--only=}" ;;
    --list) list_only=1 ;;
    -h|--help) sed -n '2,/^set -euo/p' "$0" | sed 's/^# \?//' ; exit 0 ;;
    *) echo "Unknown arg: $arg" >&2; exit 2 ;;
  esac
done

docker inspect "$CONTAINER" >/dev/null 2>&1 || { echo "ERROR: container $CONTAINER not running" >&2; exit 1; }

# Plugin dirs that exist inside the container
mapfile -t IN_CONTAINER < <(docker exec "$CONTAINER" sh -c "ls $MOD_ROOT")

overlaid=0
skipped=0
for id in "${IN_CONTAINER[@]}"; do
  [ -n "$only" ] && [ "$id" != "$only" ] && continue
  src="$PLUGINS_DIR/$id"
  [ -d "$src/.git" ] || { skipped=$((skipped+1)); continue; }

  branch="$(git -C "$src" rev-parse --abbrev-ref HEAD)"
  if [ "$list_only" = "1" ]; then
    printf '%-28s %s\n' "$id" "$branch"
    continue
  fi
  # Some plugins never got a migrate/* branch; their default branch is the only
  # line of development. Refuse only when the expected branch exists and is unused.
  if [ "$branch" != "$BRANCH" ]; then
    if git -C "$src" rev-parse --verify -q "$BRANCH" >/dev/null; then
      echo "REFUSING $id: checked out on '$branch' but $BRANCH exists" >&2
      exit 3
    fi
    echo "NOTE $id: no $BRANCH branch, using '$branch'" >&2
  fi

  # Wipe the plugin dir contents except composer's vendor/, then untar the source.
  docker exec "$CONTAINER" sh -c \
    "find '$MOD_ROOT/$id' -mindepth 1 -maxdepth 1 ! -name vendor -exec rm -rf {} +"
  tar -C "$src" \
      --exclude=.git --exclude=node_modules --exclude=.github \
      -cf - . \
    | docker exec -i "$CONTAINER" tar -C "$MOD_ROOT/$id" -xf -

  overlaid=$((overlaid+1))
  echo "overlaid $id ($branch)"
done

[ "$list_only" = "1" ] && exit 0

if [ -n "$only" ] && [ "$overlaid" -eq 0 ]; then
  echo "ERROR: --only $only matched no container plugin dir with a git source" >&2
  exit 4
fi

# mod/.plugin-order.txt is a read-only bind mount; chown it and the whole call fails.
docker exec "$CONTAINER" sh -c \
  "find $MOD_ROOT -mindepth 1 -maxdepth 1 -type d -exec chown -R www-data:www-data {} +"
# Elgg caches compiled views + the plugin/class map; stale entries hide the overlay.
docker exec "$CONTAINER" sh -c 'rm -rf /var/data/elgg/views_simplecache/* /var/data/elgg/system_cache/* 2>/dev/null || true'
docker exec "$CONTAINER" sh -c 'cd /var/www/html && php elgg-cli flush 2>/dev/null || true'

echo "overlaid=$overlaid skipped=$skipped container=$CONTAINER" >&2
