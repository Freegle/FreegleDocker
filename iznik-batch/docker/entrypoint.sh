#!/bin/bash
set -e

# Wait for database to be ready using PHP (more reliable than mysql client)
echo "Waiting for database connection..."
maxTries=30
while [ $maxTries -gt 0 ]; do
    if php -r "try { new PDO('mysql:host='.getenv('DB_HOST').';dbname='.getenv('DB_DATABASE'), getenv('DB_USERNAME'), getenv('DB_PASSWORD')); echo 'OK'; exit(0); } catch(Exception \$e) { exit(1); }" 2>/dev/null; then
        echo "Database is ready!"
        break
    fi
    maxTries=$((maxTries - 1))
    echo "Waiting for database... ($maxTries tries remaining)"
    sleep 2
done

if [ $maxTries -eq 0 ]; then
    echo "Could not connect to database!"
    exit 1
fi

# Clear Laravel caches
php artisan config:clear
php artisan cache:clear

# Run any pending migrations (optional - disabled by default as we use existing schema)
# php artisan migrate --force

echo "Starting Laravel batch job processor..."

exec "$@"
