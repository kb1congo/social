
FROM php:8.4-fpm-bookworm

# Installation de Composer depuis son image officielle.
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

# Extensions et outils nécessaires.
RUN apt-get update \
    && apt-get install -y --no-install-recommends \
       git unzip libzip-dev \
    && docker-php-ext-install -j"$(nproc)" pdo_mysql opcache \
    && apt-get clean \
    && rm -rf /var/lib/apt/lists/*

WORKDIR /var/www/html

COPY docker/php/php.ini /usr/local/etc/php/conf.d/zz-app.ini

EXPOSE 9000
