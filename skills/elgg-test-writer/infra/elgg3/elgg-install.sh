#!/bin/bash
set -e

# Wait for MySQL to be ready (using PHP since mysqladmin isn't available)
echo "Waiting for MySQL..."
until php -r "new PDO('mysql:host=${ELGG_DB_HOST:-db}', '${ELGG_DB_USER:-elgg}', '${ELGG_DB_PASS:-elgg}');" 2>/dev/null; do
    sleep 1
done
echo "MySQL is ready."

# Is this database already an Elgg install? The chain harness (verify-migration-chain.sh)
# preseeds the NEXT tier's DB volume from the PREVIOUS tier and expects this container to
# SKIP installation. The old gate was the file /var/www/html/.elgg-installed, which lives
# in the image layer, not a volume — so a fresh tier container never has it, re-ran
# batchInstall over the carried-forward data, and the chain aborted. Ask the DATABASE.
ELGG_PRESEEDED=0
if php -r "
    \$p = new PDO('mysql:host=${ELGG_DB_HOST:-db};dbname=${ELGG_DB_NAME:-elgg}', '${ELGG_DB_USER:-elgg}', '${ELGG_DB_PASS:-elgg}');
    \$q = \$p->query(\"SHOW TABLES LIKE 'elgg_entities'\");
    exit(\$q && \$q->fetchAll() ? 0 : 1);
" 2>/dev/null; then
    ELGG_PRESEEDED=1
    echo "Existing Elgg schema detected (elgg_entities present) — skipping batchInstall."
fi

cd /var/www/html

# Check if Elgg is already installed
if [ ! -f /var/www/html/.elgg-installed ]; then
    echo "Installing Elgg..."

    # Create settings.php (must use 'global $CONFIG' for Elgg to detect it)
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
\$CONFIG->wwwroot = '${ELGG_SITE_URL:-http://localhost/}';
\$CONFIG->cacheroot = '${ELGG_DATA_ROOT:-/var/www/data/}cache/';
\$CONFIG->assetroot = '${ELGG_DATA_ROOT:-/var/www/data/}assets/';
SETTINGS_VALUES

    # Run the installer using PHP directly — only when the DB is empty.
    if [ "$ELGG_PRESEEDED" = "1" ]; then
      echo "Skipping batchInstall: the database already carries an Elgg schema."
    else
    php -r "
        require_once 'vendor/autoload.php';

        \$params = [
            'dbuser' => '${ELGG_DB_USER:-elgg}',
            'dbpassword' => '${ELGG_DB_PASS:-elgg}',
            'dbname' => '${ELGG_DB_NAME:-elgg}',
            'dbhost' => '${ELGG_DB_HOST:-db}',
            'dbport' => '3306',
            'dbprefix' => 'elgg_',
            'sitename' => 'Elgg Migration Test',
            'siteemail' => '${ELGG_ADMIN_EMAIL:-admin@example.com}',
            'wwwroot' => '${ELGG_SITE_URL:-http://localhost/}',
            'dataroot' => '${ELGG_DATA_ROOT:-/var/www/data/}',
            'displayname' => 'Admin',
            'email' => '${ELGG_ADMIN_EMAIL:-admin@example.com}',
            'username' => 'admin',
            'password' => '${ELGG_ADMIN_PASSWORD:-admin12345}',
        ];

        \$installer = new \ElggInstaller();
        \$installer->batchInstall(\$params);
        echo 'Elgg installed successfully.' . PHP_EOL;
    " 2>&1 || echo "Install via PHP API completed (check for errors above)."
    fi

    # Activate plugins in priority order
    echo "Activating plugins..."
    PLUGIN_ORDER_FILE="/var/www/html/mod/.plugin-order.txt"
    if [ -f "$PLUGIN_ORDER_FILE" ]; then
        echo "Using ordered activation from .plugin-order.txt"
        php -r "
            require_once 'vendor/autoload.php';
            \$app = \Elgg\Application::getInstance();
            \$app->bootCore();
            _elgg_services()->plugins->generateEntities();
            \$order = file('$PLUGIN_ORDER_FILE', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
            \$activated = 0;
            \$failed = [];
            foreach (\$order as \$id) {
                \$id = trim(\$id);
                if (empty(\$id) || \$id[0] === '#') continue;
                \$plugin = elgg_get_plugin_from_id(\$id);
                if (!\$plugin) continue;
                if (\$plugin->isActive()) { \$activated++; continue; }
                try {
                    \$plugin->activate();
                    \$activated++;
                } catch (\Throwable \$e) {
                    \$failed[] = \$id . ': ' . \$e->getMessage();
                }
            }
            echo \$activated . ' plugin(s) activated.' . PHP_EOL;
            if (!empty(\$failed)) {
                echo count(\$failed) . ' plugin(s) failed:' . PHP_EOL;
                foreach (\$failed as \$f) echo '  - ' . \$f . PHP_EOL;
            }
        " 2>&1 || echo "Plugin activation completed (check for errors above)."
    else
        echo "No .plugin-order.txt found, activating all plugins..."
        php -r "
            require_once 'vendor/autoload.php';
            \$app = \Elgg\Application::getInstance();
            \$app->bootCore();
            _elgg_services()->plugins->generateEntities();
            \$plugins = elgg_get_plugins('inactive');
            \$failed = [];
            foreach (\$plugins as \$plugin) {
                try { \$plugin->activate(); }
                catch (\Throwable \$e) { \$failed[] = \$plugin->getID() . ': ' . \$e->getMessage(); }
            }
            if (empty(\$failed)) { echo 'All plugins activated.' . PHP_EOL; }
            else {
                echo count(\$failed) . ' plugin(s) failed:' . PHP_EOL;
                foreach (\$failed as \$f) echo '  - ' . \$f . PHP_EOL;
            }
        " 2>&1 || echo "Plugin activation completed (check for errors above)."
    fi

    # Hand the data root over to the Apache user. The installer ran as
    # root (entrypoint context) and left every cache subdirectory
    # root-owned, which makes Phpfastcache throw IOException on the
    # first request and the site renders Elgg's "fatal error" stub. Doing
    # this once on first install is enough — Apache (www-data) extends
    # the tree from there.
    chown -R www-data:www-data "${ELGG_DATA_ROOT:-/var/www/data/}"
    chmod -R u+rwX,g+rX,o+rX "${ELGG_DATA_ROOT:-/var/www/data/}"

    touch /var/www/html/.elgg-installed
    echo "Elgg setup complete."
fi

# Start Apache
echo "Starting Apache..."
exec apache2-foreground
