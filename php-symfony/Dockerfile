FROM php:8.3-cli-alpine

# libpq is the PostgreSQL client runtime library needed by pdo_pgsql.so at load
# time. It must be installed explicitly so that removing postgresql-dev (which
# only provides the compile-time headers) does not strip libpq from the image.
RUN apk add --no-cache git unzip libpq postgresql-dev \
    && docker-php-ext-install pdo pdo_pgsql \
    && apk del postgresql-dev

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /app

# Install dependencies first (cached layer)
COPY composer.json composer.lock* ./
RUN composer install --no-dev --no-scripts --no-autoloader --no-interaction \
        --ignore-platform-req=ext-pdo_pgsql

# Copy app source
COPY . .

# Generate optimised autoloader and prepare cache dir
RUN composer dump-autoload --optimize --no-dev \
    && mkdir -p var/cache var/log \
    && chmod -R 777 var/

COPY entrypoint.sh /entrypoint.sh
RUN chmod +x /entrypoint.sh

EXPOSE 8000
ENTRYPOINT ["/entrypoint.sh"]
