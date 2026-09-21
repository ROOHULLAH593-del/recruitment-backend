# syntax=docker/dockerfile:1

# Production image for Render: Nginx + PHP-FPM under supervisord.
# Build:  docker build -t recruitment-backend .
# Run:    see docker/entrypoint.sh for the environment variables it expects.

# --- Stage 1: production Composer dependencies ------------------------------------
FROM composer:2 AS vendor

WORKDIR /app

# Only the manifests, so this (slow) layer is cached until dependencies change.
# Platform checks are skipped here because this stage's PHP differs from the
# runtime image's; the runtime image installs the extensions the app needs.
# The autoloader is dumped later, once the application source is in place.
COPY composer.json composer.lock ./
RUN composer install \
    --no-dev \
    --no-interaction \
    --no-progress \
    --no-scripts \
    --no-autoloader \
    --prefer-dist \
    --ignore-platform-reqs

# --- Stage 2: runtime ---------------------------------------------------------------
FROM php:8.3-fpm-alpine

RUN apk add --no-cache nginx supervisor icu-libs libzip \
    && apk add --no-cache --virtual .build-deps $PHPIZE_DEPS icu-dev libzip-dev \
    && docker-php-ext-install -j"$(nproc)" pdo_mysql intl bcmath zip opcache pcntl \
    && apk del .build-deps

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /var/www/html

COPY --from=vendor /app/vendor ./vendor
COPY . .

# Optimized (classmap) autoloader for production. Scripts are skipped: package
# discovery needs the app booted and runs in entrypoint.sh instead.
RUN COMPOSER_ALLOW_SUPERUSER=1 composer dump-autoload \
    --no-dev \
    --optimize \
    --no-scripts \
    --no-interaction

COPY docker/nginx.conf /etc/nginx/nginx.conf.template
COPY docker/php-fpm.conf /usr/local/etc/php-fpm.d/zzz-app.conf
COPY docker/php.ini /usr/local/etc/php/conf.d/zzz-app.ini
COPY docker/supervisord.conf /etc/supervisord.conf
COPY docker/entrypoint.sh /usr/local/bin/entrypoint.sh

# Strip any CRLF from a Windows checkout, which would break the shebang.
RUN sed -i 's/\r$//' /usr/local/bin/entrypoint.sh \
    && chmod +x /usr/local/bin/entrypoint.sh

# Safe defaults; the Render dashboard overrides any of these.
ENV APP_ENV=production \
    APP_DEBUG=false \
    LOG_CHANNEL=stderr

# Render injects PORT at runtime (default 10000); entrypoint.sh wires it into nginx.
EXPOSE 10000

ENTRYPOINT ["/usr/local/bin/entrypoint.sh"]
