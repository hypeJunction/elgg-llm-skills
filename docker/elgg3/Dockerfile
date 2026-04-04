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
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

# Set working directory
WORKDIR /var/www/html

# Create Elgg data directory
RUN mkdir -p /var/www/data && chown www-data:www-data /var/www/data

# Install Elgg via Composer
RUN composer create-project elgg/starter-project:^3.3 /var/www/html --no-interaction --prefer-dist

# Apache config: allow .htaccess overrides
RUN sed -i 's/AllowOverride None/AllowOverride All/g' /etc/apache2/apache2.conf

# Copy install script
COPY elgg-install.sh /usr/local/bin/elgg-install.sh
RUN chmod +x /usr/local/bin/elgg-install.sh

# Copy settings template
COPY settings.php /var/www/html/elgg-config/settings.php

EXPOSE 80
