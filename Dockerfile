FROM php:8.3-apache

RUN apt-get update \
    && apt-get install -y --no-install-recommends libsqlite3-dev \
    && docker-php-ext-install pdo_sqlite \
    && rm -rf /var/lib/apt/lists/*

WORKDIR /var/www/html

COPY app/ /var/www/html/
COPY docker/entrypoint.sh /usr/local/bin/bni-dach-entrypoint
COPY docker/worker-entrypoint.sh /usr/local/bin/crosschapp-worker-entrypoint

EXPOSE 80
