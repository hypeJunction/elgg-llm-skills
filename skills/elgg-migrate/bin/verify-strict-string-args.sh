#!/usr/bin/env bash
# verify-strict-string-args.sh — find callsites that pass a possibly-null value to
# an Elgg 7 core function whose parameter is a non-nullable `string`.
#
# Elgg 7 tightened these signatures:
#     elgg_get_excerpt(string $text, int $num_chars = 250): string
#     elgg_strip_tags(string $string, ?string $allowable_tags = null): string
#
# An entity property that was never set reads back as NULL, so
# `elgg_get_excerpt($entity->description)` is a TypeError — an HTTP 500 on any page
# that renders an entity without a description. This is silent in 2.x/3.x (PHP was
# lenient) and only fires against real data, so it survives every synthetic smoke
# test. On a real production dataset it fataled /profile/{user} and the folder
# listing.
#
# The fix at each site is a `(string)` cast, or a truthiness guard around the call.
#
# Usage:
#   verify-strict-string-args.sh [<plugins-dir>]
# Exit: 0 = clean, 1 = unguarded callsite(s) found, 2 = usage error.
set -uo pipefail

PLUGINS_DIR="${1:-${ELGG_MIGRATE_PLUGINS:-}}"
[ -n "$PLUGINS_DIR" ] || { echo "usage: verify-strict-string-args.sh <plugins-dir>" >&2; exit 2; }
[ -d "$PLUGINS_DIR" ] || { echo "ERROR: not a directory: $PLUGINS_DIR" >&2; exit 2; }

# Nullable-looking first argument: a PROPERTY read on an object ($e->description,
# $e->$prop). Not a method call — getDisplayName() and friends declare `: string`,
# so `->foo(` is excluded via the trailing negative check below. `(string)` casts
# and literals are already safe; strip_tags()/trim() wrappers coerce to string
# themselves and only raise a deprecation, so they never reach this pattern.
NULLABLE='\$[A-Za-z_][A-Za-z0-9_]*->\$?[A-Za-z_][A-Za-z0-9_]*'
NOT_A_CALL='[^(A-Za-z0-9_]'

found=0
while IFS= read -r hit; do
  file="${hit%%:*}"; rest="${hit#*:}"; line="${rest%%:*}"

  # Skip a site whose enclosing statement is inside a truthiness guard on the same
  # property — e.g. `if (!empty($entity->description)) { ... }`.
  prop="$(sed -n "${line}p" "$file" | grep -oE "$NULLABLE" | head -1)"
  [ -n "$prop" ] || continue
  esc="$(printf '%s' "$prop" | sed 's/[][\.*^$/]/\\&/g')"
  if sed -n "$(( line > 6 ? line - 6 : 1 )),${line}p" "$file" \
       | grep -qE "(if|&&|\?)[^;]*(!empty\($esc\)|$esc[[:space:]]*(&&|\)))"; then
    continue
  fi

  printf 'UNGUARDED %s:%s  %s\n' "$file" "$line" "$(sed -n "${line}p" "$file" | sed 's/^[[:space:]]*//' | cut -c1-90)"
  found=$((found+1))
done < <(
  grep -rn --include=*.php -E "(elgg_get_excerpt|elgg_strip_tags)\([[:space:]]*${NULLABLE}${NOT_A_CALL}" "$PLUGINS_DIR" \
    | grep -v '/vendor/\|/vendors/\|/tests/\|/node_modules/\|/_legacy/' || true
)

echo
if [ "$found" -gt 0 ]; then
  echo "$found unguarded callsite(s): cast with (string) or guard the property." >&2
  exit 1
fi
echo "strict-string args: clean"
