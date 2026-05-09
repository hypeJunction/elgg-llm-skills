#!/usr/bin/env bash
# setup-plugin-docs-config.sh
# Interactively creates skills/elgg-migrate/config/plugin-docs.local.json (gitignored).
# Re-run at any time to overwrite the existing config.
set -euo pipefail

SCRIPT_DIR="$(dirname "$(realpath "$0")")"
SKILL_DIR="$(dirname "$SCRIPT_DIR")"
CONFIG_OUT="$SKILL_DIR/config/plugin-docs.local.json"
CONFIG_EXAMPLE="$SKILL_DIR/config/plugin-docs.example.json"

mkdir -p "$SKILL_DIR/config"

echo "=== Plugin Docs Cleanup — Config Setup ==="
echo "Output: $CONFIG_OUT  (gitignored)"
echo ""
echo "This config tells the audit/fix scripts what author info, domains,"
echo "and CTAs to remove. It is never committed."
echo ""

if [[ -f "$CONFIG_OUT" ]]; then
  echo "Existing config found. Press Enter to keep a value, or type a new one."
  existing() { python3 -c "import json,sys; d=json.load(open('$CONFIG_OUT')); print(eval('d'+sys.argv[1]))" "$1" 2>/dev/null || true; }
else
  existing() { echo ""; }
fi

prompt() {
  local key="$1" label="$2" default
  default="$(existing "$key")"
  if [[ -n "$default" ]]; then
    read -rp "$label [$default]: " val
    echo "${val:-$default}"
  else
    read -rp "$label: " val
    echo "$val"
  fi
}

echo "--- Author identity to remove from READMEs and metadata ---"
AUTHOR_FULL="$(prompt "['author']['names'][0]" "Author full name (e.g. 'Jane Doe')")"
AUTHOR_SHORT="$(prompt "['author']['names'][1]" "Author short name / alias (e.g. 'Jane')")"
AUTHOR_EMAILS="$(prompt "['author']['emails'][0]" "Author email(s), comma-separated")"

echo ""
echo "--- Old domain and replacement ---"
OLD_DOMAIN="$(prompt "['domains']['old']" "Old domain to replace (e.g. 'myplugins.com')")"
GITHUB_ORG="$(prompt "['github_org']" "GitHub org (e.g. 'my-org')")"
NEW_TPL="https://github.com/${GITHUB_ORG}/{repo_slug}"

echo ""
echo "Donation/CTA patterns: using defaults (paypal, patreon, ko-fi, buymeacoffee, 'Support the development', 'Buy me a')."
echo "Edit $CONFIG_OUT manually to customize."
echo ""

python3 - "$CONFIG_OUT" \
  "$AUTHOR_FULL" "$AUTHOR_SHORT" "$AUTHOR_EMAILS" \
  "$OLD_DOMAIN" "$GITHUB_ORG" "$NEW_TPL" << 'PYEOF'
import json, sys

out_path  = sys.argv[1]
name_full = sys.argv[2].strip()
name_short= sys.argv[3].strip()
emails_raw= sys.argv[4]
old_domain= sys.argv[5].strip()
github_org= sys.argv[6].strip()
new_tpl   = sys.argv[7].strip()

names  = [n for n in [name_full, name_short] if n]
emails = [e.strip() for e in emails_raw.split(',') if e.strip()]

config = {
    "author": {
        "names":  names,
        "emails": emails,
    },
    "domains": {
        "old":              old_domain,
        "new_url_template": new_tpl,
    },
    "github_org": github_org,
    "donation_patterns": [
        "paypal\\.me",
        "paypal.*cgi-bin",
        "patreon\\.com",
        "ko-fi\\.com",
        "buymeacoffee",
        "Support the development",
        "Buy me a"
    ]
}

with open(out_path, 'w') as f:
    json.dump(config, f, indent=2)
    f.write('\n')

print(f"Config written to: {out_path}")
PYEOF

echo ""
echo "Done. Run audit-plugin-docs.sh <plugin-path> to verify, or"
echo "      fix-plugin-docs.sh <plugin-path> --apply to fix."
