# Stage 1: Build frontend assets
FROM node:22-alpine AS frontend

WORKDIR /app

COPY package.json package-lock.json ./
RUN npm ci

COPY vite.config.js tailwind.config.js postcss.config.js ./
COPY resources/ resources/

RUN npm run build

# Stage 2: Production image
# serversideup/php:8.5-fpm-nginx — PHP-FPM + Nginx + S6 Overlay
# Laravel extensions pre-installed, runs as www-data on port 80
# https://github.com/serversideup/docker-php
FROM serversideup/php:8.5-fpm-nginx AS app

# Additional PHP extensions needed beyond the Laravel defaults
USER root
RUN install-php-extensions gd intl bcmath exif

# Install postgresql client for DB creation at startup
RUN apt-get update && apt-get install -y --no-install-recommends postgresql-client \
    && rm -rf /var/lib/apt/lists/*

USER www-data

# Copy composer files first for better layer caching
COPY --chown=www-data:www-data composer.json composer.lock ./
RUN composer install --no-dev --optimize-autoloader --no-interaction --no-progress

# Copy application source
COPY --chown=www-data:www-data . .
COPY --from=frontend --chown=www-data:www-data /app/public/build /var/www/html/public/build

# Storage link and ensure writable dirs
RUN php artisan storage:link --force 2>/dev/null; true \
 && mkdir -p storage/framework/{sessions,views,cache} \
             storage/logs \
             bootstrap/cache

# S6 init script — runs migrations, caches config on every start
# Must come after source is copied so artisan is available at runtime
USER root
COPY --chmod=755 docker/s6/99-laravel-init /etc/cont-init.d/99-laravel-init
USER www-data
