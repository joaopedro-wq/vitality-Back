FROM php:8.4-cli-bookworm

RUN apt-get update \
    && apt-get install -y --no-install-recommends libonig-dev libpq-dev libzip-dev unzip \
    && docker-php-ext-install mbstring pdo_pgsql zip \
    && rm -rf /var/lib/apt/lists/*

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /app

COPY composer.json composer.lock ./
RUN composer install --no-dev --no-interaction --prefer-dist --optimize-autoloader --no-scripts

COPY . ./
RUN mkdir -p storage/framework/cache storage/framework/sessions storage/framework/views storage/logs bootstrap/cache \
    && composer dump-autoload --no-dev --optimize

EXPOSE 8080

CMD ["sh", "-c", "mkdir -p storage/framework/cache storage/framework/sessions storage/framework/views storage/logs && php artisan storage:link --force && php artisan config:cache && php artisan db:seed --force && php artisan foods:import-usda --dataset=foundation && php artisan serve --host=0.0.0.0 --port=${PORT:-8080}"]
