#!/usr/bin/env bash
#
# Native Elgg seeding for the API benchmark. Runs elgg-cli database:seed and polls
# row counts. `--limit` is per entity type; see references/site-profile.md for the
# limit -> site-size mapping (native seeding is ~12 entities/s).
#
#   bin/seed.sh <limit>
#
# Environment: PHP_CONTAINER (elgg-test-php), DB_CONTAINER (elgg-test-db),
#              DB_PREFIX (c_i_elgg_).
set -euo pipefail

LIMIT="${1:?usage: seed.sh <limit>}"
PHP_CONTAINER="${PHP_CONTAINER:-elgg-test-php}"
DB_CONTAINER="${DB_CONTAINER:-elgg-test-db}"
DB_PREFIX="${DB_PREFIX:-c_i_elgg_}"

echo ">> native seeding, --limit=$LIMIT (this is not instant; ~12 entities/s)"
docker exec "$PHP_CONTAINER" php ./elgg-cli database:seed --limit="$LIMIT" --no-ansi --no-interaction

docker exec "$DB_CONTAINER" mysql -uroot -ppassword elgg -N -B -e \
  "SELECT CONCAT('entities=', (SELECT COUNT(*) FROM ${DB_PREFIX}entities),
                 ' metadata=', (SELECT COUNT(*) FROM ${DB_PREFIX}metadata))" 2>/dev/null
