#!/usr/bin/env bash
# gen-elgg-infra.sh — generate per-version Docker infra bundles for the
# elgg-migrate skill. One directory per Elgg major: Dockerfile,
# docker-compose.yml, elgg-composer.json, elgg-install.sh, index.php.
#
# Elgg 3.x and 4.x already exist in skills/elgg-migrate/infra/ and are
# the canonical reference. This script generates the remaining versions
# (2.x, 5.x, 6.x, 7.x) based on the same templates, parameterised by the
# matrix below. Re-running is safe: existing version dirs are skipped
# unless --force is passed.
#
# After generating into skills/elgg-migrate/infra/, the script mirrors
# the new dirs into the sibling skills that bundle the same docker infra.
#
# elgg-js-test-writer receives the AST engine but NOT infra/: it is a
# JS-only skill that delegates its docker stack to elgg-test-writer's
# scaffold (see its SKILL.md, "Skill layout (no shared docker stack)").
set -euo pipefail

ROOT="$(cd "$(dirname "$0")/.." && pwd)"
MIGRATE_INFRA="$ROOT/skills/elgg-migrate/infra"
SIBLING_SKILLS=(elgg-site-upgrade elgg-test-writer elgg-js-test-writer)
INFRA_SIBLINGS=(elgg-site-upgrade elgg-test-writer)
FORCE=0
[[ "${1:-}" == "--force" ]] && FORCE=1

# version | php_base       | mysql_image | elgg_require | debian_archive
# debian_archive=1 when the base image is on an EOL Debian release whose
# apt repos have moved to archive.debian.org (Buster and older).
MATRIX=(
  "2|php:7.2-apache|mysql:5.7|~2.3.0|1"
  "5|php:8.1-apache|mysql:8.0|~5.1.0|0"
  "6|php:8.2-apache|mysql:8.0|~6.1.0|0"
  "7|php:8.3-apache|mysql:8.0|~7.0.0|0"
)

