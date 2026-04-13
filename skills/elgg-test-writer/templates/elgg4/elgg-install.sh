#!/bin/bash
set -e

# Per-plugin Elgg 4.x install + activation script.
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

if [ ! -f /var/www/html/.elgg-installed ]; then
    echo "Installing Elgg 4.x..."

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
\$CONFIG->wwwroot = '${ELGG_SITE_URL:-http://localhost:8480/}';
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
            'sitename' => 'Elgg 4.x Plugin Test',
            'siteemail' => '${ELGG_ADMIN_EMAIL:-admin@example.com}',
            'wwwroot' => '${ELGG_SITE_URL:-http://localhost:8480/}',
            'dataroot' => '${ELGG_DATA_ROOT:-/var/www/data/}',
            'displayname' => 'Admin',
            'email' => '${ELGG_ADMIN_EMAIL:-admin@example.com}',
            'username' => 'admin',
            'password' => '${ELGG_ADMIN_PASSWORD:-admin12345}',
        ];

        \$installer = new \ElggInstaller();
        \$installer->batchInstall(\$params);
        echo 'Elgg 4.x installed successfully.' . PHP_EOL;
    " 2>&1 || echo "Install completed (check for errors above)."

    # Fetch dep plugins not already present in mod/.
    # deps.txt format (committed): <plugin-id> [<git-url>[#<branch>]]
    # deps.local.txt format (gitignored): <plugin-id> <local-path>
    #   Local paths are resolved by scaffold-docker.sh into volume mounts, so
    #   at container start they already appear in mod/ — no action needed here.
    #   deps.local.txt is never read by this script.
    DEPS_TXT="/var/www/html/mod/${PLUGIN_ID}/tests/deps.txt"
    if [ -f "$DEPS_TXT" ]; then
        while IFS= read -r line; do
            # skip blank lines and comments
            line="${line%%#*}"          # strip inline comments
            line="${line#"${line%%[![:space:]]*}"}"  # ltrim
            line="${line%"${line##*[![:space:]]}"}"  # rtrim
            [ -z "$line" ] && continue

            dep_id="${line%% *}"
            dep_source="${line#* }"
            [ "$dep_source" = "$dep_id" ] && dep_source=""  # no source field

            dep_mod="/var/www/html/mod/$dep_id"
            if [ -d "$dep_mod" ]; then
                echo "Dep $dep_id: already in mod/ (volume-mounted)"
            elif [ -n "$dep_source" ]; then
                # split url#branch
                dep_url="${dep_source%%#*}"
                dep_branch="${dep_source#*#}"
                [ "$dep_branch" = "$dep_source" ] && dep_branch=""
                echo "Dep $dep_id: cloning from $dep_url${dep_branch:+ @ $dep_branch}..."
                if [ -n "$dep_branch" ]; then
                    git clone --depth=1 --branch "$dep_branch" "$dep_url" "$dep_mod"
                else
                    git clone --depth=1 "$dep_url" "$dep_mod"
                fi
            else
                echo "WARNING: dep $dep_id not in mod/ and no source URL in deps.txt — skipping"
            fi
        done < "$DEPS_TXT"
    fi

    echo "Activating plugins..."
    php -r "
        require_once 'vendor/autoload.php';
        \$app = \Elgg\Application::getInstance();
        \$app->bootCore();
        _elgg_services()->plugins->generateEntities();

        // Activate dep plugins listed in tests/deps.txt (in order, before the main plugin).
        \$deps_file = '/var/www/html/mod/${PLUGIN_ID}/tests/deps.txt';
        if (file_exists(\$deps_file)) {
            \$lines = file(\$deps_file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
            foreach (\$lines as \$line) {
                \$line = trim(preg_replace('/#.*/', '', \$line));
                if (\$line === '') continue;
                \$dep_id = strtok(\$line, ' ');
                \$dep = elgg_get_plugin_from_id(\$dep_id);
                if (!\$dep) {
                    echo 'WARNING: dep plugin ' . \$dep_id . ' not found in mod/ — skipping.' . PHP_EOL;
                    continue;
                }
                if (\$dep->isActive()) {
                    echo 'Dep plugin ' . \$dep_id . ' already active.' . PHP_EOL;
                    continue;
                }
                try {
                    \$dep->activate();
                    echo 'Dep plugin ' . \$dep_id . ' activated.' . PHP_EOL;
                } catch (\Throwable \$e) {
                    echo 'FAILED to activate dep ' . \$dep_id . ': ' . \$e->getMessage() . PHP_EOL;
                    exit(1);
                }
            }
        }

        // Activate the main plugin.
        \$plugin = elgg_get_plugin_from_id('${PLUGIN_ID}');
        if (!\$plugin) {
            echo 'ERROR: plugin ${PLUGIN_ID} not found at /var/www/html/mod/${PLUGIN_ID}' . PHP_EOL;
            exit(1);
        }
        if (\$plugin->isActive()) {
            echo 'Plugin ${PLUGIN_ID} already active.' . PHP_EOL;
        } else {
            try {
                \$plugin->activate();
                echo 'Plugin ${PLUGIN_ID} activated.' . PHP_EOL;
            } catch (\Throwable \$e) {
                echo 'FAILED to activate ${PLUGIN_ID}: ' . \$e->getMessage() . PHP_EOL;
                exit(1);
            }
        }
    " 2>&1 || echo "Plugin activation completed (check for errors above)."

    touch /var/www/html/.elgg-installed
    echo "Elgg 4.x setup complete."
fi

echo "Starting Apache..."
exec apache2-foreground
