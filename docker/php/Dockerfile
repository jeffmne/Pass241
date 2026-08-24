FROM php:8.4-fpm

COPY --from=mlocati/php-extension-installer:latest /usr/bin/install-php-extensions /usr/local/bin/
COPY --from=composer:2 /usr/bin/composer /usr/local/bin/composer

RUN apt-get update \
    && apt-get install -y --no-install-recommends \
        git \
        unzip \
        postgresql-client \
    && rm -rf /var/lib/apt/lists/*

# pdo_pgsql : PostgreSQL (section 60)
# redis     : cache, queues, holds de stock (section 17.1)
# bcmath    : calculs de commission sans flottant (section 21.2)
# gd        : génération des QR codes et images de billets (section 18)
# pcntl     : Horizon (section 60)
RUN install-php-extensions \
        pdo_pgsql \
        pgsql \
        redis \
        intl \
        zip \
        bcmath \
        gd \
        exif \
        pcntl \
        opcache

WORKDIR /var/www/html
