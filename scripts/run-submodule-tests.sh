#!/bin/bash
# Run tests for a specific submodule using the FreegleDocker environment
# Usage: ./scripts/run-submodule-tests.sh <php|go> <path-to-pr-code>

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

# Create secrets files
echo "Creating secrets files..."
mkdir -p secrets
echo "placeholder" > secrets/lovejunk-api.txt
echo "placeholder" > secrets/lovejunk-secret.txt
echo "placeholder" > secrets/partner-key.txt
echo "placeholder" > secrets/partner-name.txt
echo "placeholder" > secrets/image-domain.txt

# Fix memory overcommit for Redis
sudo sysctl vm.overcommit_memory=1 || true

# Suppress docker-compose warnings about unset variables
export COMPOSE_QUIET=1

# Start required services based on test type
echo "Starting Docker services..."
if [ "$TEST_TYPE" = "playwright" ]; then
    # Playwright needs the full stack including the Nuxt production container
    docker-compose -f docker-compose.yml up -d percona redis apiv1 apiv2 freegle-prod traefik 2>&1 | grep -v "WARN\|variable is not set"
    REQUIRED_SERVICES="percona redis apiv1 apiv2 freegle-prod traefik"
else
    # PHP and Go tests only need the API services
    docker-compose -f docker-compose.yml up -d percona redis apiv1 apiv2 2>&1 | grep -v "WARN\|variable is not set"
    REQUIRED_SERVICES="percona redis apiv1 apiv2"
fi

echo "Waiting for services to start..."
sleep 30

# Function to check service status
check_service_status() {
    local service=$1
    local container_name=$(docker-compose -f docker-compose.yml ps -q $service 2>/dev/null)
    if [ -z "$container_name" ]; then
        echo "not started"
        return
    fi

    local status=$(docker inspect -f '{{.State.Status}}' "$container_name" 2>/dev/null)
    local health=$(docker inspect -f '{{if .State.Health}}{{.State.Health.Status}}{{else}}no-healthcheck{{end}}' "$container_name" 2>/dev/null)

    if [ "$status" = "running" ]; then
        if [ "$health" = "healthy" ] || [ "$health" = "no-healthcheck" ]; then
            echo "running"
        else
            echo "starting ($health)"
        fi
    else
        echo "$status"
    fi
}

# Wait for API services to be healthy
echo "Waiting for API services to be healthy..."
start_time=$(date +%s)
timeout_duration=600

while true; do
    current_time=$(date +%s)
    elapsed=$((current_time - start_time))

    if [ $elapsed -gt $timeout_duration ]; then
        echo "Timeout waiting for API services after 10 minutes"
        docker-compose -f docker-compose.yml logs 2>&1 | grep -v "WARN\|variable is not set"
        exit 1
    fi

    # Show status of all required services
    echo ""
    echo "Service status (${elapsed}s elapsed):"
    for service in $REQUIRED_SERVICES; do
        status=$(check_service_status $service)
        printf "  %-15s %s\n" "$service:" "$status"
    done

    # Check if API v1 container is healthy (uses Docker's built-in healthcheck)
    apiv1_health=$(docker inspect -f '{{if .State.Health}}{{.State.Health.Status}}{{else}}no-healthcheck{{end}}' freegle-apiv1 2>/dev/null)
    if [ "$apiv1_health" = "healthy" ]; then
        echo ""
        echo "API v1 is healthy - all services ready!"
        break
    fi

    sleep 10
done

# Wait for initial testenv.php to complete if it's running
# The container runs testenv.php on startup, we need to wait for it to finish
# Check that a PHP process is actually running testenv.php (not just the init shell containing the string)
echo "Waiting for initial database setup to complete..."
while docker-compose -f docker-compose.yml exec -T apiv1 sh -c "pgrep -f 'testenv.php' | xargs -I{} ps -p {} -o comm= | grep -q php" 2>/dev/null; do
    echo "  testenv.php still running..."
    sleep 5
done
echo "Initial database setup complete"

# Run the appropriate tests
case $TEST_TYPE in
    php)
        echo "=== Running PHPUnit tests ==="

        # Set up test environment
        echo "Setting up test environment..."
        docker-compose -f docker-compose.yml exec -T apiv1 sh -c "cd /var/www/iznik && php install/testenv.php"

        # Run PHPUnit tests
        echo "Running PHPUnit..."
        docker-compose -f docker-compose.yml exec -T apiv1 sh -c "cd /var/www/iznik && php composer/vendor/phpunit/phpunit/phpunit --configuration test/ut/php/phpunit.xml --teamcity"

        echo "PHPUnit tests completed!"
        ;;

    go)
        echo "=== Running Go tests ==="

        # Set up test environment
        echo "Setting up test environment..."
        docker-compose -f docker-compose.yml exec -T apiv1 sh -c "cd /var/www/iznik && php install/testenv.php"

        # Set up Go-specific test data
        echo "Setting up Go test data..."
        docker cp freegle-apiv2:/app/.circleci/testenv.php /tmp/go-testenv.php
        sed -i "s#dirname(__FILE__) . '/../include/config.php'#'/var/www/iznik/include/config.php'#" /tmp/go-testenv.php
        docker cp /tmp/go-testenv.php freegle-apiv1:/var/www/iznik/go-testenv.php
        docker-compose -f docker-compose.yml exec -T apiv1 sh -c "cd /var/www/iznik && php go-testenv.php"

        # Run Go tests
        echo "Running Go tests..."
        docker-compose -f docker-compose.yml exec -T apiv2 sh -c "export CGO_ENABLED=1 && go mod tidy && go test -v -race -coverprofile=coverage.out ./test/... -coverpkg ./..."

        echo "Go tests completed!"
        ;;

    playwright)
        echo "=== Running Playwright tests ==="

        # Wait for the production container to be ready
        echo "Waiting for Freegle production container to be ready..."
        start_time=$(date +%s)
        timeout_duration=900

        while true; do
            current_time=$(date +%s)
            elapsed=$((current_time - start_time))

            if [ $elapsed -gt $timeout_duration ]; then
                echo "Timeout waiting for Freegle production container after 15 minutes"
                docker-compose -f docker-compose.yml logs freegle-prod
                exit 1
            fi

            # Check if freegle-prod is responding
            if curl -f -s http://freegle-prod.localhost > /dev/null 2>&1; then
                echo "Freegle production container is responding"
                break
            fi

            echo "Waiting for Freegle production container... (${elapsed}s elapsed)"
            sleep 15
        done

        # Set up test environment
        echo "Setting up test environment..."
        docker-compose -f docker-compose.yml exec -T apiv1 sh -c "cd /var/www/iznik && php install/testenv.php"

        # Run Playwright tests
        echo "Running Playwright tests..."
        docker-compose -f docker-compose.yml run --rm playwright npx playwright test --reporter=github

        echo "Playwright tests completed!"
        ;;
esac

echo "=== Tests completed successfully ==="
