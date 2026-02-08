#!/bin/bash
# Set up test database schema and test environment

# Support both FreegleDocker (~/project) and submodule (~/FreegleDocker) paths
if [ -d "$HOME/FreegleDocker" ]; then
    cd "$HOME/FreegleDocker"
fi
echo "🗄️ Setting up test database and environment..."

# Verify required containers are still running
echo "Verifying required containers..."
for container in freegle-apiv1 freegle-percona; do
    if ! docker inspect -f '{{.State.Running}}' "$container" 2>/dev/null | grep -q "true"; then
    echo "❌ Container $container is not running!"
    echo ""
    echo "=== Container status ==="
    docker ps -a --filter "name=$container" --format "table {{.Names}}\t{{.Status}}\t{{.State}}"
    echo ""
    echo "=== Container logs (last 50 lines) ==="
    docker logs "$container" --tail 50 2>&1 || echo "Could not get logs"
    echo ""
    echo "=== All container statuses ==="
    docker ps -a --format "table {{.Names}}\t{{.Status}}\t{{.State}}" | head -30
    exit 1
    fi
    echo "✅ $container is running"
done

# Load database schema first (use container name directly for reliability)
echo "Loading database schema..."
docker exec freegle-apiv1 sh -c "cd /var/www/iznik && \
    sed -i 's/ROW_FORMAT=DYNAMIC//g' install/schema.sql && \
    sed -i 's/timestamp(3)/timestamp/g' install/schema.sql && \
    sed -i 's/timestamp(6)/timestamp/g' install/schema.sql && \
    sed -i 's/CURRENT_TIMESTAMP(3)/CURRENT_TIMESTAMP/g' install/schema.sql && \
    sed -i 's/CURRENT_TIMESTAMP(6)/CURRENT_TIMESTAMP/g' install/schema.sql && \
    mysql -h percona -u root -piznik -e 'CREATE DATABASE IF NOT EXISTS iznik;' && \
    mysql -h percona -u root -piznik iznik < install/schema.sql && \
    mysql -h percona -u root -piznik iznik < install/functions.sql && \
    mysql -h percona -u root -piznik iznik < install/damlevlim.sql && \
    mysql -h percona -u root -piznik -e \"SET GLOBAL sql_mode = 'NO_ZERO_IN_DATE,NO_ZERO_DATE,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION'\" && \
    mysql -h percona -u root -piznik -e \"SET GLOBAL sql_mode=(SELECT REPLACE(@@sql_mode,'ONLY_FULL_GROUP_BY',''));\""

# Set up test environment using testenv.php from iznik-server
echo "Setting up test environment (FreeglePlayground group, test users, etc.)..."
docker exec freegle-apiv1 sh -c "cd /var/www/iznik && php install/testenv.php"

# Run Laravel migrations to create tables not in schema.sql (e.g. email_queue)
# schema.sql drops/recreates tables, so migrations must run AFTER it.
echo "Running Laravel migrations against iznik database..."
if docker inspect -f '{{.State.Running}}' freegle-batch 2>/dev/null | grep -q "true"; then
    docker exec freegle-batch php artisan migrate --force --no-interaction 2>&1 || echo "⚠️ Laravel migrations had warnings (may be OK if tables already exist)"
    echo "✅ Laravel migrations complete"
else
    echo "⚠️ Batch container not running - skipping Laravel migrations"
fi

# Set up iznik_go_test database for Go API tests (uses a separate DB)
echo "Setting up iznik_go_test database for Go tests..."
docker exec freegle-apiv1 sh -c "cd /var/www/iznik && \
    mysql -h percona -u root -piznik -e 'CREATE DATABASE IF NOT EXISTS iznik_go_test;' && \
    mysql -h percona -u root -piznik iznik_go_test < install/schema.sql && \
    mysql -h percona -u root -piznik iznik_go_test < install/functions.sql && \
    mysql -h percona -u root -piznik iznik_go_test < install/damlevlim.sql"

# Run Laravel migrations against iznik_go_test too
if docker inspect -f '{{.State.Running}}' freegle-batch 2>/dev/null | grep -q "true"; then
    docker exec -e DB_DATABASE=iznik_go_test freegle-batch php artisan migrate --force --no-interaction 2>&1 || echo "⚠️ Go test DB migrations had warnings"
    echo "✅ iznik_go_test migrations complete"
fi

echo "✅ Test database and environment ready!"