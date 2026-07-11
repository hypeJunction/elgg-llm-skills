#!/usr/bin/env bash
#
# Bring up a clean, installed Elgg for the API benchmark: a throwaway MySQL
# container + a PHP container with the Elgg checkout mounted, install via
# elgg-cli, and activate the bundled content plugins the seeder needs.
#
# Environment:
#   ELGG_ROOT     path to the Elgg checkout to mount              (required)
#   ELGG_IMAGE    php image with gd/pdo_mysql/intl/mbstring       (php:8.3-cli-based)
#   DB_IMAGE      database image                                  (mysql:8.0)
#   PHP_CONTAINER / DB_CONTAINER   container names                (elgg-test-php / elgg-test-db)
#
# Ports are not published; the PHP container reaches the DB over a private network.
set -euo pipefail

ELGG_ROOT="${ELGG_ROOT:?set ELGG_ROOT to your Elgg checkout}"
ELGG_IMAGE="${ELGG_IMAGE:?set ELGG_IMAGE to a php image with gd,pdo_mysql,intl,mbstring}"
DB_IMAGE="${DB_IMAGE:-mysql:8.0}"
PHP_CONTAINER="${PHP_CONTAINER:-elgg-test-php}"
DB_CONTAINER="${DB_CONTAINER:-elgg-test-db}"
NET="${NET:-elgg-bench-net}"

PLUGINS="activity blog bookmarks ckeditor dashboard developers discussions externalpages \
file friends friends_collections groups likes members messageboard messages pages profile \
reportedcontent search site_notifications system_log tagcloud thewire web_services"

docker network create "$NET" >/dev/null 2>&1 || true

echo ">> DB ($DB_IMAGE, tmpfs)"
docker rm -f "$DB_CONTAINER" >/dev/null 2>&1 || true
docker run -d --name "$DB_CONTAINER" --network "$NET" \
  -e MYSQL_ROOT_PASSWORD=password -e MYSQL_DATABASE=elgg \
  --tmpfs /var/lib/mysql "$DB_IMAGE" --innodb-buffer-pool-size=1G >/dev/null
until docker exec "$DB_CONTAINER" mysqladmin ping -uroot -ppassword >/dev/null 2>&1; do sleep 2; done

echo ">> PHP ($ELGG_IMAGE), Elgg root $ELGG_ROOT"
docker rm -f "$PHP_CONTAINER" >/dev/null 2>&1 || true
docker run -d --name "$PHP_CONTAINER" --network "$NET" \
  --user "$(id -u):$(id -g)" -v "$ELGG_ROOT":/var/www/html -e HOME=/var/www/html \
  -e COMPOSER_HOME=/tmp/composer \
  -e ELGG_DB_HOST="$DB_CONTAINER" -e ELGG_DB_PORT=3306 -e ELGG_DB_NAME=elgg \
  -e ELGG_DB_USER=root -e ELGG_DB_PASS=password -e ELGG_DB_PREFIX=c_i_elgg_ \
  -e ELGG_DB_ENCODING=utf8mb4 -e ELGG_WWWROOT=http://localhost:8888/ \
  "$ELGG_IMAGE" sleep infinity >/dev/null

docker exec "$PHP_CONTAINER" composer install --no-interaction --no-progress
docker exec "$PHP_CONTAINER" sh -c 'mkdir -p engine/tests/test_files/dataroot; php ./elgg-cli install --config ./install/cli/testing_app.php --no-ansi'
docker exec "$PHP_CONTAINER" php ./elgg-cli plugins:activate $PLUGINS --no-ansi
echo ">> ready. Seed with bin/seed.sh <limit>, then benchmark with bin/bench.sh"
