#!/bin/bash
# Run tests for a specific submodule using the FreegleDocker environment
# Usage: ./scripts/run-submodule-tests.sh <php|go|playwright> <path-to-pr-code>

set -e

TEST_TYPE=$1
PR_CODE_PATH=$2

if [ -z "$TEST_TYPE" ] || [ -z "$PR_CODE_PATH" ]; then
    echo "Usage: $0 <php|go|playwright> <path-to-pr-code>"
    exit 1
fi

echo "=== Running $TEST_TYPE tests ==="
echo "PR code path: $PR_CODE_PATH"

# Determine which submodule to replace
case $TEST_TYPE in
    php)
        SUBMODULE_DIR="iznik-server"
        ;;
    go)
        SUBMODULE_DIR="iznik-server-go"
        ;;
    playwright)
        SUBMODULE_DIR="iznik-nuxt3"
        ;;
    *)
        echo "Unknown test type: $TEST_TYPE (use 'php', 'go', or 'playwright')"
        exit 1
        ;;
esac

# Replace submodule with PR code
echo "Replacing $SUBMODULE_DIR with PR code..."
rm -rf "$SUBMODULE_DIR"
cp -r "$PR_CODE_PATH" "$SUBMODULE_DIR"

# Create secrets files (same as FreegleDocker CI)
echo "Creating secrets files..."
mkdir -p secrets
echo "placeholder" > secrets/lovejunk-api.txt
echo "placeholder" > secrets/lovejunk-secret.txt
echo "placeholder" > secrets/partner-key.txt
echo "placeholder" > secrets/partner-name.txt
echo "placeholder" > secrets/image-domain.txt

# Fix memory overcommit for Redis
sudo sysctl vm.overcommit_memory=1 || true

# Set environment variables (same as FreegleDocker CI)
export DOCKER_BUILDKIT=1
export COMPOSE_DOCKER_CLI_BUILD=1

# Start all Docker services (same as full FreegleDocker CI)
echo "Starting Docker services..."
docker-compose -f docker-compose.yml up -d

echo "Waiting for services to start..."
sleep 30

# Wait for status service and API services to be healthy (same as FreegleDocker CI)
echo "Waiting for API v1 and v2 services to be healthy..."
start_time=$(date +%s)
timeout_duration=600

while true; do
    current_time=$(date +%s)
    elapsed=$((current_time - start_time))

    if [ $elapsed -gt $timeout_duration ]; then
        echo "❌ Timeout waiting for API services after 10 minutes"
        docker-compose -f docker-compose.yml logs
        exit 1
    fi

    # Check if status service is responding
    if curl -f -s http://localhost:8081 > /dev/null 2>&1; then
        echo "✅ Status service is responding!"

        # Get health status from status service
        health_response=$(curl -s http://localhost:8081/api/status/all 2>/dev/null || echo '{}')

        # Check if API v1 and v2 are healthy
        apiv1_status=$(echo "$health_response" | jq -r '.apiv1.status // "unknown"')
        apiv2_status=$(echo "$health_response" | jq -r '.apiv2.status // "unknown"')

        if [ "$apiv1_status" = "success" ] && [ "$apiv2_status" = "success" ]; then
            echo "✅ API v1 and v2 services are healthy!"
            break
        else
            elapsed_min=$((elapsed / 60))
            echo "[${elapsed_min}m] API v1: $apiv1_status, API v2: $apiv2_status - waiting..."
        fi
    else
        echo "Status service not yet responding..."
    fi

    sleep 10
done

echo "🎉 API services are ready!"

# Run tests using shared script
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
exec "$SCRIPT_DIR/run-tests.sh" "$TEST_TYPE"
