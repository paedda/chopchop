FROM php:8.3-cli-alpine

RUN apk add --no-cache postgresql-dev git unzip \
    && docker-php-ext-install pdo pdo_pgsql \
    && apk del postgresql-dev

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /app

# Install dependencies first (cached layer)
COPY composer.json composer.lock* ./
RUN composer install --no-dev --no-scripts --no-autoloader --no-interaction

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
