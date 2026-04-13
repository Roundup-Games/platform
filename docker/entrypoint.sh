#!/bin/sh
set -e

cd /var/www/html

# Create the database if it doesn't exist (external Postgres)
if [ "${DB_CONNECTION}" = "pgsql" ]; then
    echo "Ensuring database '${DB_DATABASE}' exists on ${DB_HOST}:${DB_PORT}..."
    PGPASSWORD="${DB_PASSWORD}" psql -h "${DB_HOST}" -p "${DB_PORT}" -U "${DB_USERNAME}" \
        -d postgres -tc "SELECT 1 FROM pg_database WHERE datname = '${DB_DATABASE}'" | grep -q 1 || \
    PGPASSWORD="${DB_PASSWORD}" psql -h "${DB_HOST}" -p "${DB_PORT}" -U "${DB_USERNAME}" \
        -d postgres -c "CREATE DATABASE ${DB_DATABASE};"
    echo "Database ready."
fi

# Generate app key if not set
if [ -z "${APP_KEY}" ]; then
    echo "Generating APP_KEY..."
    export APP_KEY=$(php artisan key:generate --show)
    if [ -f .env ]; then
        sed -i "s|^APP_KEY=.*|APP_KEY=${APP_KEY}|" .env
    fi
fi

# Run migrations
echo "Running migrations..."
php artisan migrate --force

# Cache config, routes, views for production
echo "Caching config, routes, views..."
php artisan config:cache
php artisan route:cache
php artisan view:cache

echo "Startup complete."

exec "$@"
