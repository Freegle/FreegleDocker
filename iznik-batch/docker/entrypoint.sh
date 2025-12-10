#!/bin/bash
set -e

echo "=== Laravel Batch Container Starting ==="

# Create .env file if it doesn't exist (bind mount overwrites Docker build)
if [ ! -f /var/www/html/.env ]; then
    echo "Creating .env from .env.example..."
    cp /var/www/html/.env.example /var/www/html/.env
    php artisan key:generate
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

# Clear Laravel application caches
echo "Clearing application caches..."
php artisan cache:clear || true
php artisan config:clear || true

echo "=== Starting Laravel batch job processor ==="

exec "$@"
