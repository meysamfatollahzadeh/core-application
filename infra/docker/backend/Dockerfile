FROM composer:2.10.3 AS composer
FROM php:8.4.21-cli-bookworm@sha256:ca4b9f44c281f6214a08313185b306368b9ec1e9a73b54b4625774a254106e1d
RUN apt-get update && apt-get install -y --no-install-recommends libpq-dev libzip-dev libicu-dev unzip git \
    && docker-php-ext-install pdo_pgsql zip intl pcntl bcmath \
    && rm -rf /var/lib/apt/lists/*
COPY --from=composer /usr/bin/composer /usr/local/bin/composer
WORKDIR /opt/dependencies
COPY backend/composer.json backend/composer.lock ./
RUN composer install --no-interaction --prefer-dist --no-scripts
WORKDIR /app
COPY infra/docker/backend/entrypoint.sh /usr/local/bin/development-entrypoint
ENTRYPOINT ["development-entrypoint"]
CMD ["php", "artisan", "serve", "--host=0.0.0.0", "--port=8000"]
