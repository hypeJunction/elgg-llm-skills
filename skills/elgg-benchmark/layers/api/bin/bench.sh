#!/usr/bin/env bash
#
# Run the API-layer benchmark before AND after a metadata index change and print
# the diff. Assumes an installed, seeded Elgg is reachable in a container.
#
# Environment (defaults match the skill's ad-hoc setup; override as needed):
#   PHP_CONTAINER   php+elgg container, Elgg root at /var/www/html   (elgg-test-php)
#   DB_CONTAINER    mysql/mariadb container                          (elgg-test-db)
#   DB_NAME         database name                                    (elgg)
#   DB_USER/DB_PASS root credentials                                 (root/password)
#   DB_PREFIX       Elgg table prefix                                (c_i_elgg_)
#   ITERS           wall-clock iterations per shape                  (50)
#
# The index add/drop here mirrors the migration under test. Only the index differs
# between the two runs — do not seed or mutate data in between.
set -euo pipefail

PHP_CONTAINER="${PHP_CONTAINER:-elgg-test-php}"
DB_CONTAINER="${DB_CONTAINER:-elgg-test-db}"
DB_NAME="${DB_NAME:-elgg}"
DB_USER="${DB_USER:-root}"
DB_PASS="${DB_PASS:-password}"
DB_PREFIX="${DB_PREFIX:-c_i_elgg_}"
ITERS="${ITERS:-50}"
HERE="$(cd "$(dirname "$0")/.." && pwd)"
OUT="${OUT:-$HERE/../../examples/api-metadata-index}"
mkdir -p "$OUT"

sql() { docker exec -i "$DB_CONTAINER" mysql -u"$DB_USER" -p"$DB_PASS" "$DB_NAME" -e "$1" 2>/dev/null; }
bench() { docker exec -w /var/www/html "$PHP_CONTAINER" php bench_api.php "$ITERS"; }

echo ">> staging bench.php into $PHP_CONTAINER"
docker cp "$HERE/bench.php" "$PHP_CONTAINER":/var/www/html/bench_api.php

echo ">> BEFORE — dropping composite index"
sql "ALTER TABLE ${DB_PREFIX}metadata DROP INDEX entity_guid_name" || true
bench > "$OUT/before.json"

echo ">> AFTER — adding composite index"
sql "ALTER TABLE ${DB_PREFIX}metadata ADD INDEX entity_guid_name (entity_guid, name(255)); ANALYZE TABLE ${DB_PREFIX}metadata" >/dev/null
bench > "$OUT/after.json"

echo ">> report"
php "$HERE/report.php" "$OUT/before.json" "$OUT/after.json" | tee "$OUT/RESULTS.md"
