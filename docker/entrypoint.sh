#!/bin/sh
set -e

echo "🚀 [ZBY Backend] Starting production container..."

# 1. Wait for database if DB_HOST is set
if [ -n "$DB_HOST" ]; then
    echo "⏳ Waiting for PostgreSQL at $DB_HOST:${DB_PORT:-5432}..."
    until nc -z -v -w3 "$DB_HOST" "${DB_PORT:-5432}" 2>/dev/null; do
        echo "⏳ Database is unavailable - sleeping 2 seconds..."
        sleep 2
    done
    echo "✅ PostgreSQL is up and accepting connections!"
fi

# 2. Fix storage permissions
echo "🔒 Setting permissions for storage and bootstrap/cache..."
chown -R www-data:www-data /var/www/html/storage /var/www/html/bootstrap/cache
chmod -R 775 /var/www/html/storage /var/www/html/bootstrap/cache

# 3. Cache configuration and routes for production performance
if [ "$APP_ENV" = "production" ] || [ "$APP_ENV" = "prod" ]; then
    echo "⚡ Caching Laravel configuration, routes, and views..."
    php artisan config:cache || true
    php artisan route:cache || true
    php artisan view:cache || true
fi

# 4. Run database migrations if RUN_MIGRATIONS is true (default true)
if [ "${RUN_MIGRATIONS:-true}" = "true" ]; then
    echo "📦 Running database migrations..."
    php artisan migrate --force || true
fi

echo "🟢 [ZBY Backend] Starting PHP server on port 8000..."
exec php artisan serve --host=0.0.0.0 --port=8000
