#!/bin/bash
set -e

echo "=== Laravel Batch Container Starting ==="

# Laravel configuration comes from environment variables in docker-compose.yml
# No .env file is needed - APP_KEY and all other config is passed via environment
echo "Using environment variables for configuration (no .env file needed)"

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

# Create the Laravel databases if they don't exist (main + test)
echo "Ensuring databases exist..."
php -r "
\$pdo = new PDO('mysql:host='.getenv('DB_HOST'), getenv('DB_USERNAME'), getenv('DB_PASSWORD'));
\$pdo->exec('CREATE DATABASE IF NOT EXISTS '.getenv('DB_DATABASE'));
\$pdo->exec('CREATE DATABASE IF NOT EXISTS '.getenv('DB_DATABASE').'_test');
echo 'Database ready: '.getenv('DB_DATABASE').PHP_EOL;
echo 'Test database ready: '.getenv('DB_DATABASE').'_test'.PHP_EOL;
"

# Run migrations to ensure tables exist (retry on failure)
echo "Running migrations..."
migrationAttempts=3
while [ $migrationAttempts -gt 0 ]; do
    if php artisan migrate --force 2>&1; then
        echo "Migrations completed successfully."
        break
    fi
    migrationAttempts=$((migrationAttempts - 1))
    if [ $migrationAttempts -gt 0 ]; then
        echo "Migration failed, retrying... ($migrationAttempts attempts remaining)"
        sleep 5
    else
        echo "WARNING: Migrations failed after all retries, continuing anyway..."
    fi
done

# Clear Laravel application caches
echo "Clearing application caches..."
php artisan cache:clear || true
php artisan config:clear || true

# Install MJML in spatie package if not present (vendor is a named volume)
if [ ! -d "/var/www/html/vendor/spatie/mjml-php/bin/node_modules/mjml" ]; then
    echo "Installing MJML in spatie package..."
    cd /var/www/html/vendor/spatie/mjml-php/bin && npm install mjml --silent
    cd /var/www/html
fi

echo "=== Starting Laravel batch job processor ==="

# Create ready marker file to signal healthcheck that startup is complete
touch /tmp/laravel-ready

exec "$@"
