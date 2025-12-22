#!/bin/bash
set -e

echo "Waiting for MySQL..."
while ! nc -z mysql 3306; do
  sleep 0.1
done
echo "MySQL is ready!"

echo "Waiting for Redis..."
while ! nc -z redis 6379; do
  sleep 0.1
done
echo "Redis is ready!"

# Fix permissions for storage and cache directories
echo "Setting permissions for storage and cache directories..."
mkdir -p /var/www/html/storage/framework/{sessions,views,cache}
mkdir -p /var/www/html/storage/logs
mkdir -p /var/www/html/bootstrap/cache
chown -R www-data:www-data /var/www/html/storage /var/www/html/bootstrap/cache
chmod -R 775 /var/www/html/storage /var/www/html/bootstrap/cache

# Auto-generate APP_KEY if missing or empty
if [ -z "$APP_KEY" ] || [ "$APP_KEY" = "" ]; then
  echo "APP_KEY is missing. Generating application key..."
  php artisan key:generate --force
  echo "Application key generated successfully!"
fi

# Clear caches before any operations
php artisan config:clear
php artisan cache:clear

# Run migrations if RUN_MIGRATIONS is set to true, or auto-detect if needed
if [ "$RUN_MIGRATIONS" = "true" ]; then
  echo "Running migrations (RUN_MIGRATIONS=true)..."
  php artisan migrate --force
elif [ "$RUN_MIGRATIONS" != "false" ]; then
  # Try to check if migrations are needed (only if database is accessible)
  echo "Checking if database needs migrations..."
  if php artisan migrate:status --quiet 2>/dev/null; then
    # If migrate:status succeeds, check if any migrations have run
    if ! php artisan migrate:status 2>/dev/null | grep -q "Ran"; then
      echo "Database appears empty. Running migrations..."
      php artisan migrate --force
    else
      echo "Migrations already run. Skipping."
    fi
  else
    # If migrate:status fails, try running migrations anyway (might be first run)
    echo "Unable to check migration status. Attempting to run migrations..."
    php artisan migrate --force || echo "Migration failed or already completed."
  fi
fi

# Cache config for production
if [ "$APP_ENV" = "production" ]; then
  echo "Caching configuration for production..."
  php artisan config:cache
  php artisan route:cache
  php artisan view:cache
fi

exec "$@"

