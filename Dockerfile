FROM composer:2 AS composer
FROM php:8.3-apache

RUN apt-get update \
    && apt-get install -y --no-install-recommends libsqlite3-dev unzip \
    && docker-php-ext-install pdo_sqlite \
    && rm -rf /var/lib/apt/lists/*

COPY --from=composer /usr/bin/composer /usr/local/bin/composer
WORKDIR /var/www
COPY composer.json composer.lock /var/www/
RUN composer install --no-dev --no-interaction --no-progress --prefer-dist

WORKDIR /var/www/html
COPY app/ /var/www/html/
COPY docker/entrypoint.sh /usr/local/bin/bni-dach-entrypoint
COPY docker/worker-entrypoint.sh /usr/local/bin/crosschapp-worker-entrypoint

EXPOSE 80
