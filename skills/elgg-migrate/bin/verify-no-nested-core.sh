#!/usr/bin/env bash
# verify-no-nested-core.sh — no plugin may vendor a second copy of Elgg core.
#
# A `composer install` run inside a plugin directory installs that plugin's OWN
# dependencies — and if its composer.json lists `elgg/elgg` under `require`
# (rather than `require-dev`), that means a complete second Elgg core lands in
# mod/<plugin>/vendor/elgg/elgg.
#
# Composer's autoloader then resolves some Elgg\* classes to the stale copy.
# Nothing warns. The plugin activates, boots, and passes every source gate; only
# a runtime path that touches a shadowed class fails.
#
# On bodyology, hypefaker had vendored Elgg 5.1.12. \Elgg\Email\Address resolved
# to that 5.x class (a Laminas\Mail\Address subclass) while \Elgg\Email was the
# real 7.x class, whose setFrom()/setTo() declare Symfony\Component\Mime\Address.
# Every outbound email — registration, password reset, every notification — died
# with a TypeError. It had been broken for the whole migration and no gate saw it.
#
# Worse, the stale core MASKED a second bug: hypenotifications imported the
# removed \Elgg\Email\Address. Delete the nested core and the TypeError becomes a
# "class not found" fatal. Both must be fixed.
#
# Checks:
# The check that matters is the DIRECTORY, not the require line. Declaring
# `elgg/elgg` in a plugin's `require` is normal Elgg convention — it is how the
# site's composer resolves a compatible core, and 75 of bodyology's plugins do it.
# The hazard only materialises when someone runs `composer install` INSIDE a
# plugin dir and a core lands in its vendor/. So:
#
#   FAIL: a mod/*/vendor/elgg/elgg directory exists
#   WARN: composer.json requires elgg/elgg AND a vendor/ dir sits beside it —
#         that plugin is one `composer install` away from the failure above
#
# Usage:
#   verify-no-nested-core.sh <plugins-dir>
set -euo pipefail

PLUGINS_DIR="${1:?usage: verify-no-nested-core.sh <plugins-dir>}"
found=0

for core in "$PLUGINS_DIR"/*/vendor/elgg/elgg; do
  [ -d "$core" ] || continue
  ver="$(python3 -c "import json,sys;print(json.load(open('$core/composer.json')).get('version','?'))" 2>/dev/null || echo '?')"
  plugin="$(basename "$(dirname "$(dirname "$(dirname "$core")")")")"
  printf 'NESTED CORE %s: vendor/elgg/elgg (elgg %s) shadows the real core\n' "$plugin" "$ver"
  found=$((found+1))
done

at_risk=0
for cj in "$PLUGINS_DIR"/*/composer.json; do
  [ -f "$cj" ] || continue
  plugin="$(basename "$(dirname "$cj")")"
  [ -d "$(dirname "$cj")/vendor" ] || continue
  if python3 - "$cj" <<'PY' 2>/dev/null
import json, sys
d = json.load(open(sys.argv[1]))
sys.exit(0 if 'elgg/elgg' in (d.get('require') or {}) else 1)
PY
  then
    printf 'AT RISK %s: has vendor/ and requires elgg/elgg — one `composer install` from a nested core\n' "$plugin"
    at_risk=$((at_risk+1))
  fi
done
[ "$at_risk" -gt 0 ] && echo "($at_risk plugin(s) at risk — warning only, not a failure)"

echo
if [ "$found" -gt 0 ]; then
  echo "$found nested-core problem(s). A second Elgg core on the autoload path" >&2
  echo "silently shadows core classes; the failure surfaces only at runtime." >&2
  exit 1
fi
echo "nested core: clean"
