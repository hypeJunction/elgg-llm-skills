#!/usr/bin/env bash
# fix-plugin-docs.sh <plugin-path> [--apply]
# Semi-automatic docs fixer for a single Elgg plugin directory.
# Dry-run by default. Pass --apply to write changes.
#
# Requires: skills/elgg-migrate/config/plugin-docs.local.json
# Run setup-plugin-docs-config.sh first if the config is missing.
set -euo pipefail

PLUGIN_PATH="${1:?Usage: fix-plugin-docs.sh <plugin-path> [--apply]}"
PLUGIN_PATH="$(realpath "$PLUGIN_PATH")"
APPLY=false
if [[ "${2:-}" == "--apply" ]]; then APPLY=true; fi

if [[ ! -d "$PLUGIN_PATH" ]]; then
  echo "ERROR: directory not found: $PLUGIN_PATH" >&2
  exit 2
fi

# ---- Load config -------------------------------------------------------------
SCRIPT_DIR="$(dirname "$(realpath "$0")")"
SKILL_DIR="$(dirname "$SCRIPT_DIR")"
CONFIG_LOCAL="$SKILL_DIR/config/plugin-docs.local.json"
CONFIG_EXAMPLE="$SKILL_DIR/config/plugin-docs.example.json"

if [[ -f "$CONFIG_LOCAL" ]]; then
  CONFIG="$CONFIG_LOCAL"
elif [[ -f "$CONFIG_EXAMPLE" ]]; then
  echo "WARNING: Using example config — run setup-plugin-docs-config.sh to create your own." >&2
  CONFIG="$CONFIG_EXAMPLE"
else
  echo "ERROR: No config found. Run: $SKILL_DIR/bin/setup-plugin-docs-config.sh" >&2
  exit 1
fi

eval "$(python3 - "$CONFIG" << 'PYEOF'
import json, sys, re

cfg = json.load(open(sys.argv[1]))

author = cfg.get('author', {})
names  = author.get('names', [])
emails = author.get('emails', [])
def esc(s):
    return re.sub(r'([\.\+\*\?\[\]\(\)\{\}\|\^\$\\])', r'\\\1', s)
author_parts = [esc(n) for n in names] + [esc(e) for e in emails]
author_pattern = '|'.join(author_parts) if author_parts else '__NOMATCH__'

old_domain = cfg.get('domains', {}).get('old', '')
new_tpl    = cfg.get('domains', {}).get('new_url_template', '')

donate_list = cfg.get('donation_patterns', [])
donate_pattern = '|'.join(donate_list) if donate_list else '__NOMATCH__'

import json as _json
print(f'AUTHOR_GREP_PATTERN={_json.dumps(author_pattern)}')
print(f'OLD_DOMAIN={_json.dumps(old_domain)}')
print(f'NEW_URL_TPL={_json.dumps(new_tpl)}')
print(f'DONATE_GREP_PATTERN={_json.dumps(donate_pattern)}')
PYEOF
)"

# ---- helpers -----------------------------------------------------------------
PLUGIN_ID="$(basename "$PLUGIN_PATH")"
COMPOSER="$PLUGIN_PATH/composer.json"
README="$PLUGIN_PATH/README.md"

echo "=== fix: $PLUGIN_ID (apply=$APPLY) ==="

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

strip_lines() {
  local file="$1" pattern="$2"
  if [[ ! -f "$file" || "$pattern" == "__NOMATCH__" ]]; then return; fi
  if $APPLY; then
    local tmp tmp2
    tmp="$(mktemp)"; tmp2="$(mktemp)"
    grep -ivE "$pattern" "$file" > "$tmp" || true
    awk 'NF > 0 { blank=0; print } NF == 0 { if (!blank) print; blank=1 }' "$tmp" > "$tmp2"
    if diff -q "$file" "$tmp2" > /dev/null 2>&1; then
      echo "  no change: $file"
    else
      diff --unified=2 "$file" "$tmp2" || true
      mv "$tmp2" "$file"
      echo "  APPLIED: $file"
    fi
    rm -f "$tmp" "$tmp2"
  else
    local hits
    hits="$(grep -iE "$pattern" "$file" || true)"
    if [[ -n "$hits" ]]; then
      echo "  Would strip from $file:"
      echo "$hits" | sed 's/^/    /'
      echo "  (dry-run — pass --apply to write)"
    else
      echo "  no match: $file"
    fi
  fi
}

# ---- 1. Derive badge from composer.json --------------------------------------
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

# ---- 2. Replace old-domain URLs in README ------------------------------------
if [[ -f "$README" && -n "$OLD_DOMAIN" && -n "${repo_slug:-}" ]]; then
  echo ""
  echo "--- Replacing old-domain refs in README ---"
  new_url="${NEW_URL_TPL/\{repo_slug\}/$repo_slug}"
  # Escape the old domain for use in sed ERE
  escaped_domain="$(echo "$OLD_DOMAIN" | sed 's/\./\\./g')"
  apply_sed "$README" \
    -E "s|https?://(www\\.)?${escaped_domain}(/[^)\"' ]*)?|${new_url}|g"
fi

# ---- 3. Strip donation/sponsor CTAs from README ------------------------------
if [[ -f "$README" && "$DONATE_GREP_PATTERN" != "__NOMATCH__" ]]; then
  echo ""
  echo "--- Stripping donation/sponsor CTAs from README ---"
  strip_lines "$README" "$DONATE_GREP_PATTERN"
fi

# ---- 4. Strip author name / email references from README ---------------------
if [[ -f "$README" && "$AUTHOR_GREP_PATTERN" != "__NOMATCH__" ]]; then
  echo ""
  echo "--- Stripping author name/email from README ---"
  strip_lines "$README" "$AUTHOR_GREP_PATTERN"
fi

# ---- 5. Fix stale/duplicate Elgg badges in README ----------------------------
if [[ -f "$README" && -n "${elgg_constraint:-}" ]]; then
  echo ""
  echo "--- Fixing Elgg badge in README ---"
  badge_count=$(grep -c 'img.shields.io/badge/Elgg' "$README" 2>/dev/null || true)
  if [[ "$badge_count" -gt 1 ]]; then
    echo "  Multiple badges ($badge_count) — collapsing to single correct badge."
    if $APPLY; then
      tmp="$(mktemp)"
      first=true
      while IFS= read -r line; do
        if echo "$line" | grep -q 'img.shields.io/badge/Elgg'; then
          if $first; then
            echo "$correct_badge"
            first=false
          fi
        else
          echo "$line"
        fi
      done < "$README" > "$tmp"
      if diff -q "$README" "$tmp" > /dev/null 2>&1; then
        echo "  no change"; rm "$tmp"
      else
        diff --unified=2 "$README" "$tmp" || true
        mv "$tmp" "$README"
        echo "  APPLIED: $README"
      fi
    else
      echo "  (dry-run — pass --apply to write)"
    fi
  elif [[ "$badge_count" -eq 1 ]]; then
    echo "  Replacing existing badge."
    apply_sed "$README" \
      -E "s|!\[Elgg [^]]+\]\(https://img\.shields\.io/badge/Elgg-[^)]+\)|${correct_badge}|g"
  fi
fi

echo ""
echo "Done. Re-run audit-plugin-docs.sh to verify."
