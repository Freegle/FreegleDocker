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
        echo "Timeout waiting for API services after 10 minutes"
        docker-compose -f docker-compose.yml logs
        exit 1
    fi

    # Check if status service is responding
    if curl -f -s http://localhost:8081 > /dev/null 2>&1; then
        echo "Status service is responding!"

        # Get health status from status service
        health_response=$(curl -s http://localhost:8081/api/status/all 2>/dev/null || echo '{}')

        # Check if API v1 and v2 are healthy
        apiv1_status=$(echo "$health_response" | jq -r '.apiv1.status // "unknown"')
        apiv2_status=$(echo "$health_response" | jq -r '.apiv2.status // "unknown"')

        if [ "$apiv1_status" = "success" ] && [ "$apiv2_status" = "success" ]; then
            echo "API v1 and v2 services are healthy!"
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

echo "API services are ready!"

# Run the appropriate tests via status service API (same as FreegleDocker CI)
case $TEST_TYPE in
    php)
        echo "=== Running PHPUnit tests via status service API ==="

        # Trigger tests via API
        response=$(curl -s -w "%{http_code}" -X POST -H "Content-Type: application/json" http://localhost:8081/api/tests/php)
        http_code="${response: -3}"

        if [ "$http_code" -ne "200" ]; then
            echo "Failed to trigger PHP tests. HTTP code: $http_code"
            echo "Response: $response"
            exit 1
        fi

        echo "PHP tests triggered successfully"

        # Monitor test progress with timeout
        echo "Monitoring PHP test progress..."
        start_time=$(date +%s)
        timeout_duration=2700  # 45 minutes

        while true; do
            current_time=$(date +%s)
            elapsed=$((current_time - start_time))

            if [ $elapsed -gt $timeout_duration ]; then
                echo "PHP tests timed out after 45 minutes"
                exit 1
            fi

            sleep 10
            status_response=$(curl -s http://localhost:8081/api/tests/php/status || echo '{"status":"error"}')
            status=$(echo "$status_response" | jq -r '.status // "unknown"')
            message=$(echo "$status_response" | jq -r '.message // "No message"')

            elapsed_min=$((elapsed / 60))
            echo "[${elapsed_min}m] Status: $status"
            echo "Message: $message"

            if [ "$status" = "completed" ]; then
                echo "PHP tests completed!"
                echo "PHPUnit tests passed!"
                break
            elif [ "$status" = "failed" ] || [ "$status" = "error" ]; then
                echo "PHP tests failed!"
                echo "Error details:"
                echo "$status_response" | jq -r '.logs // "No logs available"' | tail -30

                # Show the failure details from the PHPUnit debug log
                echo ""
                echo "Extracting failure details from PHPUnit output..."
                docker exec freegle-apiv1 sh -c '
                  if [ -f /tmp/phpunit-debug.log ]; then
                    echo "=== TEST FAILURES ==="
                    grep "##teamcity\[testFailed" /tmp/phpunit-debug.log | head -10 | while read -r line; do
                      test_name=$(echo "$line" | sed "s/.*name='"'"'\([^'"'"']*\)'"'"'.*/\1/")
                      message=$(echo "$line" | sed "s/.*message='"'"'\([^'"'"']*\)'"'"'.*/\1/")
                      details=$(echo "$line" | sed "s/.*details='"'"'\([^'"'"']*\)'"'"'.*/\1/" | sed "s/|n/\n/g")
                      if [ -n "$test_name" ]; then
                        echo "FAILED: $test_name"
                        [ -n "$message" ] && echo "   Message: $message"
                        [ -n "$details" ] && echo "   Details: $details"
                      fi
                    done

                    echo ""
                    echo "=== TEST SUMMARY ==="
                    tail -20 /tmp/phpunit-debug.log | grep -E "Tests:|FAILURES!|Skipped:" || echo "No summary found"
                  else
                    echo "Debug log not found"
                  fi
                ' || echo "Could not extract failure details"

                exit 1
            fi
        done
        ;;

    go)
        echo "=== Running Go tests via status service API ==="

        # Trigger tests via API
        response=$(curl -s -w "%{http_code}" -X POST -H "Content-Type: application/json" http://localhost:8081/api/tests/go)
        http_code="${response: -3}"

        if [ "$http_code" -ne "200" ]; then
            echo "Failed to trigger Go tests. HTTP code: $http_code"
            echo "Response: $response"
            exit 1
        fi

        echo "Go tests triggered successfully"

        # Monitor test progress with timeout
        echo "Monitoring Go test progress..."
        start_time=$(date +%s)
        timeout_duration=1800  # 30 minutes

        while true; do
            current_time=$(date +%s)
            elapsed=$((current_time - start_time))

            if [ $elapsed -gt $timeout_duration ]; then
                echo "Go tests timed out after 30 minutes"
                exit 1
            fi

            sleep 10
            status_response=$(curl -s http://localhost:8081/api/tests/go/status || echo '{"status":"error"}')
            status=$(echo "$status_response" | jq -r '.status // "unknown"')
            message=$(echo "$status_response" | jq -r '.message // "No message"')

            elapsed_min=$((elapsed / 60))
            echo "[${elapsed_min}m] Status: $status"
            echo "Message: $message"

            if [ "$status" = "completed" ]; then
                echo "Go tests completed!"
                echo "Go tests passed!"
                break
            elif [ "$status" = "failed" ] || [ "$status" = "error" ]; then
                echo "Go tests failed!"
                echo "Error details:"
                echo "$status_response" | jq -r '.logs // "No logs available"' | tail -50
                exit 1
            fi
        done
        ;;

    playwright)
        echo "=== Running Playwright tests via status service API ==="

        # Wait for production containers to be healthy first
        echo "Waiting for production containers to be healthy..."
        start_time=$(date +%s)
        timeout_duration=1200  # 20 minutes

        while true; do
            current_time=$(date +%s)
            elapsed=$((current_time - start_time))

            if [ $elapsed -gt $timeout_duration ]; then
                echo "Timeout waiting for production containers after 20 minutes"
                exit 1
            fi

            # Check both prod containers health
            health_response=$(curl -s http://localhost:8081/api/status/all 2>/dev/null || echo '{}')
            freegle_prod_status=$(echo "$health_response" | jq -r '.["freegle-prod"].status // "unknown"')
            modtools_prod_status=$(echo "$health_response" | jq -r '.["modtools-prod"].status // "unknown"')

            if [ "$freegle_prod_status" = "success" ] && [ "$modtools_prod_status" = "success" ]; then
                echo "Both production containers are healthy!"
                break
            else
                elapsed_min=$((elapsed / 60))
                echo "[${elapsed_min}m] Freegle Prod: $freegle_prod_status | ModTools Prod: $modtools_prod_status - waiting..."
                sleep 15
            fi
        done

        # Trigger tests via API
        response=$(curl -s -w "%{http_code}" -X POST -H "Content-Type: application/json" http://localhost:8081/api/tests/playwright)
        http_code="${response: -3}"

        if [ "$http_code" -ne "200" ]; then
            echo "Failed to trigger Playwright tests. HTTP code: $http_code"
            echo "Response: $response"
            exit 1
        fi

        echo "Playwright tests triggered successfully"

        # Monitor test progress with timeout
        echo "Monitoring Playwright test progress..."
        start_time=$(date +%s)
        timeout_duration=2700  # 45 minutes

        while true; do
            current_time=$(date +%s)
            elapsed=$((current_time - start_time))

            if [ $elapsed -gt $timeout_duration ]; then
                echo "Playwright tests timed out after 45 minutes"
                exit 1
            fi

            sleep 10
            status_response=$(curl -s http://localhost:8081/api/tests/playwright/status || echo '{"status":"error"}')
            status=$(echo "$status_response" | jq -r '.status // "unknown"')
            message=$(echo "$status_response" | jq -r '.message // "No message"')
            completed=$(echo "$status_response" | jq -r '.completedTests // 0')
            total=$(echo "$status_response" | jq -r '.totalTests // 0')

            elapsed_min=$((elapsed / 60))
            echo "[${elapsed_min}m] Status: $status, Progress: $completed/$total tests"
            echo "Message: $message"

            if [ "$status" = "completed" ]; then
                success=$(echo "$status_response" | jq -r '.success // false')
                echo "Playwright tests completed! Success: $success"

                if [ "$success" = "true" ]; then
                    echo "All Playwright tests passed!"
                    break
                else
                    echo "Some Playwright tests failed!"
                    echo "Test logs:"
                    echo "$status_response" | jq -r '.logs // "No logs available"' | tail -30
                    exit 1
                fi
            elif [ "$status" = "failed" ] || [ "$status" = "error" ]; then
                echo "Playwright tests failed to run!"
                echo "Error details:"
                echo "$status_response" | jq -r '.logs // "No logs available"' | tail -30
                exit 1
            fi
        done
        ;;
esac

echo "=== Tests completed successfully ==="
