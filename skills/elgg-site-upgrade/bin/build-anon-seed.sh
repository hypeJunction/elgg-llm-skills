#!/usr/bin/env bash
# build-anon-seed.sh — produce an anonymized Elgg 2.x seed dump for rehearsing a
# migration chain on production-SHAPE data WITHOUT shipping production PII.
#
# Restores a production 2.x dump into a throwaway mysql:5.7 container, applies an
# anonymize transform (PII scrub + the login/boot footgun fixes — see
# references/anonymize-elgg2x.sql), then dumps the result to a gzipped SQL file
# suitable as the chain seed. Runs the SQL's login-critical assertions and fails
# if any tripped (a clobbered `validated` flag or a missing dev password hash
# would silently break login for every migrated user).
#
# Usage:
#   build-anon-seed.sh --dump prod-2x.sql[.gz] [--anon anonymize.sql] [--out anon-2x.sql.gz]
#
#   --dump FILE   production 2.x dump (.sql or .sql.gz). REQUIRED.
#   --anon FILE   anonymize transform. Default: the shipped
#                 references/anonymize-elgg2x.sql (edit a copy for site-specific
#                 tables/plugins; keep the login/site-secret sections intact).
#   --out FILE    output gzipped seed. Default: <dump-dir>/anon-2x-seed.sql.gz
#
# Exit non-zero if the dump/anon file is missing, mysql never comes up, or a
# login-critical assertion fails.
set -euo pipefail

SELF_DIR="$(cd "$(dirname "$0")" && pwd)"
SKILL_ROOT="$(cd "$SELF_DIR/.." && pwd)"

DUMP=""
ANON="$SKILL_ROOT/references/anonymize-elgg2x.sql"
OUT=""

while [ $# -gt 0 ]; do
  case "$1" in
    --dump) DUMP="$2"; shift 2 ;;
    --anon) ANON="$2"; shift 2 ;;
    --out)  OUT="$2";  shift 2 ;;
    *) echo "unknown arg: $1" >&2; exit 2 ;;
  esac
done

[ -n "$DUMP" ] || { echo "ERROR: --dump is required (production 2.x .sql/.sql.gz)" >&2; exit 2; }
[ -f "$DUMP" ] || { echo "ERROR: dump not found: $DUMP" >&2; exit 1; }
[ -f "$ANON" ] || { echo "ERROR: anonymize.sql not found: $ANON" >&2; exit 1; }
[ -n "$OUT" ] || OUT="$(cd "$(dirname "$DUMP")" && pwd)/anon-2x-seed.sql.gz"

NAME="anon-seed-build-$$"
echo "[build-anon-seed] starting throwaway mysql:5.7 ($NAME)"
docker rm -f "$NAME" >/dev/null 2>&1 || true
docker run --rm -d --name "$NAME" \
  -e MYSQL_ROOT_PASSWORD=root -e MYSQL_DATABASE=elgg \
  -e MYSQL_USER=elgg -e MYSQL_PASSWORD=elgg \
  mysql:5.7 --default-authentication-plugin=mysql_native_password >/dev/null

cleanup() { docker rm -f "$NAME" >/dev/null 2>&1 || true; }
trap cleanup EXIT

echo "[build-anon-seed] waiting for mysql..."
tries=0
until docker exec "$NAME" mysql -uroot -proot -N -e "SELECT 1" >/dev/null 2>&1; do
  tries=$((tries+1)); [ "$tries" -gt 90 ] && { echo "mysql never ready" >&2; exit 1; }
  sleep 2
done
# mysql:5.7 restarts mysqld after init; wait for the second "ready" marker.
rc=0; wt=0
while [ "$rc" -lt 2 ]; do
  rc=$(docker logs "$NAME" 2>&1 | grep -cE 'mysqld:.*ready for connections' || true)
  [ "$rc" -ge 2 ] && break
  wt=$((wt+1)); [ "$wt" -gt 60 ] && break; sleep 2
done
until docker exec "$NAME" mysql -uroot -proot -N -e "SELECT 1" >/dev/null 2>&1; do sleep 2; done

echo "[build-anon-seed] restoring prod dump: $DUMP"
case "$DUMP" in *.gz) gunzip -c "$DUMP" ;; *) cat "$DUMP" ;; esac \
  | docker exec -i "$NAME" mysql -uroot -proot elgg

