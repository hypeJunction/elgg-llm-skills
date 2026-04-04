#!/bin/bash
set -e

# Wait for MySQL to be ready
echo "Waiting for MySQL..."
while ! mysqladmin ping -h"${ELGG_DB_HOST:-db}" -u"${ELGG_DB_USER:-elgg}" -p"${ELGG_DB_PASS:-elgg}" --silent 2>/dev/null; do
    sleep 1
done
echo "MySQL is ready."

# Check if Elgg is already installed
if [ ! -f /var/www/html/.elgg-installed ]; then
    echo "Installing Elgg..."

    cd /var/www/html

    # Run Elgg CLI installer
    php vendor/bin/elgg-cli install \
        --dbhost="${ELGG_DB_HOST:-db}" \
        --dbuser="${ELGG_DB_USER:-elgg}" \
        --dbpass="${ELGG_DB_PASS:-elgg}" \
        --dbname="${ELGG_DB_NAME:-elgg}" \
        --dbprefix="elgg_" \
        --sitename="Elgg Migration Test" \
        --siteemail="admin@example.com" \
        --wwwroot="${ELGG_SITE_URL:-http://localhost:8080/}" \
        --dataroot="${ELGG_DATA_ROOT:-/var/www/data/}" \
        --displayname="Admin" \
        --email="${ELGG_ADMIN_EMAIL:-admin@example.com}" \
        --username="admin" \
        --password="${ELGG_ADMIN_PASSWORD:-admin12345}" \
        2>&1 || true

    touch /var/www/html/.elgg-installed
    echo "Elgg installed."
fi

# Activate any plugins in mod/ directory
for plugin_dir in /var/www/html/mod/*/; do
    plugin_name=$(basename "$plugin_dir")
    # Skip core plugins (they come with Elgg)
    if [ -f "$plugin_dir/manifest.xml" ] || [ -f "$plugin_dir/elgg-plugin.php" ]; then
        echo "Activating plugin: $plugin_name"
        php vendor/bin/elgg-cli plugins:activate "$plugin_name" 2>&1 || echo "  Warning: could not activate $plugin_name"
    fi
done

# Start Apache
echo "Starting Apache..."
exec apache2-foreground
