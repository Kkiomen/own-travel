#!/bin/sh
set -e

cd /var/www/html

if [ -z "${APP_KEY:-}" ]; then
    echo "APP_KEY is empty. Run: docker compose run --rm app php artisan key:generate --show" >&2
    echo "and put the value in .env, then start again." >&2
    exit 1
fi

# Wait for Postgres before booting anything that touches the DB.
if [ -n "${DB_HOST:-}" ]; then
    printf 'Waiting for database at %s:%s' "${DB_HOST}" "${DB_PORT:-5432}"
    until pg_isready -h "${DB_HOST}" -p "${DB_PORT:-5432}" -q; do
        printf '.'
        sleep 1
    done
    echo ' ready.'
fi

mkdir -p storage/framework/cache/data storage/framework/sessions storage/framework/views storage/logs bootstrap/cache
chown -R www-data:www-data storage bootstrap/cache

# Only the web container owns schema changes, so workers never race it.
if [ "${CONTAINER_ROLE:-app}" = "app" ]; then
    php artisan migrate --force --isolated || php artisan migrate --force
    php artisan storage:link --quiet || true
fi

php artisan config:cache
php artisan route:cache
php artisan view:cache
php artisan event:cache

exec "$@"
