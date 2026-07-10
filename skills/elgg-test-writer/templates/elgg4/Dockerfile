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
# Apache's stock mods-available/alias.conf maps /icons/ to /usr/share/apache2/icons/,
# so ANY Elgg route under /icons/ (hypeicons registers /icons/{segments}) is answered by
# Apache's directory alias and never reaches Elgg — a permanent 404 that looks like a
# plugin bug. The decorative icon directory is useless here; drop the alias.
RUN sed -i 's|^\(\s*\)Alias /icons/|\1#Alias /icons/|' /etc/apache2/mods-available/alias.conf


# Install Composer
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

# Set working directory
WORKDIR /var/www/html

# Create Elgg data directory
RUN mkdir -p /var/www/data && chown www-data:www-data /var/www/data

# Install Elgg 4.x
COPY elgg-composer.json /var/www/html/composer.json
# Elgg 2.x/3.x/4.x pin PHP < 8.0, and current Composer refuses to parse tar/phar
# archives on those versions ("Refusing to parse a tar/phar archive on PHP < 8.0").
# Every npm-asset/* dependency pulled via asset-packagist ships as a .tgz, so the
# install aborts with "Install of npm-asset/... failed". Upgrading PHP is not an
# option here — the tier exists to run the old PHP. The override is acceptable in
# this DISPOSABLE TEST IMAGE: the dists come from packagist/registry.npmjs.org over
# TLS and Composer still verifies their recorded hashes. Do not carry it into a
# production image.
RUN cd /var/www/html && COMPOSER_ALLOW_UNSAFE_PHAR_METADATA=1 composer install --no-interaction --prefer-dist

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
