FROM php:8.1-cli

RUN apt-get update && \
    apt-get install -y git unzip python3 python3-pip && \
    pip3 install --break-system-packages --no-cache-dir semgrep && \
    rm -rf /var/lib/apt/lists/*

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /app

# Install composer dependencies first (cache layer). composer.lock is
# gitignored, so we only COPY composer.json and let composer resolve
# and lock inside the image.
COPY composer.json ./
RUN composer install --no-interaction --prefer-dist --no-dev

# Copy application code
COPY src/ src/
COPY bin/ bin/
COPY rules/ rules/

ENTRYPOINT ["php"]
