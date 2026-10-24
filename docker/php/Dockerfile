# PHP 8.4: Pest actual lo exige. Se ajusta si composer.lock pide otra.
FROM php:8.4-fpm-bookworm

RUN apt-get update \
 && apt-get install -y --no-install-recommends libpq-dev libicu-dev libzip-dev libgmp-dev unzip git \
 && docker-php-ext-install -j"$(nproc)" pdo_pgsql intl zip bcmath gmp opcache \
 && rm -rf /var/lib/apt/lists/*

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

# Alinea www-data con el usuario del host.
ARG UID=1000
ARG GID=1000
RUN groupmod -o -g "${GID}" www-data && usermod -o -u "${UID}" -g "${GID}" www-data

# Al final: es lo que más cambia, así tocarlo no reconstruye las capas anteriores.
COPY php.ini /usr/local/etc/php/conf.d/zz-pyme.ini

ENV COMPOSER_HOME=/tmp/composer
WORKDIR /var/www/backend