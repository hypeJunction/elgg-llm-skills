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
CORE_DIR="${2:-${ELGG_CORE_DIR:-}}"

# Core ships many of the resource views plugins name (groups/profile, groups/search,
# discussion/view...). Without core on the view path this check reports every one of
# them as missing: against the bodyology fleet it says 10 missing routes with no core
# dir and 5 with one. Half the findings would be false. Try to locate core, and if we
# cannot, say plainly that the result over-reports.
if [ -z "$CORE_DIR" ]; then
  for c in "$PLUGINS_DIR/../vendor/elgg/elgg" \
           "$PLUGINS_DIR/../*/vendor/elgg/elgg" \
           "$PLUGINS_DIR/../../vendor/elgg/elgg"; do
    for g in $c; do
      if [ -d "$g/views/default/resources" ]; then CORE_DIR="$g"; break 2; fi
    done
  done
  [ -n "$CORE_DIR" ] && echo "note: using discovered core views at $CORE_DIR" >&2
fi
if [ -z "$CORE_DIR" ]; then
  echo "WARNING: no core-dir given and none discovered. Resource views that CORE provides" >&2
  echo "         will be reported as missing — this run OVER-REPORTS. Pass the path to" >&2
  echo "         vendor/elgg/elgg, or set ELGG_CORE_DIR." >&2
fi

found=0

# A resource view may live under ANY viewtype, not only default. AJAX endpoints
# ship views/json/resources/<name>.php and are fetched with ?view=json (e.g.
# hypenotifications' ajax:notifications:ticker). Checking only views/default
# reported those as broken (bd elgg-migrate-ckn0c). Glob every viewtype.
has_resource_in() {  # $1 = a root dir (plugin/core), $2 = resource name
  local root="$1" resource="$2" vt
  for vt in "$root"/views/*/resources/"$resource".php; do
    [ -f "$vt" ] && return 0
  done
  return 1
}

resource_view_exists() {
  local plugin_root="$1" resource="$2"
  has_resource_in "$plugin_root" "$resource" && return 0
  # a plugin may legitimately point at another plugin's / core's resource view
  if [ -n "$CORE_DIR" ]; then
    has_resource_in "$CORE_DIR" "$resource" && return 0
    local m
    for m in "$CORE_DIR"/mod/*/; do
      has_resource_in "$m" "$resource" && return 0
    done
  fi
  local other
  for other in "$PLUGINS_DIR"/*/; do
    has_resource_in "$other" "$resource" && return 0
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
