# Stage 1: Build frontend assets
FROM node:22-alpine AS frontend

WORKDIR /app

COPY package.json package-lock.json ./
RUN npm ci

COPY vite.config.js tailwind.config.js postcss.config.js ./
COPY resources/ resources/

RUN npm run build

# Stage 2: Production image
FROM php:8.5-fpm-alpine AS app

RUN apk add --no-cache \
    nginx \
    supervisor \
    sqlite-libs \
    libpq \
    oniguruma \
    libpng \
    freetype \
    libjpeg-turbo \
    libzip \
    linux-headers \
    postgresql16-client \
 && apk add --no-cache --virtual .build-deps \
    $PHPIZE_DEPS \
    postgresql-dev \
    sqlite-dev \
    oniguruma-dev \
    libpng-dev \
    freetype-dev \
    libjpeg-turbo-dev \
    libzip-dev \
 && docker-php-ext-configure gd --with-freetype --with-jpeg \
 && docker-php-ext-install -j$(nproc) \
    pdo_pgsql \
    pdo_sqlite \
    pdo_mysql \
    mbstring \
    gd \
    zip \
    opcache \
    pcntl \
 && apk del .build-deps

# Install Composer
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

# Nginx config
COPY docker/nginx/default.conf /etc/nginx/http.d/default.conf

# Supervisor config
COPY docker/supervisor/supervisord.conf /etc/supervisord.conf
COPY docker/supervisor/laravel-worker.conf /etc/supervisor.d/laravel-worker.conf

# PHP config
COPY docker/php/php.ini /usr/local/etc/php/conf.d/app.ini
COPY docker/php/opcache.ini /usr/local/etc/php/conf.d/opcache.ini

WORKDIR /var/www/html

# Copy app source
COPY --chown=www-data:www-data . .
COPY --from=frontend --chown=www-data:www-data /app/public/build /var/www/html/public/build

# Install PHP dependencies (no dev for production)
RUN composer install --no-dev --optimize-autoloader --no-interaction --no-progress \
 && php artisan storage:link --force || true

# Ensure writable dirs
RUN mkdir -p storage/framework/{sessions,views,cache} \
             storage/logs \
             bootstrap/cache \
 && chown -R www-data:www-data storage bootstrap/cache

# Entrypoint for first-run setup
COPY docker/entrypoint.sh /usr/local/bin/entrypoint.sh
RUN chmod +x /usr/local/bin/entrypoint.sh

EXPOSE 80

ENTRYPOINT ["/usr/local/bin/entrypoint.sh"]
CMD ["supervisord", "-c", "/etc/supervisord.conf"]
