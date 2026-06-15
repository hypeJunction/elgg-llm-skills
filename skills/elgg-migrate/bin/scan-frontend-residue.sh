#!/usr/bin/env bash
# scan-frontend-residue.sh — static gate for leftover Elgg 2.x/3.x-era FRONT-END
# code that survives a server-side migration. migrate.php --check is PHP-AST only,
# so a plugin can "render HTTP 200" while its client JS (AMD require()/define,
# global jQuery, Foundation, RequireJS-loaded modules) never runs on Elgg 7 —
# the page loads unstyled / non-interactive. This scanner greps the patterns that
# must be GONE by Elgg 7 and exits non-zero when any are found.
#
# Usage:
#   scan-frontend-residue.sh <plugin-dir> [<plugin-dir> ...]
#   scan-frontend-residue.sh --quiet <plugin-dir>     # summary line only
# Exit: 0 = clean, 1 = residue found, 2 = usage error.
set -u

QUIET=0
[ "${1:-}" = "--quiet" ] && { QUIET=1; shift; }
[ $# -ge 1 ] || { echo "usage: scan-frontend-residue.sh [--quiet] <plugin-dir> [...]" >&2; exit 2; }

# A finding's category -> human label. Each pattern is matched against the
# relevant file set; matches in vendored libraries (vendor/, vendors/, bower_,
# node_modules) are reported separately as they are usually third-party bundles
# the plugin must REPLACE or re-wrap, not edit line-by-line.
TOTAL=0

scan_one() {
  local dir="$1" name; name="$(basename "$dir")"
  [ -d "$dir" ] || { echo "  ! not a dir: $dir" >&2; return; }

  local tmp; tmp="$(mktemp)"

  # --- 1. AMD module loading in JS — removed. Matches both the array form
  #         require([...])/define([...]) AND the simplified-CommonJS wrapper
  #         define(function(){...}) / define(function(require){...}). ---
  grep -rnE '(^|[^A-Za-z0-9_.$])(require[[:space:]]*\([[:space:]]*\[|define[[:space:]]*\([[:space:]]*(\[|function))' \
    --include='*.js' --include='*.mjs' "$dir" 2>/dev/null \
    | grep -vE '/(vendor|vendors|bower_components|node_modules)/' \
    | grep -vE ':[0-9]+:[[:space:]]*(//|\*|#|/\*)' \
    | sed 's/^/[amd-require-js]   /' >> "$tmp"

  # AMD require([...]) / define(...) embedded in PHP views (inline <script>)
  grep -rnE '(require[[:space:]]*\([[:space:]]*\[|define[[:space:]]*\([[:space:]]*(\[|function))' \
    --include='*.php' "$dir/views" 2>/dev/null \
    | grep -vE '/(vendor|vendors|bower_components|node_modules)/' \
    | grep -vE ':[0-9]+:[[:space:]]*(//|\*|#|/\*)' \
    | sed 's/^/[amd-require-php]  /' >> "$tmp"

  # --- 2. Removed AMD PHP API (RequireJS registration) — elgg_require_js,
  #         elgg_define_js, elgg_unrequire_js, elgg_register_js all removed ---
  grep -rnE 'elgg_(require|define|unrequire|register)_js[[:space:]]*\(' \
    --include='*.php' "$dir" 2>/dev/null \
    | grep -vE '/(vendor|vendors|bower_components|node_modules)/' \
    | grep -vE ':[0-9]+:[[:space:]]*(//|\*|#|/\*)' \
    | sed 's/^/[removed-amd-api]  /' >> "$tmp"

  # --- 4. (REVIEW) Foundation / 2.x front-end frameworks referenced by the
  #         plugin's OWN code (vendored library files excluded). Not a hard
  #         breaker on its own — Foundation can keep working IF the plugin
  #         provides a window.jQuery global (ESM shim) and loads it correctly —
  #         but it MUST be verified in a browser, so it is surfaced for review. ---
  grep -rilE 'zurb|[Ff]oundation\.(topbar|reveal|orbit|libs|init)|elgg_(load|register)_external_file[^)]*foundation' \
    --include='*.js' --include='*.php' "$dir" 2>/dev/null \
    | grep -vE '/(vendor|vendors|bower_components|node_modules)/' \
    | sed "s|^|[review-foundation] |;s|$| (uses Foundation — verify a window.jQuery global is provided + nav/grid render in a browser)|" >> "$tmp"

  # --- 5/5b. Per-.js-file classification (skip vendored / minified) ---
  #   5  global-jquery : classic .js using jQuery/$ with no ESM import (needs window.jQuery)
  #   5b esm-wrong-ext : .js file written as an ES module (import/export). Elgg's
  #      ESMService only registers *.mjs views in the importmap, so an import-using
  #      .js never loads (the agent must rename it to .mjs and re-point the loader).
  while IFS= read -r f; do
    case "$f" in */vendor/*|*/vendors/*|*/bower_components/*|*/node_modules/*|*.min.js) continue;; esac
    if grep -qE '^[[:space:]]*(import|export)[[:space:]]' "$f" 2>/dev/null; then
      echo "[esm-wrong-ext]    $f:1: ES module saved as .js — Elgg only registers .mjs in the importmap, so this never loads. Rename to .mjs + repoint elgg_import_esm." >> "$tmp"
    elif grep -qE '(^|[^A-Za-z0-9_.$])(jQuery|\$)[[:space:]]*\(' "$f" 2>/dev/null; then
      echo "[global-jquery]    $f:1: classic script uses jQuery/\$ with no ESM import (needs window.jQuery — removed in Elgg 7)" >> "$tmp"
    fi
  done < <(find "$dir" -name '*.js' 2>/dev/null)

  # --- 6. (REVIEW, non-failing) classic external JS — elgg_load/register_external_file
  #         still EXISTS in 7.x, but is commonly used to load global-jQuery libs
  #         (jquery.*, foundation, etc.) that no longer have a jQuery global ---
  grep -rnE "elgg_(load|register)_external_file[[:space:]]*\([[:space:]]*['\"]js" \
    --include='*.php' "$dir" 2>/dev/null \
    | grep -vE '/(vendor|vendors|bower_components|node_modules)/' \
    | grep -vE ':[0-9]+:[[:space:]]*(//|\*|#|/\*)' \
    | sed 's/^/[review-external]  /' >> "$tmp"

  # --- 7. Orphaned CSS-view overrides (Elgg 7 css/ -> name.css relocation).
  #   Elgg 7 moved cacheable CSS/JS views OUT of css/ and js/ dirs: a view that
  #   was `css/elements/layout` is now `elements/layout.css`, and core.css.php
  #   aggregates `elements/*.css`. A plugin/theme that overrides core element
  #   views at the OLD path views/default/css/elements/*.css is therefore SILENTLY
  #   ORPHANED on Elgg 7 — core's defaults win and the override never loads, so the
  #   UI renders unstyled while pages stay HTTP 200 (exactly how a theme can
  #   silently lose its header-overlay/nav/fonts past every server-side gate). Flag old-path
  #   element overrides that lack a relocated twin at views/default/elements/<name>.
  #   Gated to plugins targeting Elgg 7 (composer elgg/elgg ~7|^7) to avoid
  #   false-positives on 2.x–6.x where css/elements/* is the correct path.
  if grep -qE '"elgg/elgg"[[:space:]]*:[[:space:]]*"[~^]?7' "$dir/composer.json" 2>/dev/null \
     && [ -d "$dir/views/default/css/elements" ]; then
    for f in "$dir"/views/default/css/elements/*.css; do
      [ -e "$f" ] || continue
      base="$(basename "$f")"; stem="${base%.css}"
      # OK if a relocated twin exists at views/default/elements/<name>, OR the
      # view is loaded explicitly (elgg_extend_view / external_file referencing
      # 'css/elements/<stem>'). Only a file with NEITHER is truly orphaned.
      [ -e "$dir/views/default/elements/$base" ] && continue
      if grep -rqE "css/elements/$stem" --include='*.php' "$dir" 2>/dev/null; then
        continue
      fi
      echo "[css-view-orphaned] $f:1: Elgg 7 aggregates elements/$base (not css/elements/$base) — this core-view override has no relocated twin and is not loaded explicitly, so it never loads on Elgg 7. Relocate to views/default/elements/$base, or load it via elgg_extend_view('elgg.css', 'css/elements/$stem', 100)." >> "$tmp"
    done
  fi

  # --- 8. Dead 2.x search-results pattern (Elgg 3.0 search rewrite).
  #   Elgg 3.0 removed the search_*_hook functions and made search query-based:
  #   the 'search' hook/event no longer returns an ['entities' => ...] array, it
  #   modifies the query. Code that does
  #     $r = elgg_trigger_plugin_hook('search', $t, ...);  // or _event_results
  #     $entities = elgg_extract('entities', $r);
  #     elgg_view_entity_list($entities, ...);             // null -> TypeError
  #   has been broken since 2.x->3.0 and fatals (or silently returns nothing) on
  #   3.x..7.x. Replace with elgg_search($options) / elgg_list_entities($options,
  #   'elgg_search'). NOT a 7.x-only fix — belongs on the migrate/elgg-3.x branch
  #   and forward-merges. (Found in object_sort, hypefolders, elgg_tokeninput.)
  grep -rlnE "elgg_trigger_(plugin_hook|event_results)\(\s*['\"]search['\"]" \
    --include='*.php' "$dir" 2>/dev/null \
    | grep -vE '/(vendor|vendors|node_modules)/' \
    | while IFS= read -r f; do
        # only flag when the result feeds 'entities' extraction (the breaking use)
        if grep -qE "elgg_extract\(\s*['\"]entities['\"]" "$f" 2>/dev/null; then
          ln=$(grep -nE "elgg_trigger_(plugin_hook|event_results)\(\s*['\"]search['\"]" "$f" | head -1 | cut -d: -f1)
          echo "[dead-search-event] $f:${ln:-1}: 2.x 'search' hook/event returning ['entities'] — removed by the Elgg 3.0 search rewrite. Returns null/no results now. Use elgg_search(\$options) or elgg_list_entities(\$options, 'elgg_search'). Fix belongs at migrate/elgg-3.x and forward-merges." >> "$tmp"
        fi
      done

  # CRITICAL findings (removed APIs / non-functional on 7.x) drive the exit code;
  # [review-*] are surfaced but do not fail the gate.
  # grep -c always prints a count (0 on no match) even when it exits 1 on an
  # empty file — do NOT append `|| echo 0` (that yields "0\n0" -> arithmetic error).
  local n crit
  n=$(grep -c '' "$tmp" 2>/dev/null); n=${n:-0}
  crit=$(grep -cvE '^\[review-' "$tmp" 2>/dev/null); crit=${crit:-0}
  [ -s "$tmp" ] || crit=0
  TOTAL=$((TOTAL + crit))

  if [ "$n" -gt 0 ]; then
    local breakdown
    breakdown=$(grep -oE '^\[[a-z-]+\]' "$tmp" | sort | uniq -c | awk '{printf "%s:%s ", $2, $1}')
    printf '%-32s crit=%-2d (of %d)  %s\n' "$name" "$crit" "$n" "$breakdown"
    [ "$QUIET" -eq 1 ] || sed 's/^/    /' "$tmp"
  else
    [ "$QUIET" -eq 1 ] || printf '%-32s crit=0  (clean)\n' "$name"
  fi
  rm -f "$tmp"
}

for d in "$@"; do scan_one "$d"; done
echo "── total CRITICAL frontend residue findings: $TOTAL ──"
[ "$TOTAL" -eq 0 ]
