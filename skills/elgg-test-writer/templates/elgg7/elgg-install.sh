#!/bin/bash
set -e

# Per-plugin Elgg 7.x install + activation script.
# PLUGIN_ID must be set in the container environment (passed by docker-compose
# from <plugin>/docker/.env). Only that one plugin is activated — no fleet
# activation, no plugin-order.txt, no cross-plugin side effects.

if [ -z "${PLUGIN_ID:-}" ]; then
    echo "ERROR: PLUGIN_ID environment variable is required." >&2
    echo "Set it in docker/.env before starting the stack." >&2
    exit 1
fi

echo "Waiting for MySQL..."
until php -r "new PDO('mysql:host=${ELGG_DB_HOST:-db}', '${ELGG_DB_USER:-elgg}', '${ELGG_DB_PASS:-elgg}');" 2>/dev/null; do
    sleep 1
done
echo "MySQL is ready."

cd /var/www/html

# Elgg core plugins live in vendor/elgg/elgg/mod/ and must be symlinked into
# mod/ so the plugin loader can find them (a plugin under test that declares a
# core dependency — groups/file/discussions/… — fails to activate otherwise).
# Bind-mounted plugins always win. Runs on every start (symlinks are cheap and
# idempotent), not only first install.
if [ -d /var/www/html/vendor/elgg/elgg/mod ]; then
    for core_plugin_dir in /var/www/html/vendor/elgg/elgg/mod/*/; do
        core_plugin_id=$(basename "${core_plugin_dir}")
        if [ ! -e "/var/www/html/mod/${core_plugin_id}" ]; then
            ln -s "${core_plugin_dir%/}" "/var/www/html/mod/${core_plugin_id}"
        fi
    done
fi

if [ ! -f /var/www/html/.elgg-installed ]; then
    echo "Installing Elgg 7.x..."

    mkdir -p elgg-config
    cat > elgg-config/settings.php <<'SETTINGS_TEMPLATE'
<?php
global $CONFIG;
if (!isset($CONFIG)) {
    $CONFIG = new \stdClass;
}
SETTINGS_TEMPLATE

    cat >> elgg-config/settings.php <<SETTINGS_VALUES
\$CONFIG->dbuser = '${ELGG_DB_USER:-elgg}';
\$CONFIG->dbpass = '${ELGG_DB_PASS:-elgg}';
\$CONFIG->dbname = '${ELGG_DB_NAME:-elgg}';
\$CONFIG->dbhost = '${ELGG_DB_HOST:-db}';
\$CONFIG->dbport = '3306';
\$CONFIG->dbprefix = 'elgg_';
\$CONFIG->dbencoding = 'utf8mb4';
\$CONFIG->dataroot = '${ELGG_DATA_ROOT:-/var/www/data/}';
\$CONFIG->wwwroot = '${ELGG_SITE_URL:-http://elgg/}';
\$CONFIG->cacheroot = '${ELGG_DATA_ROOT:-/var/www/data/}cache/';
\$CONFIG->assetroot = '${ELGG_DATA_ROOT:-/var/www/data/}assets/';
SETTINGS_VALUES

    php -r "
        require_once 'vendor/autoload.php';

        \$params = [
            'dbuser' => '${ELGG_DB_USER:-elgg}',
            'dbpassword' => '${ELGG_DB_PASS:-elgg}',
            'dbname' => '${ELGG_DB_NAME:-elgg}',
            'dbhost' => '${ELGG_DB_HOST:-db}',
            'dbport' => '3306',
            'dbprefix' => 'elgg_',
            'sitename' => 'Elgg 7.x Plugin Test',
            'siteemail' => '${ELGG_ADMIN_EMAIL:-admin@example.com}',
            'wwwroot' => '${ELGG_SITE_URL:-http://elgg/}',
            'dataroot' => '${ELGG_DATA_ROOT:-/var/www/data/}',
            'displayname' => 'Admin',
            'email' => '${ELGG_ADMIN_EMAIL:-admin@example.com}',
            'username' => 'admin',
            'password' => '${ELGG_ADMIN_PASSWORD:-admin12345}',
        ];

        \$installer = new \ElggInstaller();
        \$installer->batchInstall(\$params);
        echo 'Elgg 7.x installed successfully.' . PHP_EOL;
    " 2>&1 || echo "Install completed (check for errors above)."

    echo "Activating plugins..."
    php -r "
        require_once 'vendor/autoload.php';
        \$app = \Elgg\Application::getInstance();
        \$app->bootCore();
        _elgg_services()->plugins->generateEntities();

        // Fixed-point activation: keep trying until no more plugins can be
        // activated. Handles transitive core/dep chains (A needs B needs C)
        // without manual ordering. setPriority('last') satisfies
        // plugin.dependencies position-after at activation time.
        \$max_rounds = 10;
        for (\$round = 0; \$round < \$max_rounds; \$round++) {
            \$activated_this_round = 0;
            foreach (elgg_get_plugins('inactive') as \$p) {
                if (\$p->getID() === '${PLUGIN_ID}') continue; // activate under-test plugin last
                try {
                    \$p->setPriority('last');
                    \$p->activate();
                    echo '  + ' . \$p->getID() . PHP_EOL;
                    \$activated_this_round++;
                } catch (\Throwable \$e) {
                    // not yet activatable — retry next round
                }
            }
            if (\$activated_this_round === 0) break;
        }

        \$plugin = elgg_get_plugin_from_id('${PLUGIN_ID}');
        if (!\$plugin) {
            echo 'ERROR: plugin ${PLUGIN_ID} not found at /var/www/html/mod/${PLUGIN_ID}' . PHP_EOL;
            exit(1);
        }
        if (\$plugin->isActive()) {
            echo 'Plugin ${PLUGIN_ID} already active.' . PHP_EOL;
        } else {
            try {
                \$plugin->setPriority('last');
                \$plugin->activate();
                echo 'Plugin ${PLUGIN_ID} activated.' . PHP_EOL;
            } catch (\Throwable \$e) {
                echo 'FAILED to activate ${PLUGIN_ID}: ' . \$e->getMessage() . PHP_EOL;
                exit(1);
            }
        }
    " 2>&1 || echo "Plugin activation completed (check for errors above)."

    # elgg_clear_caches() alone doesn't purge the filesystem cache dirs that Elgg
    # wrote during bootCore() *before* the plugin was activated. Those stale dirs
    # shadow the newly-activated plugin's view paths. Wipe them explicitly.
    php -r "
        require_once 'vendor/autoload.php';
        \$app = \Elgg\Application::getInstance();
        \$app->bootCore();
        elgg_clear_caches();
        echo 'Caches cleared.' . PHP_EOL;
    " 2>&1 || true
    rm -rf "${ELGG_DATA_ROOT:-/var/www/data/}cache/fastcache" \
           "${ELGG_DATA_ROOT:-/var/www/data/}cache/localfastcache" 2>/dev/null || true
    chown -R www-data:www-data "${ELGG_DATA_ROOT:-/var/www/data/}"
    chmod -R u+rwX,g+rX,o+rX "${ELGG_DATA_ROOT:-/var/www/data/}"

    if [ "${ELGG_SEED_LIMIT:-5}" -gt 0 ]; then
        echo "Seeding database (limit: ${ELGG_SEED_LIMIT:-5})..."
        php vendor/elgg/elgg/elgg-cli database:seed --limit "${ELGG_SEED_LIMIT:-5}" --no-interaction 2>&1 || \
            echo "Seeding completed (check for errors above)."
    fi

    touch /var/www/html/.elgg-installed
    echo "Elgg 7.x setup complete."
fi

echo "Starting Apache..."
exec apache2-foreground
