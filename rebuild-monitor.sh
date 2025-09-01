#!/bin/bash

# Monitor for rebuild requests from status container
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
cd "$SCRIPT_DIR"

echo "Starting rebuild monitor..."

while true; do
    # Look for rebuild request files
    for request_file in rebuild-*.json; do
        # Skip if no files match the pattern
        [[ ! -f "$request_file" ]] && continue
        
        echo "Processing rebuild request: $request_file"
        
        # Read the request
        service=$(jq -r '.service' "$request_file" 2>/dev/null)
        container=$(jq -r '.container' "$request_file" 2>/dev/null)
        
        if [[ "$service" == "null" ]] || [[ "$container" == "null" ]]; then
            echo "Invalid request file, skipping: $request_file"
            rm -f "$request_file"
            continue
        fi
        
        echo "Rebuilding service: $service ($container)"
        
        # Execute the rebuild
        if docker-compose stop "$service" && \
           docker-compose rm -f "$service" && \
           docker-compose build "$service" && \
           docker-compose up --no-deps -d "$service"; then
            echo "Rebuild completed successfully for $service"
        else
            echo "Rebuild failed for $service"
        fi
        
        # Remove the request file to signal completion
        rm -f "$request_file"
    done
    
    # Wait before checking again
    sleep 2
done