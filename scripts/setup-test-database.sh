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

echo "✅ Test database and environment ready!"