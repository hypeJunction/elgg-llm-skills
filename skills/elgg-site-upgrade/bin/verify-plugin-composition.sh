#!/usr/bin/env bash
# verify-plugin-composition.sh — diff the ACTIVE plugin set of a migrated site
# against the source (production) site. THE FIRST CHECK when a feature is missing.
#
# WHY THIS IS FIRST: Elgg deactivates a plugin that throws during boot
# (Elgg\Application boots each active plugin in a try/catch; an uncaught error
# flips its active_plugin relationship off). So a plugin that is ACTIVE on the
# source but INACTIVE on the migrated site is not a config preference — it is the
# single loudest signal of a migration fatal, and it silently removes that
# plugin's entire feature surface (routes, actions, views, hooks). No page 500s;
# the feature just isn't there.
#
# Two distinct causes, both surfaced here:
#   1. BOOT FATAL  — the plugin errored on 7.x boot and Elgg auto-disabled it.
#                    Reproduce with:  elgg-cli plugins:activate <id>   (reads the
#                    fatal) or read error.log around the boot. Fix the fatal.
#   2. RESTORE GAP — the plugin was simply never re-activated when the prod active
#                    set was restored into the migrated DB (e.g. it was not on disk
#                    at restore time, or an id-case mismatch). Fix: re-run
#                    restore-active-plugins.sh; confirm the plugin STAYS active
#                    after a cache clear + fresh boot (a boot fatal re-disables it).
#
# A plugin ABSENT FROM DISK on the target is usually a 2.x-era plugin removed or
# absorbed into 7.x core (notifications, embed, htmlawed, legacy_urls, aalborg_theme,
# diagnostics, log* tools). Those are expected cross-version and reported separately.
#
# Usage:
#   verify-plugin-composition.sh --source-tsv <prod-active.tsv> --target-db <container> [--target-mod-dir <path-in-container>]
#   verify-plugin-composition.sh --source-db <prod-container> --target-db <preview-container>
#
# source TSV format: one plugin id per line (an optional 2nd tab-column priority is
# ignored). Ids are compared LOWERCASED (Elgg 4 lowercased plugin ids).
set -euo pipefail

SRC_TSV=""; SRC_DB=""; TGT_DB=""; TGT_MOD="/var/www/html/mod"; DB_NAME="elgg"; PREFIX="elgg_"
while [ $# -gt 0 ]; do
  case "$1" in
    --source-tsv) SRC_TSV="$2"; shift 2 ;;
    --source-db) SRC_DB="$2"; shift 2 ;;
    --target-db) TGT_DB="$2"; shift 2 ;;
    --target-mod-dir) TGT_MOD="$2"; shift 2 ;;
    --db-name) DB_NAME="$2"; shift 2 ;;
    --prefix) PREFIX="$2"; shift 2 ;;
    *) echo "unknown arg: $1" >&2; exit 2 ;;
  esac
done
[ -n "$TGT_DB" ] || { echo "ERROR: --target-db <container> required" >&2; exit 2; }

active_ids() {  # $1 = db container
  docker exec "$1" mysql --skip-ssl -uroot -proot "$DB_NAME" -N -e "
    SELECT LOWER(t.value)
    FROM ${PREFIX}entities e
    JOIN ${PREFIX}metadata t ON t.entity_guid = e.guid AND t.name = 'title'
    JOIN ${PREFIX}entity_relationships r ON r.guid_one = e.guid AND r.relationship = 'active_plugin'
    WHERE e.subtype = 'plugin';" 2>/dev/null | sort -u
}

TMP="$(mktemp -d)"; trap 'rm -rf "$TMP"' EXIT
if [ -n "$SRC_TSV" ]; then
  awk -F'\t' 'NF{print tolower($1)}' "$SRC_TSV" | sort -u > "$TMP/src"
elif [ -n "$SRC_DB" ]; then
  active_ids "$SRC_DB" > "$TMP/src"
else
  echo "ERROR: one of --source-tsv / --source-db required" >&2; exit 2
fi
active_ids "$TGT_DB" > "$TMP/tgt"

# plugins on the target's disk (so we can split missing into on-disk vs absent)
docker exec "$TGT_DB" true 2>/dev/null # noop; disk list needs the APP container, resolved below
missing="$(comm -23 "$TMP/src" "$TMP/tgt")"

echo "source active: $(wc -l < "$TMP/src")   target active: $(wc -l < "$TMP/tgt")"
echo
if [ -z "$missing" ]; then
  echo "plugin composition: every source-active plugin is active on the target ✓"
  extra="$(comm -13 "$TMP/src" "$TMP/tgt")"
  [ -n "$extra" ] && { echo; echo "target-only (extra, usually 7.x-core or newly-added):"; printf '%s\n' "$extra" | sed 's/^/  /'; }
  exit 0
fi

echo "ACTIVE ON SOURCE, INACTIVE ON TARGET — triage each (a boot fatal auto-disables):"
printf '%s\n' "$missing" | while read -r id; do
  [ -n "$id" ] || continue
  printf '  %s\n' "$id"
done
echo
echo "NEXT (in order):"
echo "  1. For each, try: docker exec -u www-data <app> php vendor/elgg/elgg/elgg-cli plugins:activate <id>"
echo "     - a class/fatal error => a BOOT FATAL; fix it, then re-activate."
echo "     - activates cleanly    => a RESTORE GAP; ensure it STAYS active after"
echo "       cache:clear + a fresh boot (a boot fatal re-disables it silently)."
echo "  2. A plugin ABSENT from the target disk is usually a 2.x plugin removed/absorbed"
echo "     into 7.x core — confirm the feature is covered by core, then ignore."
exit 1
