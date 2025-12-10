#!/bin/bash
set -e

# Install composer dependencies if vendor directory doesn't exist (needed when volume-mounted)
if [ ! -d "/var/www/html/vendor" ]; then
    echo "Installing composer dependencies..."
    composer install --no-interaction --prefer-dist --optimize-autoloader
fi

# Wait for database server to be ready (connect without specifying database)
echo "Waiting for database server..."
maxTries=30
while [ $maxTries -gt 0 ]; do
    if php -r "try { new PDO('mysql:host='.getenv('DB_HOST'), getenv('DB_USERNAME'), getenv('DB_PASSWORD')); echo 'OK'; exit(0); } catch(Exception \$e) { exit(1); }" 2>/dev/null; then
        echo "Database server is ready!"
        break
    fi
    maxTries=$((maxTries - 1))
    echo "Waiting for database server... ($maxTries tries remaining)"
    sleep 2
done

if [ $maxTries -eq 0 ]; then
    echo "Could not connect to database server!"
    exit 1
fi

# Create the Laravel database if it doesn't exist
echo "Ensuring database exists..."
php -r "
\$pdo = new PDO('mysql:host='.getenv('DB_HOST'), getenv('DB_USERNAME'), getenv('DB_PASSWORD'));
\$pdo->exec('CREATE DATABASE IF NOT EXISTS '.getenv('DB_DATABASE'));
echo 'Database ready: '.getenv('DB_DATABASE').PHP_EOL;
"

# Run migrations to ensure tables exist
echo "Running migrations..."
php artisan migrate --force

# Clear Laravel caches (non-fatal if tables don't exist yet)
echo "Clearing caches..."
php artisan config:clear || true
php artisan cache:clear || true

echo "Starting Laravel batch job processor..."

exec "$@"
