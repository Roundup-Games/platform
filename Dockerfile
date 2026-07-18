# syntax=docker/dockerfile:1
# Stage 1: Build frontend assets
# Node 24 — current active LTS, pinned in lockstep with CI (.github/workflows/ci.yml),
# .nvmrc, and package.json engines so every build environment produces
# byte-equivalent assets.
FROM node:24-alpine AS frontend

WORKDIR /app

COPY package.json package-lock.json ./
RUN npm ci

COPY vite.config.js ./
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

# Install postgresql client for DB creation at startup.
# Cache mounts keep apt's download cache in a BuildKit-managed volume that
# survives across builds, so re-runs (e.g. after a base-image bump) skip the
# network fetch. Requires BuildKit (on by default in Docker 23+/buildx);
# the `# syntax=docker/dockerfile:1` directive at the top pins the frontend.
RUN --mount=type=cache,target=/var/cache/apt,sharing=locked \
    --mount=type=cache,target=/var/lib/apt,sharing=locked \
    apt-get update && apt-get install -y --no-install-recommends postgresql-client

USER www-data

# Copy composer files first for better layer caching
COPY --chown=www-data:www-data composer.json composer.lock ./
# Create autoload.files entries before install — source isn't copied yet
RUN mkdir -p app && touch app/helpers.php
RUN composer install --no-dev --optimize-autoloader --no-interaction --no-progress

# Copy application source
COPY --chown=www-data:www-data . .
COPY --from=frontend --chown=www-data:www-data /app/public/build /var/www/html/public/build

# Patch third-party packages that assume int user IDs (we use UUIDs)
RUN bash scripts/patch-escalated-uuid.sh

# Regenerate the package-discovery cache from the prod vendor. Any host cache
# (which may reference dev-only providers like laravel/pail, absent under
# --no-dev) is discarded so artisan boots cleanly. Belt-and-suspenders with
# the .dockerignore exclusion of bootstrap/cache/*.php.
RUN rm -f bootstrap/cache/packages.php bootstrap/cache/services.php \
 && php artisan package:discover --ansi

# Storage link and ensure writable dirs
RUN php artisan storage:link --force || true \
 && mkdir -p storage/framework/{sessions,views,cache} \
             storage/logs \
             bootstrap/cache

# S6 init script — runs migrations, caches config on every start
# Must come after source is copied so artisan is available at runtime
USER root
COPY --chmod=755 docker/s6/99-laravel-init /etc/cont-init.d/99-laravel-init
USER www-data

# Stage 3: Worker image — queue worker + scheduler (no nginx/fpm)
# Reuses the app stage but replaces S6 services
FROM app AS worker

USER root

# Remove nginx and php-fpm from the user bundle
RUN rm /etc/s6-overlay/s6-rc.d/user/contents.d/nginx \
   && rm /etc/s6-overlay/s6-rc.d/user/contents.d/php-fpm

# Install image optimizer binaries for Spatie MediaLibrary conversions
# These only run on the queue worker — no need to bloat the web container.
# Covers all 7 optimizers configured in config/media-library.php:
#   jpegoptim, pngquant, optipng, gifsicle, cwebp (via webp), avifenc (via libavif-bin)
#
# Cache mounts persist the apt download cache across builds. This step sits
# after `FROM app`, so it would otherwise re-download every package on every
# source change (the worker stage inherits app's cache-busted final layer).
# The cache mount decouples the expensive network fetch from layer invalidation:
# the RUN still re-executes (dpkg unpack) but reads packages from the cache.
# The /var/lib/apt/lists cleanup is dropped — the cache mount holds lists
# outside the image layer, so they never ship in the final image.
RUN --mount=type=cache,target=/var/cache/apt,sharing=locked \
    --mount=type=cache,target=/var/lib/apt,sharing=locked \
    apt-get update && apt-get install -y --no-install-recommends \
        jpegoptim \
        pngquant \
        optipng \
        gifsicle \
        webp \
        libavif-bin

# Raise PHP memory limit for image conversion jobs — GD decompresses large
# images into bitmaps that can exceed the default 256 MB limit.
COPY --chown=www-data:www-data docker/s6-worker/queue/php-memory.ini /usr/local/etc/php/conf.d/zzz-memory.ini

# Add queue and scheduler S6 service definitions
COPY --chmod=755 docker/s6-worker/queue/ /etc/s6-overlay/s6-rc.d/queue/
COPY --chmod=755 docker/s6-worker/scheduler/ /etc/s6-overlay/s6-rc.d/scheduler/

# Register queue and scheduler in the user bundle
RUN touch /etc/s6-overlay/s6-rc.d/user/contents.d/queue \
   && touch /etc/s6-overlay/s6-rc.d/user/contents.d/scheduler

USER www-data
