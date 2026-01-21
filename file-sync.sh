#!/bin/bash
# File sync script for Freegle Docker development
# Monitors WSL filesystem changes and syncs to Docker containers
# Uses a "settle" pattern - waits for files to stop changing before syncing

# Use the directory where this script is located
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
PROJECT_DIR="$SCRIPT_DIR"

# Queue file for pending syncs
QUEUE_FILE="/tmp/freegle-sync-queue.$$"
QUEUE_LOCK="/tmp/freegle-sync-queue.$$.lock"

echo "Starting Freegle file sync monitor..."
echo "Project: $PROJECT_DIR"
echo "Press Ctrl+C to stop"
echo ""

# Cleanup on exit
cleanup() {
    rm -f "$QUEUE_FILE" "$QUEUE_LOCK"
    kill $SETTLE_PID 2>/dev/null
}
trap cleanup EXIT

# Function to get file mtime
get_mtime() {
    local file="$1"
    if [[ -f "$file" ]]; then
        stat -c %Y "$file" 2>/dev/null || echo "0"
    else
        echo "0"
    fi
}

# Function to get file size
get_size() {
    local file="$1"
    if [[ -f "$file" ]]; then
        stat -c %s "$file" 2>/dev/null || echo "0"
    else
        echo "0"
    fi
}

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
        echo "freegle-apiv2 /app/${relative_path#iznik-server-go/} API-v2"
    elif [[ "$relative_path" == iznik-server/* ]]; then
        echo "freegle-apiv1 /var/www/iznik/${relative_path#iznik-server/} API-v1"
    elif [[ "$relative_path" == iznik-batch/* ]]; then
        echo "freegle-batch /var/www/html/${relative_path#iznik-batch/} Batch"
    fi
}

# Function to actually perform the sync
do_sync() {
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

            local cp_error
            if cp_error=$(docker cp "$file_path" "$container:$target_path" 2>&1); then
                echo "  ✓ Synced to $container"
            else
                echo "  ✗ Failed to sync to $container: $cp_error"
            fi
        fi
    done <<< "$container_info"
}

# Background process to handle settling files
process_pending_syncs() {
    declare -A PENDING_MTIME
    declare -A ZERO_LENGTH_WAIT  # Track how long we've waited for zero-length files

    while true; do
        sleep 2

        # Read any new entries from queue file with locking
        if [[ -s "$QUEUE_FILE" ]]; then
            # Copy and clear atomically with lock
            local temp_queue="/tmp/freegle-sync-temp.$$"
            (
                flock -x 200
                cp "$QUEUE_FILE" "$temp_queue" 2>/dev/null
                > "$QUEUE_FILE"
            ) 200>"$QUEUE_LOCK"

            # Now read from temp file (outside the lock/subshell)
            if [[ -f "$temp_queue" ]]; then
                while IFS='|' read -r file_path mtime; do
                    if [[ -n "$file_path" ]]; then
                        PENDING_MTIME["$file_path"]=$mtime
                    fi
                done < "$temp_queue"
                rm -f "$temp_queue"
            fi
        fi

        # Process pending files
        for file_path in "${!PENDING_MTIME[@]}"; do
            if [[ ! -f "$file_path" ]]; then
                # File was deleted, remove from pending
                unset PENDING_MTIME["$file_path"]
                unset ZERO_LENGTH_WAIT["$file_path"]
                continue
            fi

            local current_mtime=$(get_mtime "$file_path")
            local last_mtime="${PENDING_MTIME[$file_path]}"

            if [[ "$current_mtime" == "$last_mtime" ]]; then
                # File hasn't changed in 2 seconds - it's settled
                local file_size=$(get_size "$file_path")

                if [[ "$file_size" == "0" ]]; then
                    # Zero-length file - likely still being written
                    # Wait up to 10 more seconds before giving up
                    local wait_count="${ZERO_LENGTH_WAIT[$file_path]:-0}"
                    if (( wait_count < 5 )); then
                        ZERO_LENGTH_WAIT["$file_path"]=$((wait_count + 1))
                        echo "[$(date '+%H:%M:%S')] Waiting for content: $(basename "$file_path") (attempt $((wait_count + 1))/5)"
                        continue
                    else
                        # Gave up waiting - sync anyway (might be intentionally empty)
                        echo "[$(date '+%H:%M:%S')] Warning: Syncing zero-length file after timeout: $(basename "$file_path")"
                        unset ZERO_LENGTH_WAIT["$file_path"]
                    fi
                fi

                do_sync "$file_path"
                unset PENDING_MTIME["$file_path"]
            else
                # File changed, update mtime and wait another cycle
                PENDING_MTIME["$file_path"]=$current_mtime
                # Reset zero-length wait counter since file is changing
                unset ZERO_LENGTH_WAIT["$file_path"]
            fi
        done
    done
}

# Function to queue a file for sync
queue_sync() {
    local file_path="$1"
    local mtime=$(get_mtime "$file_path")
    (
        flock -x 200
        echo "${file_path}|${mtime}" >> "$QUEUE_FILE"
    ) 200>"$QUEUE_LOCK"
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

echo "Starting file watcher with settle detection..."
echo "(Files sync after 2 seconds of no changes)"
echo "(Zero-length files wait up to 10 extra seconds for content)"
echo ""

# Initialize queue file
> "$QUEUE_FILE"

# Start the settle processor in the background
process_pending_syncs &
SETTLE_PID=$!

# Monitor file changes - exclude node_modules, .git, build artifacts, and migrations
# IMPORTANT: close_write is essential - it signals when a file is fully written
# Without it, we might sync files while they're still being written (empty/partial)
inotifywait -m -r -e modify,create,move,close_write \
    --exclude '(node_modules|\.git|\.nuxt|\.output|dist|vendor|migrations|~|\.tmp|\.swp|\.log)' \
    "$PROJECT_DIR/iznik-nuxt3" \
    "$PROJECT_DIR/iznik-nuxt3-modtools" \
    "$PROJECT_DIR/iznik-server" \
    "$PROJECT_DIR/iznik-server-go" \
    "$PROJECT_DIR/iznik-batch" \
    2>/dev/null | while read -r directory events filename; do

    full_path="$directory$filename"

    # Only process regular files - queue them for sync after settling
    if [[ -f "$full_path" ]]; then
        queue_sync "$full_path"
    fi
done
