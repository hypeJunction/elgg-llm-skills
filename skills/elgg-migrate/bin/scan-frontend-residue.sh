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

  # --- 1. AMD module loading in JS (require([...]) / define([...])) — removed ---
  grep -rnE '(^|[^A-Za-z0-9_.$])(require|define)[[:space:]]*\([[:space:]]*\[' \
    --include='*.js' --include='*.mjs' "$dir" 2>/dev/null \
    | grep -vE '/(vendor|vendors|bower_components|node_modules)/' \
    | grep -vE ':[0-9]+:[[:space:]]*(//|\*|#|/\*)' \
    | sed 's/^/[amd-require-js]   /' >> "$tmp"

  # AMD require([...]) embedded in PHP views (inline <script> strings)
  grep -rnE '(require|define)[[:space:]]*\([[:space:]]*\[' \
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

  # --- 4. Foundation / known 2.x front-end frameworks bundled in the theme ---
  grep -rilE 'foundation(\.min)?\.js|zurb|foundation\.(topbar|reveal|orbit)' \
    --include='*.js' --include='*.php' "$dir" 2>/dev/null \
    | sed "s|^|[foundation-fw]    |;s|$| (2.x frontend framework — replace or re-wrap as ESM)|" >> "$tmp"

  # --- 5. Global-jQuery classic scripts: a .js (not .mjs) that uses jQuery/$
  #         but has no ESM import/export — i.e. expects a window.jQuery global ---
  while IFS= read -r f; do
    case "$f" in */vendor/*|*/vendors/*|*/bower_components/*|*/node_modules/*|*.min.js) continue;; esac
    if grep -qE '(^|[^A-Za-z0-9_.$])(jQuery|\$)[[:space:]]*\(' "$f" 2>/dev/null \
       && ! grep -qE '^[[:space:]]*(import|export)[[:space:]]' "$f" 2>/dev/null; then
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

  # CRITICAL findings (removed APIs / non-functional on 7.x) drive the exit code;
  # [review-*] are surfaced but do not fail the gate.
  local n crit
  n=$(grep -c '' "$tmp" 2>/dev/null || echo 0)
  crit=$(grep -cvE '^\[review-' "$tmp" 2>/dev/null || echo 0)
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
