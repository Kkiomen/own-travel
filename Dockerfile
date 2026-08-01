# syntax=docker/dockerfile:1

# ---------------------------------------------------------------------------
# Stage 1 - PHP dependencies (production only)
# ---------------------------------------------------------------------------
FROM composer:2 AS vendor

WORKDIR /app

COPY composer.json composer.lock ./

RUN composer install \
        --no-dev \
        --no-scripts \
        --prefer-dist \
        --optimize-autoloader \
        --no-interaction

# ---------------------------------------------------------------------------
# Stage 2 - Front-end build
#
# Runs on a PHP image because the Wayfinder Vite plugin shells out to
# `php artisan wayfinder:generate` during `vite build`.
# ---------------------------------------------------------------------------
FROM php:8.4-cli-alpine AS assets

RUN apk add --no-cache nodejs npm

WORKDIR /app

COPY --from=vendor /app/vendor ./vendor
COPY . .

# Wayfinder boots the framework during `vite build`, so it needs an .env.
RUN cp .env.example .env

# `npm install`, not `npm ci`: the lockfile is written on Windows and lacks the
# musl-linux optional binaries that Alpine needs.
RUN npm install --no-audit --no-fund \
    && npm run build

# ---------------------------------------------------------------------------
# Stage 2b - Local development (artisan serve + vite, source is bind-mounted)
# ---------------------------------------------------------------------------
FROM php:8.4-cli-alpine AS dev

RUN apk add --no-cache nodejs npm postgresql-client icu-libs libzip libpq \
    && apk add --no-cache --virtual .build-deps $PHPIZE_DEPS icu-dev libzip-dev libpq-dev \
    && docker-php-ext-install -j"$(nproc)" pdo_pgsql intl zip bcmath pcntl \
    && pecl install redis \
    && docker-php-ext-enable redis \
    && apk del .build-deps \
    && rm -rf /tmp/pear

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /var/www/html

EXPOSE 8000 5173

CMD ["php", "artisan", "serve", "--host=0.0.0.0", "--port=8000"]

# ---------------------------------------------------------------------------
# Stage 3 - Runtime (nginx + php-fpm under supervisor)
# ---------------------------------------------------------------------------
FROM php:8.4-fpm-alpine AS runtime

RUN apk add --no-cache \
        nginx \
        supervisor \
        postgresql-client \
        icu-libs \
        libzip \
        libpq \
    && apk add --no-cache --virtual .build-deps \
        $PHPIZE_DEPS \
        icu-dev \
        libzip-dev \
        libpq-dev \
    && docker-php-ext-install -j"$(nproc)" \
        pdo_pgsql \
        intl \
        zip \
        bcmath \
        opcache \
        pcntl \
    && pecl install redis \
    && docker-php-ext-enable redis \
    && apk del .build-deps \
    && rm -rf /tmp/pear

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /var/www/html

COPY docker/php.ini /usr/local/etc/php/conf.d/99-app.ini
COPY docker/php-fpm.conf /usr/local/etc/php-fpm.d/zz-app.conf
COPY docker/nginx.conf /etc/nginx/nginx.conf
COPY docker/supervisord.conf /etc/supervisord.conf
COPY docker/entrypoint.sh /usr/local/bin/entrypoint
RUN chmod +x /usr/local/bin/entrypoint

COPY . .
COPY --from=vendor /app/vendor ./vendor
COPY --from=assets /app/public/build ./public/build

RUN composer dump-autoload --optimize --no-dev \
    && php artisan package:discover --ansi \
    && mkdir -p storage/framework/cache/data storage/framework/sessions storage/framework/views storage/logs bootstrap/cache \
    && chown -R www-data:www-data storage bootstrap/cache \
    && rm -rf /var/www/html/.env

EXPOSE 80

ENTRYPOINT ["entrypoint"]
CMD ["supervisord", "-c", "/etc/supervisord.conf"]
