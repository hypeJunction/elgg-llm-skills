#!/usr/bin/env bash
# fix-plugin-docs.sh <plugin-path> [--apply]
# Semi-automatic docs fixer for a single Elgg plugin directory.
#
# Dry-run by default (shows a diff). Pass --apply to write changes.
set -euo pipefail

PLUGIN_PATH="${1:?Usage: fix-plugin-docs.sh <plugin-path> [--apply]}"
PLUGIN_PATH="$(realpath "$PLUGIN_PATH")"
APPLY=false
if [[ "${2:-}" == "--apply" ]]; then APPLY=true; fi

if [[ ! -d "$PLUGIN_PATH" ]]; then
  echo "ERROR: directory not found: $PLUGIN_PATH" >&2
  exit 2
fi

PLUGIN_ID="$(basename "$PLUGIN_PATH")"
COMPOSER="$PLUGIN_PATH/composer.json"
README="$PLUGIN_PATH/README.md"

echo "=== fix: $PLUGIN_ID (apply=$APPLY) ==="

# ---- helpers ---------------------------------------------------------------
apply_sed() {
  local file="$1"; shift
  if [[ ! -f "$file" ]]; then return; fi
  local tmp
  tmp="$(mktemp)"
  sed "$@" "$file" > "$tmp"
  if diff -q "$file" "$tmp" > /dev/null 2>&1; then
    echo "  no change: $file"
    rm "$tmp"
    return
  fi
  diff --unified=2 "$file" "$tmp" || true
  if $APPLY; then
    mv "$tmp" "$file"
    echo "  APPLIED: $file"
  else
    rm "$tmp"
    echo "  (dry-run — pass --apply to write)"
  fi
}

# ---- 1. Derive badge from composer.json -------------------------------------
if [[ -f "$COMPOSER" ]]; then
  elgg_constraint="$(python3 -c "
import json, re
data = json.load(open('$COMPOSER'))
req = data.get('require', {}).get('elgg/elgg', '')
m = re.search(r'(\d+)[\.\d]*', req)
print(m.group(0).rstrip('.') if m else '')
" 2>/dev/null || true)"

  repo_slug="$(python3 -c "
import json
data = json.load(open('$COMPOSER'))
name = data.get('name', '')
print(name.split('/')[-1] if '/' in name else name)
" 2>/dev/null || true)"

  if [[ -n "$elgg_constraint" ]]; then
    correct_badge="![Elgg ${elgg_constraint}](https://img.shields.io/badge/Elgg-${elgg_constraint}-orange.svg?style=flat-square)"
    echo "  Derived badge: $correct_badge"
  fi
fi

# ---- 2. Replace hypejunction.com URLs in README ----------------------------
if [[ -f "$README" && -n "${repo_slug:-}" ]]; then
  echo ""
  echo "--- Replacing hypejunction.com refs in README ---"
  apply_sed "$README" \
    -E "s|https?://(www\.)?hypejunction\.com(/[^)\"' ]*)?|https://github.com/hypeJunction/${repo_slug}|g"
fi

# ---- 3. Strip donation/sponsor CTAs from README ----------------------------
if [[ -f "$README" ]]; then
  echo ""
  echo "--- Stripping donation/sponsor CTAs from README ---"
  # Remove lines matching known donate patterns; also remove surrounding blank lines
  # by collapsing multiple consecutive blank lines into one.
  if $APPLY; then
    tmp="$(mktemp)"
    # Remove matching lines
    grep -ivE 'paypal\.me|paypal.*cgi-bin|patreon\.com|ko-fi\.com|buymeacoffee|Support the development|Buy me a' \
      "$README" > "$tmp" || true
    # Collapse consecutive blank lines
    awk 'NF > 0 { blank=0; print } NF == 0 { if (!blank) print; blank=1 }' "$tmp" > "${tmp}.2"
    if diff -q "$README" "${tmp}.2" > /dev/null 2>&1; then
      echo "  no change: $README"
    else
      diff --unified=2 "$README" "${tmp}.2" || true
      mv "${tmp}.2" "$README"
      echo "  APPLIED: $README"
    fi
    rm -f "$tmp" "${tmp}.2"
  else
    has_donate="$(grep -iE 'paypal\.me|paypal.*cgi-bin|patreon\.com|ko-fi\.com|buymeacoffee|Support the development|Buy me a' "$README" || true)"
    if [[ -n "$has_donate" ]]; then
      echo "  Would strip these lines from $README:"
      echo "$has_donate" | sed 's/^/    /'
      echo "  (dry-run — pass --apply to write)"
    else
      echo "  no donation CTAs found in $README"
    fi
  fi
fi

# ---- 4. Fix stale/duplicate Elgg badges in README -------------------------
if [[ -f "$README" && -n "${elgg_constraint:-}" ]]; then
  echo ""
  echo "--- Fixing Elgg badge in README ---"
  badge_count=$(grep -c 'img.shields.io/badge/Elgg' "$README" 2>/dev/null || echo 0)
  if [[ "$badge_count" -gt 1 ]]; then
    echo "  Multiple badges found ($badge_count). Collapsing to single correct badge."
    if $APPLY; then
      tmp="$(mktemp)"
      first=true
      while IFS= read -r line; do
        if echo "$line" | grep -q 'img.shields.io/badge/Elgg'; then
          if $first; then
            echo "$correct_badge"
            first=false
          fi
          # Skip remaining badge lines
        else
          echo "$line"
        fi
      done < "$README" > "$tmp"
      if diff -q "$README" "$tmp" > /dev/null 2>&1; then
        echo "  no change"
        rm "$tmp"
      else
        diff --unified=2 "$README" "$tmp" || true
        mv "$tmp" "$README"
        echo "  APPLIED: $README"
      fi
    else
      echo "  (dry-run — pass --apply to write)"
    fi
  elif [[ "$badge_count" -eq 1 ]]; then
    echo "  Replacing existing badge with correct one."
    apply_sed "$README" \
      -E "s|!\[Elgg [^]]+\]\(https://img\.shields\.io/badge/Elgg-[^)]+\)|${correct_badge}|g"
  fi
fi

echo ""
echo "Done. Re-run audit-plugin-docs.sh to verify."
