# syntax=docker/dockerfile:1.7
FROM composer:2 AS vendor
WORKDIR /app
# The composer image tracks the newest PHP; the runtime below is PHP 8.5.
# Ignore only upper-bound PHP constraints so lower bounds are still enforced.
ENV COMPOSER_IGNORE_PLATFORM_REQ=php+
COPY composer.json composer.lock ./
RUN composer install --no-dev --no-scripts --no-autoloader --prefer-dist --no-interaction
COPY . .
RUN composer dump-autoload --optimize --no-dev --classmap-authoritative

FROM php:8.5-fpm-alpine AS runtime
RUN apk add --no-cache icu-libs libpq libzip oniguruma \
    && apk add --no-cache --virtual .build-deps icu-dev postgresql-dev libzip-dev oniguruma-dev $PHPIZE_DEPS \
    && docker-php-ext-install -j$(nproc) pdo_pgsql bcmath intl zip pcntl \
    && apk del .build-deps
WORKDIR /var/www/html
COPY --from=vendor /app /var/www/html
COPY docker/php.ini /usr/local/etc/php/conf.d/zz-filterdesk.ini
COPY docker/php-fpm-pool.conf /usr/local/etc/php-fpm.d/zz-filterdesk.conf
# Worker pool defaults (docker/php-fpm-pool.conf); override in .env.
ENV PHP_FPM_MAX_CHILDREN=20 PHP_FPM_START_SERVERS=4 PHP_FPM_MIN_SPARE_SERVERS=2 PHP_FPM_MAX_SPARE_SERVERS=8
COPY docker/entrypoint.sh /entrypoint.sh
RUN mkdir -p storage/framework/{cache,sessions,views} storage/logs bootstrap/cache \
    && chown -R www-data:www-data storage bootstrap/cache \
    && chmod +x /entrypoint.sh
USER www-data
ENTRYPOINT ["/entrypoint.sh"]
CMD ["php-fpm"]

FROM nginx:1.29-alpine AS webserver
COPY docker/nginx.conf /etc/nginx/conf.d/default.conf
COPY --from=vendor /app/public /var/www/html/public
