#!/bin/bash
# Script to repeatedly run testTyping inside Docker container

MAX_RUNS=50
FAIL_COUNT=0
PASS_COUNT=0

echo "Running testTyping test repeatedly inside Docker (up to $MAX_RUNS times)..."
echo "Press Ctrl+C to stop"
echo ""

for i in $(seq 1 $MAX_RUNS); do
    echo "====================================================================="
    echo "Run #$i at $(date '+%Y-%m-%d %H:%M:%S')"
    echo "====================================================================="

    # Run the test inside the Docker container
    cd /home/edward/FreegleDockerWSL
    docker-compose exec -T apiv1 bash -c "
        cd /var/www/iznik && \
        php -d memory_limit=-1 -d max_execution_time=0 \
            /var/www/iznik/composer/vendor/phpunit/phpunit/phpunit \
            --configuration /var/www/iznik/test/ut/php/phpunit.xml \
            --no-coverage \
            --teamcity \
            --filter testTyping \
            test/ut/php/api/chatMessagesAPITest.php
    " 2>&1 | tee /tmp/testTyping-run-$i.log

    EXIT_CODE=${PIPESTATUS[0]}

    if [ $EXIT_CODE -eq 0 ]; then
        echo ""
        echo "✓ Run #$i PASSED"
        PASS_COUNT=$((PASS_COUNT + 1))
    else
        echo ""
        echo "✗ Run #$i FAILED (exit code: $EXIT_CODE)"
        FAIL_COUNT=$((FAIL_COUNT + 1))

        # Show the failure details
        echo ""
        echo "FAILURE DETAILS:"
        grep "testFailed" /tmp/testTyping-run-$i.log | head -10
        echo ""

        # Extract timing information from the log
        echo "TIMING INFORMATION FROM FAILURE:"
        grep -E "(Message time|Typing action result|Stage)" /tmp/testTyping-run-$i.log | tail -20
        echo ""

        # Save detailed failure log
        cp /tmp/testTyping-run-$i.log /tmp/testTyping-failure-run-$i.log
        echo "Detailed failure log saved to: /tmp/testTyping-failure-run-$i.log"

        echo ""
        echo "Failure reproduced on run #$i!"
        echo "Summary so far: $PASS_COUNT passed, $FAIL_COUNT failed out of $i runs"
        echo ""

        # Continue to get more data points
        if [ $FAIL_COUNT -ge 3 ]; then
            echo "Got 3 failures - stopping to analyze"
            break
        fi
    fi

    # Small delay between runs
    sleep 1
done

echo ""
echo "====================================================================="
echo "FINAL SUMMARY"
echo "====================================================================="
echo "Total runs: $((PASS_COUNT + FAIL_COUNT))"
echo "Passed: $PASS_COUNT"
echo "Failed: $FAIL_COUNT"
if [ $FAIL_COUNT -gt 0 ]; then
    echo "Failure rate: $(awk "BEGIN {printf \"%.2f\", ($FAIL_COUNT / ($PASS_COUNT + $FAIL_COUNT)) * 100}")%"
    echo ""
    echo "Failure logs saved to /tmp/testTyping-failure-run-*.log"
fi
echo "====================================================================="
