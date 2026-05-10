#!/usr/bin/env bash
# audit-plugin-docs.sh <plugin-path>
# Read-only docs audit for a single Elgg plugin directory.
# Exits non-zero when any issue is found (so it can gate CI).
#
# Requires: skills/elgg-migrate/config/plugin-docs.local.json
# Run setup-plugin-docs-config.sh first if the config is missing.
set -euo pipefail

PLUGIN_PATH="${1:?Usage: audit-plugin-docs.sh <plugin-path>}"
PLUGIN_PATH="$(realpath "$PLUGIN_PATH")"

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
    # Escape ERE metacharacters only (not spaces — ERE doesn't need that)
    return re.sub(r'([\.\+\*\?\[\]\(\)\{\}\|\^\$\\])', r'\\\1', s)
author_parts = [esc(n) for n in names] + [esc(e) for e in emails]
author_pattern = '|'.join(author_parts) if author_parts else '__NOMATCH__'

old_domain = cfg.get('domains', {}).get('old', '')

donate_list = cfg.get('donation_patterns', [])
donate_pattern = '|'.join(donate_list) if donate_list else '__NOMATCH__'

import json as _json
print(f'AUTHOR_GREP_PATTERN={_json.dumps(author_pattern)}')
print(f'OLD_DOMAIN={_json.dumps(old_domain)}')
print(f'DONATE_GREP_PATTERN={_json.dumps(donate_pattern)}')
PYEOF
)"

# ---- helpers -----------------------------------------------------------------
PLUGIN_ID="$(basename "$PLUGIN_PATH")"
issues=0
fail() { echo "  FAIL: $*"; ((issues++)) || true; }
ok()   { echo "  OK:   $*"; }

# Grep inside the plugin, excluding vendor/ node_modules/ .git/
pg() {
  grep -r --include="$1" "${@:2}" \
    --exclude-dir=vendor --exclude-dir=node_modules --exclude-dir=.git \
    "$PLUGIN_PATH" 2>/dev/null || true
}

echo "=== audit: $PLUGIN_ID ==="

# ---- 1. README present? ------------------------------------------------------
README="$(find "$PLUGIN_PATH" -maxdepth 1 -iname 'readme.md' | head -1)"
if [[ -z "$README" ]]; then
  fail "README.md missing"
else
  ok "README.md present"

  # ---- 2. Badge presence + count -------------------------------------------
  badge_count=$(grep -c 'img.shields.io/badge/Elgg' "$README" 2>/dev/null || true)
  if [[ "$badge_count" -eq 0 ]]; then
    fail "Elgg badge missing from README"
  elif [[ "$badge_count" -gt 1 ]]; then
    fail "Multiple Elgg badges ($badge_count) — keep only the current one"
  else
    ok "Elgg badge present (count=1)"
  fi

  # ---- 3. Badge version matches composer.json --------------------------------
  COMPOSER="$PLUGIN_PATH/composer.json"
  if [[ -f "$COMPOSER" ]]; then
    elgg_constraint="$(python3 -c "
import json, re
data = json.load(open('$COMPOSER'))
req = data.get('require', {}).get('elgg/elgg', '')
m = re.search(r'(\d+)', req)
print(m.group(1) if m else '')
" 2>/dev/null || true)"

    if [[ -n "$elgg_constraint" ]]; then
      if grep -q "Elgg-${elgg_constraint}" "$README" 2>/dev/null; then
        ok "Badge version matches composer.json (~${elgg_constraint}.x)"
      else
        fail "Badge version mismatch — expected ${elgg_constraint}.x"
      fi
    else
      fail "composer.json has no elgg/elgg constraint — cannot derive badge version"
    fi
  fi
  # ---- 3b. Compatibility section present ------------------------------------
  if grep -q '^## Compatibility' "$README" 2>/dev/null; then
    ok "## Compatibility section present"
  else
    fail "## Compatibility section missing from README"
  fi
fi

# ---- 4. composer.json checks -------------------------------------------------
COMPOSER="$PLUGIN_PATH/composer.json"
if [[ ! -f "$COMPOSER" ]]; then
  fail "composer.json missing"
else
  ok "composer.json present"

  desc="$(python3 -c "import json; d=json.load(open('$COMPOSER')); print(d.get('description',''))" 2>/dev/null || true)"
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

# ---- 5. manifest.xml checks --------------------------------------------------
MANIFEST="$PLUGIN_PATH/manifest.xml"
if [[ -f "$MANIFEST" ]]; then
  desc_val="$(python3 -c "
import xml.etree.ElementTree as ET
tree = ET.parse('$MANIFEST')
root = tree.getroot()
ns = (root.tag.split('}')[0] + '}') if root.tag.startswith('{') else ''
d = root.find(ns + 'description')
if d is None: d = root.find('description')
print((d.text or '').strip() if d is not None else '')
" 2>/dev/null || true)"
  if [[ -z "$desc_val" ]]; then
    fail "manifest.xml <description> is empty or missing"
  else
    ok "manifest.xml description present"
  fi
fi

# ---- 6. Old domain references ------------------------------------------------
if [[ -n "$OLD_DOMAIN" ]]; then
  domain_refs="$(pg "*.md" -l -i "$OLD_DOMAIN" || true)
$(pg "*.php" -l -i "$OLD_DOMAIN" || true)
$(pg "*.json" -l -i "$OLD_DOMAIN" || true)
$(pg "*.xml" -l -i "$OLD_DOMAIN" || true)"
  domain_refs="$(echo "$domain_refs" | sort -u | grep -v '^$' || true)"
  if [[ -n "$domain_refs" ]]; then
    fail "Old domain '$OLD_DOMAIN' references found in:"
    echo "$domain_refs" | while read -r f; do echo "      $f"; done
  else
    ok "No old-domain references"
  fi
fi

# ---- 7. Donation/sponsor CTAs ------------------------------------------------
donate_files="$(pg "*.md" -l -iE "$DONATE_GREP_PATTERN" || true)"
if [[ -n "$donate_files" ]]; then
  fail "Donation/sponsor CTAs found in:"
  echo "$donate_files" | while read -r f; do echo "      $f"; done
else
  ok "No donation/sponsor CTAs"
fi

# ---- 8. Author name / email references ---------------------------------------
if [[ "$AUTHOR_GREP_PATTERN" != "__NOMATCH__" ]]; then
  author_files="$(pg "*.md" -l -iE "$AUTHOR_GREP_PATTERN" || true)"
  if [[ -n "$author_files" ]]; then
    fail "Author name/email references found in:"
    echo "$author_files" | while read -r f; do echo "      $f"; done
  else
    ok "No author name/email references"
  fi
fi

# ---- summary -----------------------------------------------------------------
echo ""
if [[ "$issues" -gt 0 ]]; then
  echo "RESULT: $PLUGIN_ID — $issues issue(s) found"
  exit 1
else
  echo "RESULT: $PLUGIN_ID — clean"
  exit 0
fi
