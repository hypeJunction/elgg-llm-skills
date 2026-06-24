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

# Normalize a PHP parameter string to a comma-joined list of "type $name" tokens
# with default values stripped, so an override's arity+types can be compared
# against a canonical core signature regardless of default values / spacing.
norm_params() {
  local in="$1" out="" p
  in="${in//$'\n'/ }"
  local IFS=,
  for p in $in; do
    p="${p%%=*}"                                   # drop "= default"
    p="$(printf '%s' "$p" | sed -E 's/^[[:space:]]+//; s/[[:space:]]+$//; s/[[:space:]]+/ /g')"
    [ -n "$p" ] || continue
    out="${out:+$out, }$p"
  done
  printf '%s' "$out"
}

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

  # --- 2b. Vendored JS lib registered via a '.js' (NOT '.mjs') 'views' key, or
  #         elgg_register_esm() routed through elgg_get_simplecache_url('*.js').
  #         Elgg 7's ESMService adds only *.mjs views to the importmap, so neither
  #         form produces an importmap entry — a bare `import '<spec>'` then fails
  #         to resolve at runtime (the whole module aborts; the page still 200s).
  #         Fix: elgg_register_esm('<spec>', elgg_normalize_url('mod/<p>/vendors/<lib>.js'))
  #         + vendor the file under vendors/ + expose window.jQuery for UMD plugins. ---
  grep -rnE "['\"][A-Za-z0-9_./-]+\.js['\"][[:space:]]*=>[[:space:]]*.*(vendor|vendors|/dist/|\.min\.js)" \
    --include='elgg-plugin.php' "$dir" 2>/dev/null \
    | grep -vE ':[0-9]+:[[:space:]]*(//|\*|#|/\*)' \
    | sed 's/^/[esm-js-views-reg]   /' >> "$tmp"
  grep -rnE 'elgg_register_esm\([^)]*elgg_get_simplecache_url\([^)]*\.js' \
    --include='*.php' "$dir" 2>/dev/null \
    | grep -vE '/(vendor|vendors|bower_components|node_modules)/' \
    | grep -vE ':[0-9]+:[[:space:]]*(//|\*|#|/\*)' \
    | sed 's/^/[esm-simplecache-js] /' >> "$tmp"

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

  # --- 9. Null/undefined title passed to a typed-string title param (Elgg 7).
  #   Elgg 7 typed both elgg_view_page(string $title, ...) AND
  #   elgg_view_module(string $name, string $title, ...). A view that passes a
  #   $var NEVER assigned in the file sends null -> TypeError -> 500. Lenient
  #   pre-7.x (empty title, HTTP 200), HARD FATAL on 7.x, and only when the page
  #   actually renders (often AUTHENTICATED) so it slips past activation +
  #   homepage render. Covers elgg_view_page(<var> as 1st arg) and
  #   elgg_view_module(<x>, <var> as 2nd arg). Pass a real string (entity/owner/
  #   collection display name, or elgg_echo('...')).
  null_title_var_unassigned() { # $1=file $2=var(with $)
    local f="$1" name="${2#\$}"
    grep -qE "\\\$${name}[[:space:]]*=" "$f" 2>/dev/null && return 1
    grep -qE "as[[:space:]]+\\\$${name}\b|function[^)]*\\\$${name}\b|\\\$${name}[[:space:]]*=>" "$f" 2>/dev/null && return 1
    return 0
  }
  for f in $(grep -rlE 'elgg_view_(page|module)\(' "$dir/views" --include='*.php' 2>/dev/null | grep -vE '/(vendor|vendors|node_modules)/'); do
    # elgg_view_page(<var>, ...)  — title is the 1st arg
    var=$(grep -oE 'elgg_view_page\(\$[a-zA-Z_][a-zA-Z0-9_]*' "$f" | grep -oE '\$[a-zA-Z_][a-zA-Z0-9_]*' | head -1)
    if [ -n "$var" ] && null_title_var_unassigned "$f" "$var"; then
      ln=$(grep -nF "elgg_view_page($var" "$f" | head -1 | cut -d: -f1)
      echo "[viewpage-null-title] $f:${ln:-1}: elgg_view_page($var, ...) but $var is never assigned in this file -> null title -> TypeError on Elgg 7's strict elgg_view_page(string \$title). Pass a real string." >> "$tmp"
    fi
    # elgg_view_module(<name>, <var>, ...) — title is the 2nd arg
    mvar=$(grep -oE "elgg_view_module\([^,]+,[[:space:]]*\\\$[a-zA-Z_][a-zA-Z0-9_]*" "$f" | grep -oE '\$[a-zA-Z_][a-zA-Z0-9_]*' | head -1)
    if [ -n "$mvar" ] && null_title_var_unassigned "$f" "$mvar"; then
      ln=$(grep -nE "elgg_view_module\([^,]+,[[:space:]]*\\${mvar}" "$f" | head -1 | cut -d: -f1)
      echo "[viewmodule-null-title] $f:${ln:-1}: elgg_view_module(..., $mvar, ...) but $mvar is never assigned in this file -> null title -> TypeError on Elgg 7's strict elgg_view_module(string \$name, string \$title). Pass a real string." >> "$tmp"
    fi
    # literal null in the title position: elgg_view_page(null, ...) / elgg_view_module(<x>, null, ...)
    if grep -qE "elgg_view_page\([[:space:]]*null\b" "$f" 2>/dev/null; then
      ln=$(grep -nE "elgg_view_page\([[:space:]]*null\b" "$f" | head -1 | cut -d: -f1)
      echo "[viewpage-null-title] $f:${ln:-1}: elgg_view_page(null, ...) — literal null to Elgg 7's strict string \$title -> TypeError. Pass a real string." >> "$tmp"
    fi
    if grep -qE "elgg_view_module\([^,]+,[[:space:]]*null\b" "$f" 2>/dev/null; then
      ln=$(grep -nE "elgg_view_module\([^,]+,[[:space:]]*null\b" "$f" | head -1 | cut -d: -f1)
      echo "[viewmodule-null-title] $f:${ln:-1}: elgg_view_module(..., null, ...) — literal null to Elgg 7's strict string \$title -> TypeError. Pass a real string (or '')." >> "$tmp"
    fi
  done

  # --- 10. Legacy 2.x language file format (add_translation() removed in Elgg 5.0).
  #   2.x lang files did:  $lang = array(...); add_translation('xx', $lang);
  #   add_translation() was removed in Elgg 5.0 -> Call to undefined function at
  #   BOOT for that language (often surfaces only when a non-default locale loads,
  #   e.g. a user with fr/es). 3.x+ lang files must `return [ ... ];`.
  # Comment-aware: a docblock/comment that merely *mentions* add_translation()
  # ("no add_translation()") is not a call. Match the call, drop comment lines.
  grep -rnE '(^|[^A-Za-z_])add_translation[[:space:]]*\(' "$dir/languages" --include='*.php' 2>/dev/null \
    | grep -vE '/(vendor|vendors|node_modules)/' \
    | grep -vE ':[0-9]+:[[:space:]]*(//|\*|#|/\*)' \
    | awk -F: '!seen[$1]++ {print $1":"$2}' \
    | while IFS=: read -r f ln; do
        echo "[legacy-language-file] $f:${ln:-1}: uses add_translation() — removed in Elgg 5.0. Convert to a top-level 'return [ ... ];' array. Fatals at boot when this locale loads." >> "$tmp"
      done

  # CRITICAL findings (removed APIs / non-functional on 7.x) drive the exit code;
  # [review-*] are surfaced but do not fail the gate.
  # grep -c always prints a count (0 on no match) even when it exits 1 on an
  # empty file — do NOT append `|| echo 0` (that yields "0\n0" -> arithmetic error).
  # ── [signature-incompat] — a plugin class overrides a core ElggEntity method
  # with an incompatible signature (wrong arity / missing types). Elgg 7.0 typed
  # these core methods; a 2.x-era untyped or extra-arg override fatals at class
  # load with "Declaration of X::m() must be compatible with ElggY::m()".
  # bug-007 (Comment::canComment + $default), bug-013 (canComment), bug-017-adjacent.
  if grep -qE '"elgg/elgg"[[:space:]]*:[[:space:]]*"[~^]?7' "$dir/composer.json" 2>/dev/null; then
    declare -A CORE_SIG
    CORE_SIG[canComment]='int $user_guid'
    CORE_SIG[canWriteToContainer]='int $user_guid, string $type, string $subtype'
    CORE_SIG[canEdit]='int $user_guid'
    CORE_SIG[canDelete]='int $user_guid'
    CORE_SIG[canAnnotate]='int $user_guid, string $annotation_name'
    while IFS= read -r f; do
      case "$f" in */vendor/*|*/vendors/*) continue;; esac
      grep -qE 'extends[[:space:]]+\\?(ElggEntity|ElggObject|ElggComment|ElggGroup|ElggUser|ElggSite)\b' "$f" 2>/dev/null || continue
      for m in canComment canWriteToContainer canEdit canDelete canAnnotate; do
        local decl ln raw params norm want
        decl=$(grep -nE "function[[:space:]]+${m}[[:space:]]*\(" "$f" 2>/dev/null | head -1)
        [ -n "$decl" ] || continue
        ln=${decl%%:*}
        raw=${decl#*:}
        params=$(printf '%s' "$raw" | sed -E "s/.*function[[:space:]]+${m}[[:space:]]*\(//; s/\).*//")
        norm=$(norm_params "$params")
        want="${CORE_SIG[$m]}"
        if [ "$norm" != "$want" ]; then
          echo "[signature-incompat] $f:$ln: ${m}(${norm}) override is incompatible with Elgg 7 core ${m}(${want} = …): bool — fatals at class load. Match the core signature (types + arity)." >> "$tmp"
        fi
      done
    done < <(find "$dir/classes" -name '*.php' 2>/dev/null)
  fi

  # ── [removed-instance-method] — calls to ElggPlugin instance methods deleted
  # in 4.x/5.x. getManifest() is gone; $plugin->get/setUserSetting() moved to the
  # procedural elgg_get_plugin_user_setting() family. Fatals: Call to undefined method.
  grep -rnE --include='*.php' -e '->getManifest[[:space:]]*\(|\$plugin->(get|set)UserSetting[[:space:]]*\(' \
    "$dir" 2>/dev/null \
    | grep -vE '/(vendor|vendors|bower_components|node_modules)/' \
    | grep -vE ':[0-9]+:[[:space:]]*(//|\*|#|/\*)' \
    | sed 's/^/[removed-instance-method] /;s/$/ -- removed ElggPlugin method (getManifest\/getUserSetting); use elgg_get_plugin_from_id()->getDisplayName() or elgg_get_plugin_user_setting()/' >> "$tmp"

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
