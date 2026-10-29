# syntax=docker/dockerfile:1
# Stage 1: Build frontend assets
# Node 24 — current active LTS, pinned in lockstep with CI (.github/workflows/ci.yml),
# .nvmrc, and package.json engines so every build environment produces
# byte-equivalent assets.
FROM node:24-alpine AS frontend

WORKDIR /app

COPY package.json package-lock.json ./
RUN --mount=type=cache,target=/root/.npm npm ci

COPY vite.config.js ./
COPY resources/ resources/

RUN npm run build

# Stage 2: Shared base — all apt installs live here, BEFORE any source COPY.
# This is the key to layer caching: because neither `app` nor `worker` copy
# application source at this point, this stage's layers are keyed only to the
# base image and the apt command itself. BuildKit reuses them across every
# source commit. (Previously the optimizer install lived in the worker stage,
# which is `FROM app` — and `app`'s `COPY . .` cache-busts on every commit,
# invalidating the entire worker lineage and re-running apt every build.)
#
# serversideup/php:8.5-fpm-nginx — PHP-FPM + Nginx + S6 Overlay
# Laravel extensions pre-installed, runs as www-data on port 80
# https://github.com/serversideup/docker-php
FROM serversideup/php:8.5-fpm-nginx AS base

# Additional PHP extensions needed beyond the Laravel defaults
USER root
RUN install-php-extensions gd intl bcmath exif

# Production opcache tuning. The image boots with PHP's defaults, which are
# shaped for development: validate_timestamps=1 stats()s every cached file,
# the 10k max_accelerated_files cap is below this app's file count (~700 app
# files + ~290 vendor packages → eviction churn), and JIT stays off. Deploys
# are new containers — code is never hot-swapped in place — so timestamp
# validation is pure overhead. Baked into the image (not env vars) so every
# runtime (FPM, Horizon, artisan) shares identical settings.
#
# 2026-10-17 production incident: opcache.jit=tracing caused deterministic
# FPM SIGSEGVs (502s on every full public page render — /en, /de — while
# light routes like /up and /en/login stayed 200). CLI rendered fine;
# disabling the JIT restored service immediately. Tracing JIT stays OFF
# until the triggering path is understood on a PHP release that fixes it.
# opcache itself (the big win) is unaffected.
RUN printf '%s\n' \
    'opcache.enable=1' \
    'opcache.enable_cli=1' \
    'opcache.validate_timestamps=0' \
    'opcache.memory_consumption=256' \
    'opcache.max_accelerated_files=30000' \
    'opcache.interned_strings_buffer=16' \
    'opcache.jit=disable' \
    'opcache.jit_buffer_size=0' \
    > /usr/local/etc/php/conf.d/zz-opcache-tuning.ini

# Install ALL apt packages in one stable layer:
#   - postgresql-client: DB creation at startup (app + worker)
#   - jpegoptim/pngquant/optipng/gifsicle/webp/libavif-bin: image optimizers
#     used by Spatie MediaLibrary conversions on the queue worker
#     (config/media-library.php).
# The optimizers ship in both images even though only the worker uses them —
# ~10-15MB of unused CLI tools in the web image buys a ~120s build-speedup
# on every commit, which is the right tradeoff. Cache mounts persist the
# apt download cache across builds (useful on a base-image bump) and require
# BuildKit, on by default in Docker 23+/buildx and pinned by the syntax
# directive at the top of this file. The /var/lib/apt/lists cleanup is
# dropped: with the cache mount the lists live in the cache volume, not the
# image layer, so they never ship in the final image.
RUN --mount=type=cache,target=/var/cache/apt,sharing=locked \
    --mount=type=cache,target=/var/lib/apt,sharing=locked \
    apt-get update && apt-get install -y --no-install-recommends \
        postgresql-client \
        jpegoptim \
        pngquant \
        optipng \
        gifsicle \
        webp \
        libavif-bin

USER www-data

# Stage 3: Production image (web/app)
# Source is copied here and below — these layers cache-bust on every commit,
# which is fine because everything expensive (apt, PHP exts) is already in `base`.
FROM base AS app

# Copy composer files first for better layer caching
COPY --chown=www-data:www-data composer.json composer.lock ./
# Create autoload.files entries before install — source isn't copied yet
RUN mkdir -p app && touch app/helpers.php
# Cache mount keeps package downloads across lockfile bumps; the layer
# itself only re-runs when composer.json/lock change.
RUN --mount=type=cache,target=/tmp/composer-cache \
    COMPOSER_CACHE_DIR=/tmp/composer-cache \
    composer install --no-dev --optimize-autoloader --no-interaction --no-progress

# Copy application source
COPY --chown=www-data:www-data . .
COPY --from=frontend --chown=www-data:www-data /app/public/build /var/www/html/public/build

# Patch third-party packages that assume int user IDs (we use UUIDs)
RUN bash scripts/patch-escalated-uuid.sh

# Regenerate the package-discovery cache from the prod vendor. Any host cache
# (which may reference dev-only providers like laravel/pail, absent under
# --no-dev) is discarded so artisan boots cleanly. Belt-and-suspenders with
# the .dockerignore exclusion of bootstrap/cache/*.php. Needs the writable
# runtime dirs — the build context no longer ships dev storage/.
RUN rm -f bootstrap/cache/packages.php bootstrap/cache/services.php \
 && mkdir -p storage/framework/sessions storage/framework/views \
             storage/framework/cache storage/logs bootstrap/cache \
 && php artisan package:discover --ansi