# Pre-flight: refuse an input that has ALREADY been through this transform.
# Anonymisation is destructive and NOT reversible — the original metastring values
# are gone. Re-running on an anonymised dump cannot repair earlier damage, but the
# assertions below would report it as if this run had caused it. Feed a RAW
# production dump. (snapshots/prod-dump-2026-05-28.sql.gz is misnamed: it is an
# anonymised artifact carrying the bd elgg-migrate-2knpd damage.)
pre_placeholders=$(docker exec "$NAME" mysql -uroot -proot elgg -N -e "
  SELECT COUNT(*) FROM elgg_metastrings WHERE string LIKE 'metastring\\_%';" 2>/dev/null || echo 0)
if [ "${pre_placeholders:-0}" -gt 0 ] 2>/dev/null; then
    echo "[build-anon-seed] REFUSING: input already contains $pre_placeholders 'metastring_<id>' values." >&2
    echo "  This dump has already been anonymised. Anonymisation is destructive and cannot be" >&2
    echo "  re-applied to recover the originals — supply a RAW production dump instead." >&2
    echo "  Override with ALLOW_REANONYMIZE=1 if you really mean to (assertions will be unreliable)." >&2
    [ "${ALLOW_REANONYMIZE:-0}" = "1" ] || exit 1
fi

echo "[build-anon-seed] applying anonymize transform: $ANON"
# mysql exits non-zero on the first error; without this check a transform that
# aborted half-way would still be dumped and shipped as the chain seed.
if ! docker exec -i "$NAME" mysql -uroot -proot elgg < "$ANON"; then
    echo "[build-anon-seed] FAIL: the anonymize transform errored — refusing to dump a partially anonymized DB" >&2
    exit 1
fi

echo "[build-anon-seed] login-critical assertions:"
fail=0
clob=$(docker exec "$NAME" mysql -uroot -proot elgg -N -e "
  SELECT COUNT(*) FROM elgg_metadata m
    JOIN elgg_metastrings mn ON mn.id=m.name_id
    JOIN elgg_metastrings mv ON mv.id=m.value_id
   WHERE mn.string='validated' AND mv.string LIKE 'metastring\\_%';" 2>/dev/null || echo "?")
devh=$(docker exec "$NAME" mysql -uroot -proot elgg -N -e "
  SELECT COUNT(*) FROM elgg_users_entity
   WHERE password_hash='\$2y\$10\$TunwvKLLEw5s1XbW59mXoOzBbTJ67lU3x2L2dXtAQL9ldhQN/Xo2G';" 2>/dev/null || echo "?")
users=$(docker exec "$NAME" mysql -uroot -proot elgg -N -e "SELECT COUNT(*) FROM elgg_users_entity;" 2>/dev/null || echo "?")

# elgg_metastrings is a deduplicated shared pool: a string used as a metadata NAME
# is the same row as that string used as some entity's free-text VALUE. Scrubbing
# by value alone renames metadata names and clobbers functional values, and the
# resulting DB is an unsound oracle for anything access-related (bd 2knpd).
names=$(docker exec "$NAME" mysql -uroot -proot elgg -N -e "
  SELECT COUNT(*) FROM (
    SELECT m.name_id AS id FROM elgg_metadata m
    UNION SELECT a.name_id FROM elgg_annotations a
  ) n JOIN elgg_metastrings ms ON ms.id=n.id
   WHERE ms.string LIKE 'metastring\\_%';" 2>/dev/null || echo "?")
func=$(docker exec "$NAME" mysql -uroot -proot elgg -N -e "
  SELECT COUNT(*) FROM elgg_metadata m
    JOIN elgg_metastrings mn ON mn.id=m.name_id
    JOIN elgg_metastrings mv ON mv.id=m.value_id
   WHERE mv.string LIKE 'metastring\\_%'
     AND (mn.string IN ('content_access_mode','membership','access_id','admin','banned','language')
          OR mn.string LIKE '%\\_enable');" 2>/dev/null || echo "?")

echo "  validated_flags_clobbered     = $clob (must be 0)"
echo "  metadata_names_clobbered      = $names (must be 0)"
echo "  functional_metadata_clobbered = $func (must be 0)"
echo "  users_with_dev_hash           = $devh / $users users"
[ "$clob" = "0" ] || { echo "  FAIL: metastring scrub clobbered 'validated' — login would break for migrated users" >&2; fail=1; }
[ "$names" = "0" ] || { echo "  FAIL: metastring scrub rewrote metadata NAMES — those metadata rows have lost their name" >&2; fail=1; }
[ "$func" = "0" ] || { echo "  FAIL: metastring scrub clobbered FUNCTIONAL metadata (group access / tool options) — seed is an unsound oracle" >&2; fail=1; }
[ "$devh" = "$users" ] || { echo "  FAIL: not every user has the dev password hash" >&2; fail=1; }
[ "$fail" -eq 0 ] || exit 1

echo "[build-anon-seed] dumping anonymized seed -> $OUT"
docker exec "$NAME" sh -c 'mysqldump -uroot -proot --single-transaction --no-tablespaces elgg' | gzip > "$OUT"
echo "[build-anon-seed] DONE: $OUT ($(wc -c < "$OUT") bytes). Log in as any user_<guid> / password 'dev'."
