# syntax=docker/dockerfile:1

# --- Composer dependencies -------------------------------------------------
FROM composer:2 AS vendor
WORKDIR /app
COPY composer.json composer.lock ./
RUN composer install --no-dev --no-scripts --no-autoloader --no-interaction --prefer-dist
COPY . .
RUN composer dump-autoload --no-dev --optimize --classmap-authoritative

# --- Runtime (PHP-FPM) ------------------------------------------------------
FROM php:8.3-fpm-alpine AS app

RUN apk add --no-cache icu-libs libzip libpng freetype libjpeg-turbo postgresql-libs fcgi \
    && apk add --no-cache --virtual .build-deps $PHPIZE_DEPS icu-dev libzip-dev libpng-dev freetype-dev libjpeg-turbo-dev postgresql-dev \
    && docker-php-ext-configure gd --with-freetype --with-jpeg \
    && docker-php-ext-install -j"$(nproc)" pdo_pgsql intl zip gd opcache bcmath \
    && apk del .build-deps

COPY docker/php/php.ini /usr/local/etc/php/conf.d/zz-shop.ini
COPY docker/php/fpm.conf /usr/local/etc/php-fpm.d/zz-shop.conf

WORKDIR /var/www/html
COPY --from=vendor --chown=www-data:www-data /app /var/www/html
RUN rm -rf tests/browser/node_modules \
    && mkdir -p storage/framework/cache storage/framework/sessions storage/framework/views storage/logs storage/invoices storage/app/private/products \
    && chown -R www-data:www-data storage bootstrap/cache

USER www-data
EXPOSE 9000
HEALTHCHECK --interval=30s --timeout=5s --retries=3 CMD SCRIPT_FILENAME=/var/www/html/public/index.php REQUEST_URI=/health REQUEST_METHOD=GET cgi-fcgi -bind -connect 127.0.0.1:9000 | grep -q '"status":"ok"' || exit 1
CMD ["php-fpm"]