write_dockerfile() {
  local dir="$1" php_base="$2" ver="$3" debian_archive="$4"
  local archive_step="" gd_flags="--with-freetype --with-jpeg"
  if [[ "$debian_archive" == "1" ]]; then
    archive_step=$'# Debian Buster repos are archived since EOL — switch to archive.debian.org\nRUN sed -i \'s|deb.debian.org|archive.debian.org|g; s|security.debian.org|archive.debian.org|g; /buster-updates/d\' /etc/apt/sources.list \\\n && echo \'Acquire::Check-Valid-Until "false";\' > /etc/apt/apt.conf.d/00-archive\n\n'
    # PHP 7.2 era: docker-php-ext-configure gd uses the old-style flags.
    gd_flags="--with-freetype-dir=/usr/include/ --with-jpeg-dir=/usr/include/"
  fi
  cat > "$dir/Dockerfile" <<EOF
FROM $php_base

${archive_step}# Install system dependencies
RUN apt-get update && apt-get install -y \\
    libfreetype6-dev \\
    libjpeg62-turbo-dev \\
    libpng-dev \\
    libicu-dev \\
    libxml2-dev \\
    libzip-dev \\
    unzip \\
    git \\
    curl \\
    && rm -rf /var/lib/apt/lists/*

# Install PHP extensions required by Elgg
RUN docker-php-ext-configure gd $gd_flags \\
    && docker-php-ext-install -j\$(nproc) \\
        gd \\
        intl \\
        mysqli \\
        pdo_mysql \\
        xml \\
        zip \\
        opcache

# Enable Apache mod_rewrite
RUN a2enmod rewrite

# Install Composer
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

# Set working directory
WORKDIR /var/www/html

# Create Elgg data directory
RUN mkdir -p /var/www/data && chown www-data:www-data /var/www/data

# Install Elgg ${ver}.x
COPY elgg-composer.json /var/www/html/composer.json
RUN cd /var/www/html && composer install --no-interaction --prefer-dist

# Apache config: allow .htaccess overrides
RUN sed -i 's/AllowOverride None/AllowOverride All/g' /etc/apache2/apache2.conf

# Remove any pre-existing settings so installer can create them
RUN rm -f /var/www/html/elgg-config/settings.php

# Create web root bootstrap
COPY index.php /var/www/html/index.php
RUN cp vendor/elgg/elgg/install/config/htaccess.dist /var/www/html/.htaccess \\
    && mkdir -p /var/www/html/mod

# Copy install script
COPY elgg-install.sh /usr/local/bin/elgg-install.sh
RUN chmod +x /usr/local/bin/elgg-install.sh

EXPOSE 80
EOF
}

write_compose() {
  local dir="$1" mysql_image="$2"
  # ELGG_PORT and DB_PORT are REQUIRED (no defaults) so parallel
  # migrations and validation runs never collide on the same host
  # port. Set them in a per-job .env file, via the orchestrator
  # (bin/elgg-migrate-run writes one per job), or explicitly in the
  # environment. The failure message points users at the fix.
  cat > "$dir/docker-compose.yml" <<EOF
services:
  elgg:
    build:
      context: .
      dockerfile: Dockerfile
    volumes:
      - elgg-data:/var/www/data
    environment:
      ELGG_DB_HOST: db
      ELGG_DB_NAME: elgg
      ELGG_DB_USER: elgg
      ELGG_DB_PASS: elgg
      ELGG_SITE_URL: "http://localhost:\${ELGG_PORT:?ELGG_PORT must be set (create a .env with ELGG_PORT and DB_PORT, or use bin/elgg-migrate-run)}/"
      ELGG_DATA_ROOT: "/var/www/data/"
      ELGG_ADMIN_EMAIL: "admin@example.com"
      ELGG_ADMIN_PASSWORD: "admin12345"
    ports:
      - "\${ELGG_PORT}:80"
    depends_on:
      db:
        condition: service_healthy
    entrypoint: /usr/local/bin/elgg-install.sh

  db:
    image: $mysql_image
    environment:
      MYSQL_DATABASE: elgg
      MYSQL_USER: elgg
      MYSQL_PASSWORD: elgg
      MYSQL_ROOT_PASSWORD: root
    healthcheck:
      test: ["CMD", "mysqladmin", "ping", "-h", "localhost"]
      interval: 5s
      timeout: 5s
      retries: 10
    volumes:
      - db-data:/var/lib/mysql
    ports:
      - "\${DB_PORT:?DB_PORT must be set (create a .env with ELGG_PORT and DB_PORT, or use bin/elgg-migrate-run)}:3306"

  # Node.js + Playwright for running Playwright and Vitest tests inside Docker.
  # Activated via --profile test so it doesn't start with \`docker compose up\`.
  node:
    image: mcr.microsoft.com/playwright:v1.49.0-noble
    volumes:
      - \${PLUGINS_DIR:?PLUGINS_DIR must be set by the orchestrator}:/plugins
    working_dir: /plugins
    environment:
      ELGG_BASE_URL: "http://elgg"
      ELGG_PORT: "80"
      ELGG_DB_HOST: db
      ELGG_DB_PORT: "3306"
      ELGG_DB_USER: elgg
      ELGG_DB_PASS: elgg
      ELGG_DB_NAME: elgg
    depends_on:
      - elgg
    profiles:
      - test

volumes:
  elgg-data:
  db-data:
EOF
}

write_composer() {
  local dir="$1" elgg_req="$2"
  cat > "$dir/elgg-composer.json" <<EOF
{
    "name": "elgg/test-site",
    "type": "project",
    "minimum-stability": "dev",
    "prefer-stable": true,
    "require": {
        "elgg/elgg": "$elgg_req",
        "composer/installers": "^1.0 || ^2.0"
    },
    "replace": {
        "roave/security-advisories": "dev-master"
    },
    "repositories": [
        {
            "type": "composer",
            "url": "https://asset-packagist.org"
        }
    ],
    "config": {
        "allow-plugins": {
            "composer/installers": true
        },
        "audit": {
            "block-insecure": false
        }
    }
}
EOF
}

write_index() {
  local dir="$1"
  cat > "$dir/index.php" <<'EOF'
<?php

require_once __DIR__ . '/vendor/autoload.php';
return \Elgg\Application::index();
EOF
}

write_install_sh() {
  local dir="$1" ver="$2"
  # Install.sh is based on the elgg4 script. It already handles Elgg 3-7
  # because the ElggInstaller API, settings.php layout, and plugin
  # activation APIs are identical across those majors. For Elgg 2.x the
  # ElggInstaller API is the same but settings live under elgg-config/
  # in 2.3+ (the version this skill targets), so the script also works.
  cat > "$dir/elgg-install.sh" <<EOF
#!/bin/bash
set -e

# Wait for MySQL to be ready
echo "Waiting for MySQL..."
until php -r "new PDO('mysql:host=\${ELGG_DB_HOST:-db}', '\${ELGG_DB_USER:-elgg}', '\${ELGG_DB_PASS:-elgg}');" 2>/dev/null; do
    sleep 1
done
echo "MySQL is ready."

cd /var/www/html

# Check if Elgg is already installed
if [ ! -f /var/www/html/.elgg-installed ]; then
    echo "Installing Elgg ${ver}.x..."

    # Create settings.php
    mkdir -p elgg-config
    cat > elgg-config/settings.php <<'SETTINGS_TEMPLATE'
<?php
global \$CONFIG;
if (!isset(\$CONFIG)) {
    \$CONFIG = new \\stdClass;
}
SETTINGS_TEMPLATE

    cat >> elgg-config/settings.php <<SETTINGS_VALUES
\\\$CONFIG->dbuser = '\${ELGG_DB_USER:-elgg}';
\\\$CONFIG->dbpass = '\${ELGG_DB_PASS:-elgg}';
\\\$CONFIG->dbname = '\${ELGG_DB_NAME:-elgg}';
\\\$CONFIG->dbhost = '\${ELGG_DB_HOST:-db}';
\\\$CONFIG->dbport = '3306';
\\\$CONFIG->dbprefix = 'elgg_';
\\\$CONFIG->dbencoding = 'utf8mb4';
\\\$CONFIG->dataroot = '\${ELGG_DATA_ROOT:-/var/www/data/}';
\\\$CONFIG->wwwroot = '\${ELGG_SITE_URL:-http://localhost/}';
\\\$CONFIG->cacheroot = '\${ELGG_DATA_ROOT:-/var/www/data/}cache/';
\\\$CONFIG->assetroot = '\${ELGG_DATA_ROOT:-/var/www/data/}assets/';
SETTINGS_VALUES

    # Run the installer
    php -r "
        require_once 'vendor/autoload.php';

        \\\$params = [
            'dbuser' => '\${ELGG_DB_USER:-elgg}',
            'dbpassword' => '\${ELGG_DB_PASS:-elgg}',
            'dbname' => '\${ELGG_DB_NAME:-elgg}',
            'dbhost' => '\${ELGG_DB_HOST:-db}',
            'dbport' => '3306',
            'dbprefix' => 'elgg_',
            'sitename' => 'Elgg ${ver}.x Migration Test',
            'siteemail' => '\${ELGG_ADMIN_EMAIL:-admin@example.com}',
            'wwwroot' => '\${ELGG_SITE_URL:-http://localhost/}',
            'dataroot' => '\${ELGG_DATA_ROOT:-/var/www/data/}',
            'displayname' => 'Admin',
            'email' => '\${ELGG_ADMIN_EMAIL:-admin@example.com}',
            'username' => 'admin',
            'password' => '\${ELGG_ADMIN_PASSWORD:-admin12345}',
        ];

        \\\$installer = new \\ElggInstaller();
        \\\$installer->batchInstall(\\\$params);
        echo 'Elgg ${ver}.x installed successfully.' . PHP_EOL;
    " 2>&1 || echo "Install completed (check for errors above)."

    # Activate plugins in priority order
    echo "Activating plugins..."
    PLUGIN_ORDER_FILE="/var/www/html/mod/.plugin-order.txt"
    if [ -f "\$PLUGIN_ORDER_FILE" ]; then
        echo "Using ordered activation from .plugin-order.txt"
        php -r "
            require_once 'vendor/autoload.php';
            \\\$app = \\Elgg\\Application::getInstance();
            \\\$app->bootCore();
            _elgg_services()->plugins->generateEntities();
            \\\$order = file('\$PLUGIN_ORDER_FILE', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
            \\\$activated = 0;
            \\\$failed = [];
            foreach (\\\$order as \\\$id) {
                \\\$id = trim(\\\$id);
                if (empty(\\\$id) || \\\$id[0] === '#') continue;
                \\\$plugin = elgg_get_plugin_from_id(\\\$id);
                if (!\\\$plugin) { echo 'Plugin not found: ' . \\\$id . PHP_EOL; continue; }
                if (\\\$plugin->isActive()) { \\\$activated++; continue; }
                try {
                    \\\$plugin->activate();
                    \\\$activated++;
                    echo '  + ' . \\\$id . PHP_EOL;
                } catch (\\Throwable \\\$e) {
                    \\\$failed[] = \\\$id . ': ' . \\\$e->getMessage();
                }
            }
            echo \\\$activated . ' plugin(s) activated.' . PHP_EOL;
            if (!empty(\\\$failed)) {
                echo count(\\\$failed) . ' plugin(s) failed:' . PHP_EOL;
                foreach (\\\$failed as \\\$f) echo '  - ' . \\\$f . PHP_EOL;
            }
        " 2>&1 || echo "Plugin activation completed (check for errors above)."
    else
        echo "No .plugin-order.txt found, activating all plugins..."
        php -r "
            require_once 'vendor/autoload.php';
            \\\$app = \\Elgg\\Application::getInstance();
            \\\$app->bootCore();
            _elgg_services()->plugins->generateEntities();
            \\\$plugins = elgg_get_plugins('inactive');
            \\\$failed = [];
            foreach (\\\$plugins as \\\$plugin) {
                try { \\\$plugin->activate(); }
                catch (\\Throwable \\\$e) { \\\$failed[] = \\\$plugin->getID() . ': ' . \\\$e->getMessage(); }
            }
            if (empty(\\\$failed)) { echo 'All plugins activated.' . PHP_EOL; }
            else {
                echo count(\\\$failed) . ' plugin(s) failed:' . PHP_EOL;
                foreach (\\\$failed as \\\$f) echo '  - ' . \\\$f . PHP_EOL;
            }
        " 2>&1 || echo "Plugin activation completed (check for errors above)."
    fi

    touch /var/www/html/.elgg-installed
    echo "Elgg ${ver}.x setup complete."
fi

# Start Apache
echo "Starting Apache..."
exec apache2-foreground
EOF
  chmod +x "$dir/elgg-install.sh"
}

gen_version() {
  local ver="$1" php_base="$2" mysql_image="$3" elgg_req="$4" debian_archive="$5"
  local dir="$MIGRATE_INFRA/elgg${ver}"
  if [[ -d "$dir" && $FORCE -eq 0 ]]; then
    echo "skip elgg${ver} (exists; pass --force to overwrite)"
    return
  fi
  mkdir -p "$dir"
  write_dockerfile "$dir" "$php_base" "$ver" "$debian_archive"
  write_compose    "$dir" "$mysql_image"
  write_composer   "$dir" "$elgg_req"
  write_index      "$dir"
  write_install_sh "$dir" "$ver"
  echo "generated elgg${ver} at $dir"
}

mirror_to_siblings() {
  local ver="$1"
  local src="$MIGRATE_INFRA/elgg${ver}"
  [[ -d "$src" ]] || return
  for s in "${INFRA_SIBLINGS[@]}"; do
    local dst="$ROOT/skills/$s/infra/elgg${ver}"
    mkdir -p "$dst"
    rsync -a --delete "$src/" "$dst/"
    echo "mirrored elgg${ver} -> skills/$s/infra/elgg${ver}"
  done
}

# Mirror the AST engine (bin/migrate.php, bin/scan-frontend-residue.sh, src/,
# rules/, references/, composer.json, phpunit.xml, tests/, infra/migrate/) from
# skills/elgg-migrate/ into each sibling skill so that every skill is atomic and
# self-contained. skills/elgg-migrate/ is the single source of truth; the
# siblings are regenerated from it on every run of this script.
#
# references/ is mirrored WITHOUT --delete: the engine loads
# {removed-functions,removed-function-renames,class-renames,string-renames,
# changed-class-contracts}.json plus migration-failure-catalog.md at runtime
# (PostMigrationVerifier, TestsFirstGate, the DataDriven* rules), while each
# sibling also OWNS files under references/ (elgg-test-writer: ci/,
# regression-classes.md; elgg-site-upgrade: the SQL + runbook). Deleting there
# would destroy skill-local docs. Likewise scan-frontend-residue.sh is exec'd by
# the mirrored tests/ScanFrontendResidueTest.php, so it must travel with them.
mirror_engine_to_siblings() {
  local migrate_root="$ROOT/skills/elgg-migrate"
  for s in "${SIBLING_SKILLS[@]}"; do
    local dst="$ROOT/skills/$s"
    mkdir -p "$dst/bin" "$dst/infra/migrate" "$dst/references"
    rsync -a "$migrate_root/bin/migrate.php"       "$dst/bin/migrate.php"
    rsync -a "$migrate_root/bin/migrate-plugin.sh" "$dst/bin/migrate-plugin.sh"
    rsync -a "$migrate_root/bin/scan-frontend-residue.sh" "$dst/bin/scan-frontend-residue.sh"
    # elgg-site-upgrade's cutover runbook invokes this as a go/no-go gate.
    rsync -a "$migrate_root/bin/check-release-lag.sh" "$dst/bin/check-release-lag.sh"
    rsync -a --delete "$migrate_root/src/"         "$dst/src/"
    rsync -a --delete "$migrate_root/rules/"       "$dst/rules/"
    rsync -a "$migrate_root/references/"           "$dst/references/"
    rsync -a "$migrate_root/composer.json"         "$dst/composer.json"
    rsync -a "$migrate_root/phpunit.xml"           "$dst/phpunit.xml"
    rsync -a --delete "$migrate_root/tests/"       "$dst/tests/"
    rsync -a --delete "$migrate_root/infra/migrate/" "$dst/infra/migrate/"
    echo "mirrored engine -> skills/$s/{bin,src,rules,references,composer.json,phpunit.xml,tests,infra/migrate}"
  done
}

main() {
  for row in "${MATRIX[@]}"; do
    IFS='|' read -r ver php mysql req archive <<< "$row"
    gen_version "$ver" "$php" "$mysql" "$req" "$archive"
    mirror_to_siblings "$ver"
  done

  # Also mirror the existing elgg3 and elgg4 into siblings so they stay
  # self-contained after any changes to the canonical skill infra.
  for ver in 3 4; do
    mirror_to_siblings "$ver"
  done

  mirror_engine_to_siblings
}

main "$@"
