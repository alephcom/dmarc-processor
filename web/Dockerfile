# Laravel app image, used for the web, worker (Horizon) and scheduler containers.
FROM composer:2 AS vendor
WORKDIR /app
COPY composer.json composer.lock ./
RUN composer install --no-dev --no-scripts --no-autoloader --prefer-dist --no-interaction
COPY . .
RUN composer dump-autoload --optimize --no-dev --classmap-authoritative

FROM serversideup/php:8.3-fpm-nginx
USER root
RUN install-php-extensions intl
USER www-data
WORKDIR /var/www/html
COPY --chown=www-data:www-data --from=vendor /app /var/www/html
RUN php artisan filament:assets && php artisan storage:link || true
# serversideup images run migrations/caches on start when AUTORUN_ENABLED=true (web container only).
ENV AUTORUN_ENABLED=false \
    PHP_OPCACHE_ENABLE=1 \
    SSL_MODE=off
EXPOSE 8080
