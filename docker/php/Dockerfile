# PHP 8.4: current Pest requires it. Adjust if composer.lock asks for another.
FROM php:8.4-fpm-bookworm

RUN apt-get update \
 && apt-get install -y --no-install-recommends libpq-dev libicu-dev libzip-dev libgmp-dev unzip git \
 && docker-php-ext-install -j"$(nproc)" pdo_pgsql intl zip bcmath gmp opcache \
 && rm -rf /var/lib/apt/lists/*

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

# Align www-data with the host user.
ARG UID=1000
ARG GID=1000
RUN groupmod -o -g "${GID}" www-data && usermod -o -u "${UID}" -g "${GID}" www-data

# Last: it changes the most, so touching it does not rebuild the earlier layers.
COPY php.ini /usr/local/etc/php/conf.d/zz-pyme.ini
COPY zz-pyme-fpm.conf /usr/local/etc/php-fpm.d/zz-pyme-fpm.conf

ENV COMPOSER_HOME=/tmp/composer
WORKDIR /var/www/backend