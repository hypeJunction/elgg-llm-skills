#!/usr/bin/env bash
# gen-e2e-fixtures.sh — regenerate an e2e suite's fixtures.json from a live Elgg
# database, so the suite never asserts against GUIDs that do not exist.
#
# WHY: fixtures hand-captured from an ANONYMISED dev dump do not survive contact
# with the real, chain-migrated database — the two have completely disjoint GUIDs,
# so the same number can be a group in one and a widget in the other. The suite
# then fails on fixture rot and the failures look like migration defects.
# Generate, don't hardcode.
#
# Every entity emitted is enabled, not deleted, and PUBLICLY readable
# (access_id = 2), so the same fixtures are valid for the anon, user and admin
# projects alike.
#
# Deliberately NO usernames or profile URLs: the real database holds real member
# accounts, and this file is committed. Specs discover profile links live from
# /members (which is public here) instead.
#
# Usage:
#   gen-e2e-fixtures.sh --db <db-container> --out path/to/fixtures.json
#   gen-e2e-fixtures.sh --db <db-container> --print   # stdout, write nothing
set -euo pipefail

DB_CONTAINER="${E2E_FIXTURE_DB:-}"
OUT="${E2E_FIXTURES_OUT:-}"
PRINT=0

while [ $# -gt 0 ]; do
  case "$1" in
    --db) DB_CONTAINER="$2"; shift 2 ;;
    --out) OUT="$2"; shift 2 ;;
    --print) PRINT=1; shift ;;
    *) echo "unknown arg: $1" >&2; exit 2 ;;
  esac
done

[ -n "$DB_CONTAINER" ] || { echo "ERROR: set E2E_FIXTURE_DB (or pass --db)" >&2; exit 2; }
[ "$PRINT" = 1 ] || [ -n "$OUT" ] || { echo "ERROR: set E2E_FIXTURES_OUT (or pass --out / --print)" >&2; exit 2; }
docker inspect "$DB_CONTAINER" >/dev/null 2>&1 || { echo "ERROR: db container $DB_CONTAINER not running" >&2; exit 2; }

q() { docker exec "$DB_CONTAINER" mysql --skip-ssl -uroot -proot elgg -N -e "$1" 2>/dev/null; }

# subtype -> URL pattern. Keep in step with the plugins' route registrations.
emit_guids() { # subtype limit
  q "SELECT guid FROM elgg_entities
     WHERE subtype='$1' AND type='object' AND enabled='yes' AND deleted='no' AND access_id=2
     ORDER BY guid LIMIT $2;"
}

# Groups need their content_access_mode: only 'unrestricted' groups render for anon
# (since Elgg 5, Gatekeeper::assertAccessibleGroup demands canAccessContent()).
emit_public_groups() {
  q "SELECT e.guid FROM elgg_entities e
     JOIN elgg_metadata m ON m.entity_guid = e.guid AND m.name = 'content_access_mode'
     WHERE e.type='group' AND e.enabled='yes' AND e.deleted='no' AND e.access_id=2
       AND BINARY m.value='unrestricted'
     ORDER BY e.guid LIMIT $1;"
}

json_paths() { # prefix, guids...
  local prefix="$1"; shift
  local first=1 out=""
  for g in "$@"; do
    [ "$first" = 1 ] || out+=","
    out+=$'\n    "'"${prefix}${g}"'"'
    first=0
  done
  printf '%s' "$out"
}

# NB: do NOT name a variable GROUPS. Bash keeps the caller's supplementary group
# ids in the GROUPS array, and `GROUPS=$(...)` writes element 0 of that array
# instead of creating a scalar — so $GROUPS reads back as a numeric gid (1000).
# The generator silently emitted /groups/profile/1000 (a widget) until renamed.
GROUP_GUIDS=$(emit_public_groups 5)
FILES=$(emit_guids file 4)
BOOKMARKS=$(emit_guids bookmarks 4)
VIDEOS=$(emit_guids videolist_item 4)
PAGES=$(emit_guids page_top 4)

for name in GROUP_GUIDS FILES BOOKMARKS VIDEOS PAGES; do
  eval "v=\$$name"
  [ -n "$v" ] || echo "WARN: no public entities found for $name" >&2
done

body=$(cat <<EOF
{
  "_generated_by": "bin/gen-e2e-fixtures.sh — do not hand-edit; regenerate against the target database",
  "_source_db": "$DB_CONTAINER",
  "groups": [$(json_paths "/groups/profile/" $GROUP_GUIDS)
  ],
  "files": [$(json_paths "/file/view/" $FILES)
  ],
  "bookmarks": [$(json_paths "/bookmarks/view/" $BOOKMARKS)
  ],
  "videos": [$(json_paths "/videolist/watch/" $VIDEOS)
  ],
  "pages": [$(json_paths "/pages/view/" $PAGES)
  ]
}
EOF
)

python3 -c 'import json,sys; json.loads(sys.stdin.read())' <<<"$body" || { echo "ERROR: generated invalid JSON" >&2; exit 1; }

if [ "$PRINT" = 1 ]; then
  printf '%s\n' "$body"
else
  printf '%s\n' "$body" > "$OUT"
  echo "wrote $OUT (source: $DB_CONTAINER)" >&2
fi
