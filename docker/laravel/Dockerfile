# syntax=docker/dockerfile:1.7
#
# Multi-target image for the Laravel tier. Build context is the REPO ROOT
# (so the production stages can COPY the laravel/ app tree):
#
#   docker build -f docker/laravel/Dockerfile --target base .   # dev runtime (code via bind-mount)
#   docker build -f docker/laravel/Dockerfile --target prod .   # php-fpm with app baked in
#   docker build -f docker/laravel/Dockerfile --target web  .   # nginx serving the baked public/
#
# Targets:
#   base   PHP-FPM + extensions + composer/node. Dev compose builds this and
#          bind-mounts ./laravel over it.
#   vendor composer install (prod deps).
#   assets npm build (Vite) -> public/build.
#   prod   full app + vendor + built/published assets. Pushed as oryntra:<tag>.
#   web    nginx with the prod public/ baked in. Pushed as oryntra-nginx:<tag>.

# -------------------------------------------------------------------------
# base — shared runtime, also used as the dev image (code via bind-mount)
# -------------------------------------------------------------------------
FROM php:8.4-fpm-bookworm AS base

ARG WWWUSER=1000
ARG WWWGROUP=1000

ENV DEBIAN_FRONTEND=noninteractive \
    TZ=UTC \
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

COPY docker/laravel/php.ini /usr/local/etc/php/conf.d/zz-oryntra.ini

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

# -------------------------------------------------------------------------
# vendor — production composer dependencies (no scripts; app not present yet)
# -------------------------------------------------------------------------
FROM base AS vendor

WORKDIR /var/www/html/laravel

COPY --chown=oryntra:oryntra laravel/composer.json laravel/composer.lock ./

RUN composer install \
        --no-dev \
        --prefer-dist \
        --no-progress \
        --no-interaction \
        --no-scripts \
        --no-autoloader

# -------------------------------------------------------------------------
# assets — Vite production build -> public/build
# -------------------------------------------------------------------------
FROM base AS assets

WORKDIR /var/www/html/laravel

COPY --chown=oryntra:oryntra laravel/package.json laravel/package-lock.json ./
RUN npm ci

COPY --chown=oryntra:oryntra laravel/ ./
# Vendor must be present: the Filament theme CSS @imports from vendor/filament.
COPY --from=vendor --chown=oryntra:oryntra /var/www/html/laravel/vendor ./vendor
RUN npm run build

# -------------------------------------------------------------------------
# prod — php-fpm with the application baked in
# -------------------------------------------------------------------------
FROM base AS prod

# Production php.ini overrides (opcache timestamps off, errors hidden).
COPY docker/laravel/php.prod.ini /usr/local/etc/php/conf.d/zz-oryntra.ini

WORKDIR /var/www/html/laravel

COPY --chown=oryntra:oryntra laravel/ ./
COPY --from=vendor --chown=oryntra:oryntra /var/www/html/laravel/vendor ./vendor
COPY --from=assets --chown=oryntra:oryntra /var/www/html/laravel/public/build ./public/build

# Optimize the autoloader and publish package/Filament assets into public/.
# A throwaway APP_KEY lets these boot-the-framework commands run at build time;
# the real key comes from the environment at runtime.
ARG BUILD_APP_KEY=base64:HDKCJnPazd9mGwllhBeszMhhWJRqfWH8h1iCS4zCoB0=
RUN set -eux; \
    composer dump-autoload --optimize --no-dev --no-interaction --no-scripts; \
    APP_KEY="${BUILD_APP_KEY}" php artisan package:discover --ansi; \
    APP_KEY="${BUILD_APP_KEY}" php artisan filament:assets; \
    chmod -R ug+rwX storage bootstrap/cache

# Resolve *_FILE secrets (Swarm/Docker secrets) into env at container start.
# entrypoint.sh is committed with mode 0755; COPY preserves it (chmod here would
# fail — the file lands root-owned and the build runs as the oryntra user).
COPY docker/laravel/entrypoint.sh /usr/local/bin/oryntra-entrypoint
ENTRYPOINT ["oryntra-entrypoint"]
CMD ["php-fpm"]

# -------------------------------------------------------------------------
# web — nginx serving the production public/ (assets baked from `prod`)
# -------------------------------------------------------------------------
FROM nginx:1.27-alpine AS web

COPY docker/nginx/default.conf /etc/nginx/conf.d/default.conf
COPY --from=prod /var/www/html/laravel/public /var/www/html/laravel/public

EXPOSE 80