# Storage link (public/storage -> storage/app/public). Runs with no
# environment configured, so the webhook-secret fail-fast is inert here by
# design (build-time boot, see AppServiceProvider).
RUN php artisan storage:link --force || true

# Bake ONLY the caches that are provably env-independent. event:cache is
# code-driven — safe to bake. route:cache is NOT (see the init script):
# Livewire 4 prefixes its endpoints with a hash of APP_KEY
# (EndpointResolver::prefix()), so a build-time route cache registers a
# keyless prefix while the running app serves the real-key one — every
# Livewire POST then 405s (2026-10-15 production incident: admin dashboard
# and all interactive pages returned 405 on /livewire-73d64a53/update
# while the baked table only knew /livewire-4aeeedbb). route:cache runs in
# docker/s6/99-laravel-init after APP_KEY is ensured; the init-complete
# marker gate covers the boot race that originally motivated baking.
#
# view:cache is deliberately NOT baked: compiled views live in
# storage/framework/views, which the app_storage named volume shadows at
# runtime (no copy-up once the volume exists), so baked views never reach
# production. view:cache stays in the runtime init (docker/s6/99-laravel-init)
# where it writes the volume — and has no fatal race class of its own.
#
# Ops notes:
# - event:cache is a build artifact: `docker exec ... artisan event:clear`
#   in a running container is permanent until the next image deploy.
# - route:cache is a runtime artifact regenerated on every start:
#   `docker exec ... artisan route:clear` restores dynamically registered
#   routes immediately and survives until the next restart.
RUN php artisan event:cache

# S6 init script — runs migrations, caches config on every start
# Must come after source is copied so artisan is available at runtime
USER root
COPY --chmod=755 docker/s6/99-laravel-init /etc/cont-init.d/99-laravel-init
# Bound the s6-rc boot readiness wait. The serversideup base ships 0
# (= infinite, s6-overlay >=3.2.0.0 default): any service declaring
# notification-fd without ever signaling readiness wedges the boot
# `s6-rc change top` transition forever — it holds the s6-rc database
# lock, shutdown stage 3 (`s6-rc -bda change`) then blocks on that lock,
# PID 1 never exits, and Docker SIGKILLs at stop_grace_period (the
# 5-minute deploy-window outage; s6-overlay issue #637). A positive bound
# makes a wedged readiness wait release the lock instead.
ENV S6_CMD_WAIT_FOR_SERVICES_MAXTIME=30000
USER www-data

# Stage 4: Worker image — queue worker + scheduler (no nginx/fpm)
# Extends `app` (which has source, vendor, and the s6 init). No apt here —
# the optimizers already live in `base`, so this stage's layers are all cheap
# (rm, COPY ini/service definitions, touch) and the ~120s apt step is gone.
FROM app AS worker

USER root

# Remove nginx and php-fpm from the service tree. serversideup restructured
# their s6 layout (found 2026-10): nginx/php-fpm are now top-level services
# with bundle markers in /etc/s6-overlay/user-bundles.d/user/contents.d; the
# old /etc/s6-overlay/s6-rc.d/user/contents.d location is gone. Strip both
# layouts — a surviving bundle marker pointing at a removed service makes
# s6-rc-compile fail at container start, crash-looping the worker. The final
# assertion fails the build if any nginx/php-fpm artifact survives anywhere
# under /etc/s6-overlay.
RUN set -eux; \
    rm -rf /etc/s6-overlay/s6-rc.d/nginx /etc/s6-overlay/s6-rc.d/php-fpm; \
    find /etc/s6-overlay -depth -type d -name contents.d \
        -exec rm -f {}/nginx {}/php-fpm \; ; \
    ! find /etc/s6-overlay \( -name nginx -o -name php-fpm \) | grep -q .

# Raise PHP memory limit for image conversion jobs — GD decompresses large
# images into bitmaps that can exceed the default 256 MB limit.
COPY --chown=www-data:www-data docker/s6-worker/queue/php-memory.ini /usr/local/etc/php/conf.d/zzz-memory.ini

# Add queue and scheduler S6 service definitions
COPY --chmod=755 docker/s6-worker/queue/ /etc/s6-overlay/s6-rc.d/queue/
COPY --chmod=755 docker/s6-worker/scheduler/ /etc/s6-overlay/s6-rc.d/scheduler/
COPY --chmod=755 docker/s6-worker/watchdog/ /etc/s6-overlay/s6-rc.d/watchdog/

# Register queue and scheduler in the bundle s6 starts. Its location
# depends on the base image layout (new: user-bundles.d/user; legacy:
# s6-rc.d/user).
RUN set -eux; \
    reg=/etc/s6-overlay/s6-rc.d/user/contents.d; \
    [ -d "$reg" ] || reg=/etc/s6-overlay/user-bundles.d/user/contents.d; \
    mkdir -p "$reg"; touch "$reg/queue" "$reg/scheduler" "$reg/watchdog"

USER www-data
