FROM php:8.3-cli

WORKDIR /var/www/html

# System packages and PHP extensions Laravel needs.
RUN apt-get update \
    && apt-get install -y --no-install-recommends git unzip libpq-dev libzip-dev libicu-dev \
    && docker-php-ext-install pdo_pgsql pgsql mbstring xml curl zip bcmath intl opcache \
    && apt-get clean \
    && rm -rf /var/lib/apt/lists/*

# Composer from the official image.
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

# Install PHP dependencies first so the layer is cached until composer files change.
COPY composer.json composer.lock ./
RUN composer install --no-interaction --no-scripts --no-autoloader \
    && composer dump-autoload --optimize

COPY . .

RUN mkdir -p storage/framework/cache storage/framework/sessions storage/framework/views storage/logs bootstrap/cache \
    && chown -R www-data:www-data storage bootstrap/cache

# APP_KEY comes from the environment at runtime (see docker-compose.yml).
# Generate one with: php artisan key:generate --show

EXPOSE 8000

CMD ["php", "artisan", "serve", "--host=0.0.0.0", "--port=8000"]
