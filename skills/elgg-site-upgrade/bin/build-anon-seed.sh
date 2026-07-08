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

echo "[build-anon-seed] applying anonymize transform: $ANON"
docker exec -i "$NAME" mysql -uroot -proot elgg < "$ANON"

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
echo "  validated_flags_clobbered = $clob (must be 0)"
echo "  users_with_dev_hash       = $devh / $users users"
[ "$clob" = "0" ] || { echo "  FAIL: metastring scrub clobbered 'validated' — login would break for migrated users" >&2; fail=1; }
[ "$devh" = "$users" ] || { echo "  FAIL: not every user has the dev password hash" >&2; fail=1; }
[ "$fail" -eq 0 ] || exit 1

echo "[build-anon-seed] dumping anonymized seed -> $OUT"
docker exec "$NAME" sh -c 'mysqldump -uroot -proot --single-transaction --no-tablespaces elgg' | gzip > "$OUT"
echo "[build-anon-seed] DONE: $OUT ($(wc -c < "$OUT") bytes). Log in as any user_<guid> / password 'dev'."
