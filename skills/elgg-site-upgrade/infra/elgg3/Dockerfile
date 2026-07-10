FROM php:7.4-apache

# Install system dependencies
RUN apt-get update && apt-get install -y \
    libfreetype6-dev \
    libjpeg62-turbo-dev \
    libpng-dev \
    libicu-dev \
    libxml2-dev \
    libzip-dev \
    unzip \
    git \
    curl \
    && rm -rf /var/lib/apt/lists/*

# Install PHP extensions required by Elgg
RUN docker-php-ext-configure gd --with-freetype --with-jpeg \
    && docker-php-ext-install -j$(nproc) \
        gd \
        intl \
        mysqli \
        pdo_mysql \
        xml \
        zip \
        opcache

# Enable Apache mod_rewrite
RUN a2enmod rewrite

# Install Composer
# Composer 2.2 (LTS) on purpose: composer/installers v1 requires Plugin API ^1.0,
# but Composer 2.3+ ships Plugin API 2.x and SILENTLY SKIPS the plugin, so every
# elgg-plugin package lands in vendor/ instead of mod/. Elgg 3.x has a hard chain
# elgg/elgg 3.3 -> elgg/login_as ~2.1 -> composer/installers ^1.0.8 with no newer
# login_as. See SKILL.md "composer/installers v1 vs Composer 2.3+".
COPY --from=composer:2.2 /usr/bin/composer /usr/bin/composer

# Set working directory
WORKDIR /var/www/html

# Create Elgg data directory
RUN mkdir -p /var/www/data && chown www-data:www-data /var/www/data

# Install Elgg 3.x with asset-packagist for bower-asset dependencies
COPY elgg-composer.json /var/www/html/composer.json
RUN cd /var/www/html && composer install --no-interaction --prefer-dist

# Register Elgg coding standard with phpcs
RUN vendor/bin/phpcs --config-set installed_paths /var/www/html/vendor/elgg/sniffs/src

# Apache config: allow .htaccess overrides
RUN sed -i 's/AllowOverride None/AllowOverride All/g' /etc/apache2/apache2.conf

# Remove any pre-existing settings so installer can create them
RUN rm -f /var/www/html/elgg-config/settings.php

# Create web root bootstrap
COPY index.php /var/www/html/index.php
RUN cp vendor/elgg/elgg/install/config/htaccess.dist /var/www/html/.htaccess \
    && mkdir -p /var/www/html/mod

# Copy install script
COPY elgg-install.sh /usr/local/bin/elgg-install.sh
RUN chmod +x /usr/local/bin/elgg-install.sh

EXPOSE 80
