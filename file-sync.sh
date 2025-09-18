#!/bin/bash
# File sync script for Freegle Docker development
# Monitors WSL filesystem changes and syncs to Docker containers

PROJECT_DIR="/workspace"

echo "Starting Freegle file sync monitor..."
echo "Project: $PROJECT_DIR"
echo "Press Ctrl+C to stop"
echo ""

# Function to determine target container
get_container_info() {
    local file_path="$1"
    local relative_path="${file_path#$PROJECT_DIR/}"
    
    if [[ "$relative_path" == iznik-nuxt3-modtools/* ]]; then
        echo "freegle-modtools /app/${relative_path#iznik-nuxt3-modtools/} ModTools"
    elif [[ "$relative_path" == iznik-nuxt3/* ]]; then
        # Sync to both Freegle and Playwright containers for test files
        if [[ "$relative_path" == iznik-nuxt3/tests/* || "$relative_path" == iznik-nuxt3/playwright.config.js ]]; then
            echo "freegle-playwright /app/${relative_path#iznik-nuxt3/} Playwright"
        else
            echo "freegle-freegle-dev /app/${relative_path#iznik-nuxt3/} Freegle-Dev"$'\n'"freegle-freegle-prod /app/${relative_path#iznik-nuxt3/} Freegle-Prod"
        fi
    elif [[ "$relative_path" == iznik-server-go/* ]]; then
        echo "freegle-apiv2 /app/${relative_path#iznik-server-go/} API-v2"
    elif [[ "$relative_path" == iznik-server/* ]]; then
        echo "freegle-apiv1 /var/www/iznik/${relative_path#iznik-server/} API-v1"
    fi
}

# Function to sync file
sync_file() {
    local file_path="$1"
    local container_info
    container_info=$(get_container_info "$file_path")
    
    if [[ -z "$container_info" ]]; then
        return
    fi
    
    local filename=$(basename "$file_path")
    local timestamp=$(date '+%H:%M:%S')
    
    # Handle multiple containers (for test files)
    while IFS= read -r line; do
        if [[ -n "$line" ]]; then
            read -r container target_path service <<< "$line"
            echo "[$timestamp] $service: $filename"
            
            if docker cp "$file_path" "$container:$target_path" 2>/dev/null; then
                echo "  ✓ Synced to $container"
            else
                echo "  ✗ Failed to sync to $container"
            fi
        fi
    done <<< "$container_info"
}

# Install inotify-tools if not present
if ! command -v inotifywait &> /dev/null; then
    echo "Installing inotify-tools..."
    # Check if we're in Alpine Linux container
    if [ -f /etc/alpine-release ]; then
        apk add --no-cache inotify-tools
    else
        sudo apt-get update && sudo apt-get install -y inotify-tools
    fi
fi

echo "Starting file watcher..."
echo ""

# Monitor file changes
inotifywait -m -r -e modify,create,move \
    --exclude '(node_modules|\.git|\.nuxt|\.output|dist|vendor|~|\.tmp|\.swp|\.log)' \
    "$PROJECT_DIR/iznik-nuxt3" \
    "$PROJECT_DIR/iznik-nuxt3-modtools" \
    "$PROJECT_DIR/iznik-server" \
    "$PROJECT_DIR/iznik-server-go" \
    2>/dev/null | while read -r directory events filename; do
    
    full_path="$directory$filename"
    
    # Only process regular files
    if [[ -f "$full_path" ]]; then
        sync_file "$full_path"
    fi
done