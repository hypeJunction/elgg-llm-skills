#!/usr/bin/env bash
# verify-route-resources.sh — every route's `resource` must name a real resource view.
#
# Elgg resolves 'resource' => 'library/view' to the view resources/library/view.php.
# If that view does not exist, elgg_view_resource() raises ResourceNotFoundException
# and the route 404s. Nothing warns at boot; the plugin activates cleanly and the
# route simply never works.
#
# The shape that bites: a 2.x page handler ported as a catch-all
#
#     'library' => ['path' => '/library/{segments}', 'resource' => 'library']
#
# where the plugin only ever had resources/library/{all,view,edit}.php and the old
# handler switched on $page[0] itself. `resources/library.php` never existed, so
# EVERY /library/* URL 404s — including the permalink getURL() advertises. This is
# invisible to a route crawl (nothing links to a 404) and to activation checks.
#
# Found three instances on bodyology: bodyology_library, bodyology_feedback, feedback.
#
# Usage:
#   verify-route-resources.sh <plugins-dir> [core-dir]
#
# core-dir (optional): an Elgg checkout, so routes pointing at CORE resource views
#                      (e.g. 'resource' => 'blog/all') are not reported as missing.
set -euo pipefail

PLUGINS_DIR="${1:?usage: verify-route-resources.sh <plugins-dir> [core-dir]}"
CORE_DIR="${2:-}"

found=0

resource_view_exists() {
  local plugin_root="$1" resource="$2" rel="views/default/resources/${2}.php"
  [ -f "$plugin_root/$rel" ] && return 0
  # a plugin may legitimately point at another plugin's / core's resource view
  if [ -n "$CORE_DIR" ]; then
    [ -f "$CORE_DIR/$rel" ] && return 0
    [ -f "$CORE_DIR/views/default/resources/${resource}.php" ] && return 0
    local m
    for m in "$CORE_DIR"/mod/*/; do
      [ -f "$m$rel" ] && return 0
    done
  fi
  local other
  for other in "$PLUGINS_DIR"/*/; do
    [ -f "$other$rel" ] && return 0
  done
  return 1
}

for plugin in "$PLUGINS_DIR"/*/; do
  manifest="$plugin/elgg-plugin.php"
  [ -f "$manifest" ] || continue
  case "$plugin" in *－*|*/vendor/*) continue ;; esac

  # 'resource' => 'x/y'   and   elgg_register_route(... 'resource' => 'x/y' ...)
  while IFS= read -r line; do
    resource="$(printf '%s' "$line" | sed -E "s/.*'resource'[[:space:]]*=>[[:space:]]*'([^']+)'.*/\1/")"
    [ -n "$resource" ] || continue
    if ! resource_view_exists "${plugin%/}" "$resource"; then
      printf 'MISSING %s: route resource %-28s -> views/default/resources/%s.php not found\n' \
        "$(basename "${plugin%/}")" "'$resource'" "$resource"
      found=$((found+1))
    fi
  done < <(grep -rhoE "'resource'[[:space:]]*=>[[:space:]]*'[^']+'" \
             "$manifest" "${plugin}classes" "${plugin}lib" "${plugin}start.php" 2>/dev/null || true)
done

echo
if [ "$found" -gt 0 ]; then
  echo "$found route(s) point at a resource view that does not exist." >&2
  echo "Each one is a permanent 404 that no route crawl can see." >&2
  exit 1
fi
echo "route resources: clean"
