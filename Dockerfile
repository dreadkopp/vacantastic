# Vacantastic — shared base image for the app, reverb, and scheduler services.
#
# The runtime code (including vendor/) is bind-mounted from the host by
# docker-compose.yml; the composer install below only provides a working
# fallback vendor tree inside the image itself.
FROM composer:2 AS composer

FROM php:8.5-alpine

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer
# pcntl: Reverb's server command subscribes to process signals (SIGINT/SIGTERM).
RUN apk add --no-cache autoconf g++ make \
    && docker-php-ext-install pcntl \
    && apk del autoconf g++ make \
    && php -m | grep -q pcntl

WORKDIR /var/www/app

COPY . .

# Create the SQLite database file before composer install: the post-autoload-dump
# script boots the app, whose provider touches the database (WAL pragma).
RUN touch database/database.sqlite

RUN composer install --no-dev --optimize-autoloader --no-interaction \
    && composer clear-cache
