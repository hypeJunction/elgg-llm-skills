#!/usr/bin/env bash
# audit-plugin-docs.sh <plugin-path>
# Read-only docs audit for a single Elgg plugin directory.
# Exits non-zero when any issue is found (so it can gate CI).
set -euo pipefail

PLUGIN_PATH="${1:?Usage: audit-plugin-docs.sh <plugin-path>}"
PLUGIN_PATH="$(realpath "$PLUGIN_PATH")"

if [[ ! -d "$PLUGIN_PATH" ]]; then
  echo "ERROR: directory not found: $PLUGIN_PATH" >&2
  exit 2
fi

PLUGIN_ID="$(basename "$PLUGIN_PATH")"
issues=0

# ---- helpers ---------------------------------------------------------------
fail() { echo "  FAIL: $*"; ((issues++)) || true; }
ok()   { echo "  OK:   $*"; }

# Grep inside the plugin, excluding vendor/ node_modules/ .git/
pg() {
  grep -r --include="$1" "${@:2}" \
    --exclude-dir=vendor --exclude-dir=node_modules --exclude-dir=.git \
    "$PLUGIN_PATH" 2>/dev/null || true
}

echo "=== audit: $PLUGIN_ID ==="

# ---- 1. README present? ----------------------------------------------------
# Case-insensitive search for README on case-sensitive filesystems.
README="$(find "$PLUGIN_PATH" -maxdepth 1 -iname 'readme.md' | head -1)"
if [[ -z "$README" ]]; then
  fail "README.md missing"
else
  ok "README.md present ($README)"

  # ---- 2. Badge presence + staleness ----------------------------------------
  badge_count=$(grep -c 'img.shields.io/badge/Elgg' "$README" 2>/dev/null || echo 0)
  if [[ "$badge_count" -eq 0 ]]; then
    fail "Elgg badge missing from README"
  elif [[ "$badge_count" -gt 1 ]]; then
    fail "Multiple Elgg badges ($badge_count) — should keep only the current one"
  else
    ok "Elgg badge present (count=1)"
  fi

  # Derive the expected version from composer.json (elgg/elgg constraint).
  COMPOSER="$PLUGIN_PATH/composer.json"
  if [[ -f "$COMPOSER" ]]; then
    # Extract e.g. "^5.0" → "5" for a rough major-version check.
    elgg_constraint="$(python3 -c "
import json, sys, re
data = json.load(open('$COMPOSER'))
req = data.get('require', {}).get('elgg/elgg', '')
m = re.search(r'(\d+)', req)
print(m.group(1) if m else '')
" 2>/dev/null || true)"

    if [[ -n "$elgg_constraint" ]]; then
      if grep -q "Elgg-${elgg_constraint}" "$README" 2>/dev/null; then
        ok "Badge version matches composer.json constraint (~${elgg_constraint}.x)"
      else
        fail "Badge version does not match elgg/elgg constraint (expected ${elgg_constraint}.x)"
      fi
    else
      fail "composer.json has no elgg/elgg constraint — cannot derive badge version"
    fi
  fi
fi

# ---- 3. composer.json checks -----------------------------------------------
COMPOSER="$PLUGIN_PATH/composer.json"
if [[ ! -f "$COMPOSER" ]]; then
  fail "composer.json missing"
else
  ok "composer.json present"

  desc="$(python3 -c "import json,sys; d=json.load(open('$COMPOSER')); print(d.get('description',''))" 2>/dev/null || true)"
  if [[ -z "$desc" ]]; then
    fail "composer.json 'description' is empty"
  else
    ok "composer.json description: '$desc'"
  fi

  has_elgg_req="$(python3 -c "import json; d=json.load(open('$COMPOSER')); print('yes' if 'elgg/elgg' in d.get('require',{}) else 'no')" 2>/dev/null || true)"
  if [[ "$has_elgg_req" != "yes" ]]; then
    fail "composer.json missing 'elgg/elgg' in require"
  else
    ok "composer.json has elgg/elgg constraint"
  fi
fi

# ---- 4. manifest.xml checks ------------------------------------------------
MANIFEST="$PLUGIN_PATH/manifest.xml"
if [[ -f "$MANIFEST" ]]; then
  desc_val="$(python3 -c "
import xml.etree.ElementTree as ET, sys
tree = ET.parse('$MANIFEST')
d = tree.find('description')
print((d.text or '').strip() if d is not None else '')
" 2>/dev/null || true)"
  if [[ -z "$desc_val" ]]; then
    fail "manifest.xml <description> is empty or missing"
  else
    ok "manifest.xml description present"
  fi
fi

# ---- 5. hypejunction.com references ----------------------------------------
hyp_refs="$(pg "*.md" -l -i 'hypejunction\.com' || true)
$(pg "*.php" -l -i 'hypejunction\.com' || true)
$(pg "*.json" -l -i 'hypejunction\.com' || true)"
hyp_refs="$(echo "$hyp_refs" | sort -u | grep -v '^$' || true)"
if [[ -n "$hyp_refs" ]]; then
  fail "hypejunction.com references found in:"
  echo "$hyp_refs" | while read -r f; do echo "      $f"; done
else
  ok "No hypejunction.com references"
fi

# ---- 6. Donation/sponsor CTAs ----------------------------------------------
donate_pattern='paypal\.me|paypal.*cgi-bin|patreon\.com|ko-fi\.com|buymeacoffee|Support the development|Buy me a'
donate_files="$(pg "*.md" -l -iE "$donate_pattern" || true)"
if [[ -n "$donate_files" ]]; then
  fail "Donation/sponsor CTAs found in:"
  echo "$donate_files" | while read -r f; do echo "      $f"; done
else
  ok "No donation/sponsor CTAs"
fi

# ---- summary ----------------------------------------------------------------
echo ""
if [[ "$issues" -gt 0 ]]; then
  echo "RESULT: $PLUGIN_ID — $issues issue(s) found"
  exit 1
else
  echo "RESULT: $PLUGIN_ID — clean"
  exit 0
fi
