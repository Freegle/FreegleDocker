#!/bin/bash
# File sync script for Freegle Docker development
# Monitors WSL filesystem changes and syncs to Docker containers

# Use the directory where this script is located
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
PROJECT_DIR="$SCRIPT_DIR"

echo "Starting Freegle file sync monitor..."
echo "Project: $PROJECT_DIR"
echo "Press Ctrl+C to stop"
echo ""

# Debounce tracking - prevent syncing same file within 2 seconds
declare -A LAST_SYNC

# Function to determine target container
get_container_info() {
    local file_path="$1"
    local relative_path="${file_path#$PROJECT_DIR/}"

    if [[ "$relative_path" == iznik-nuxt3-modtools/* ]]; then
        local targets="modtools-dev-local /app/${relative_path#iznik-nuxt3-modtools/} ModTools-Dev-Local"
        # Only include dev-live if the container is running
        if docker ps --format '{{.Names}}' | grep -q '^modtools-dev-live$'; then
            targets="$targets"$'\n'"modtools-dev-live /app/${relative_path#iznik-nuxt3-modtools/} ModTools-Dev-Live"
        fi
        echo "$targets"
    elif [[ "$relative_path" == iznik-nuxt3/* ]]; then
        # Sync to both Freegle and Playwright containers for test files
        if [[ "$relative_path" == iznik-nuxt3/tests/* || "$relative_path" == iznik-nuxt3/playwright.config.js ]]; then
            echo "freegle-playwright /app/${relative_path#iznik-nuxt3/} Playwright"
        else
            local targets="freegle-dev-local /app/${relative_path#iznik-nuxt3/} Freegle-Dev-Local"
            # Only include dev-live if the container is running
            if docker ps --format '{{.Names}}' | grep -q '^freegle-dev-live$'; then
                targets="$targets"$'\n'"freegle-dev-live /app/${relative_path#iznik-nuxt3/} Freegle-Dev-Live"
            fi
            echo "$targets"
        fi
    elif [[ "$relative_path" == iznik-server-go/* ]]; then
        echo "apiv2 /app/${relative_path#iznik-server-go/} API-v2"
    elif [[ "$relative_path" == iznik-server/* ]]; then
        echo "apiv1 /var/www/iznik/${relative_path#iznik-server/} API-v1"
    elif [[ "$relative_path" == iznik-batch/* ]]; then
        echo "freegle-batch /var/www/html/${relative_path#iznik-batch/} Batch"
    fi
}

# Function to sync file with debouncing
sync_file() {
    local file_path="$1"
    local now=$(date +%s)

    # Check debounce - skip if synced within last 2 seconds
    local last="${LAST_SYNC[$file_path]:-0}"
    if (( now - last < 2 )); then
        return
    fi
    LAST_SYNC[$file_path]=$now

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
    if [ -f /etc/alpine-release ]; then
        apk add --no-cache inotify-tools
    else
        sudo apt-get update && sudo apt-get install -y inotify-tools
    fi
fi

echo "Starting file watcher..."
echo ""

# Monitor file changes - exclude node_modules, .git, build artifacts, and migrations
inotifywait -m -r -e modify,create,move \
    --exclude '(node_modules|\.git|\.nuxt|\.output|dist|vendor|migrations|~|\.tmp|\.swp|\.log)' \
    "$PROJECT_DIR/iznik-nuxt3" \
    "$PROJECT_DIR/iznik-nuxt3-modtools" \
    "$PROJECT_DIR/iznik-server" \
    "$PROJECT_DIR/iznik-server-go" \
    "$PROJECT_DIR/iznik-batch" \
    2>/dev/null | while read -r directory events filename; do

    full_path="$directory$filename"

    # Only process regular files
    if [[ -f "$full_path" ]]; then
        sync_file "$full_path"
    fi
done
