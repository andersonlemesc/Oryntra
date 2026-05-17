# syntax=docker/dockerfile:1.7
FROM php:8.3-fpm-bookworm

ARG WWWUSER=1000
ARG WWWGROUP=1000

ENV DEBIAN_FRONTEND=noninteractive \
    TZ=America/Sao_Paulo \
    COMPOSER_ALLOW_SUPERUSER=0 \
    COMPOSER_NO_INTERACTION=1

SHELL ["/bin/bash", "-o", "pipefail", "-c"]

# hadolint ignore=DL3008
RUN apt-get update && apt-get install -y --no-install-recommends \
        git \
        unzip \
        curl \
        ca-certificates \
        gnupg \
        libpq-dev \
        libicu-dev \
        libzip-dev \
        libpng-dev \
        libjpeg-dev \
        libfreetype6-dev \
        libonig-dev \
        libxml2-dev \
        libssl-dev \
        pkg-config \
        supervisor \
    && rm -rf /var/lib/apt/lists/*

RUN docker-php-ext-configure gd --with-freetype --with-jpeg \
    && docker-php-ext-install -j"$(nproc)" \
        pdo_pgsql \
        pgsql \
        intl \
        bcmath \
        opcache \
        pcntl \
        sockets \
        zip \
        gd \
        exif \
        mbstring \
    && pecl install redis \
    && docker-php-ext-enable redis

COPY php.ini /usr/local/etc/php/conf.d/zz-oryntra.ini

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

# hadolint ignore=DL3008,DL3016
RUN curl -fsSL https://deb.nodesource.com/setup_22.x | bash - \
    && apt-get install -y --no-install-recommends nodejs \
    && rm -rf /var/lib/apt/lists/* \
    && npm install -g pnpm

RUN groupadd --force -g ${WWWGROUP} oryntra \
    && useradd -l -ms /bin/bash --no-user-group -g ${WWWGROUP} -u ${WWWUSER} oryntra

WORKDIR /var/www/html

USER oryntra

EXPOSE 9000

HEALTHCHECK --interval=30s --timeout=5s --start-period=20s --retries=3 \
    CMD php -r "exit(@fsockopen('127.0.0.1', 9000) ? 0 : 1);" || exit 1

CMD ["php-fpm"]
