#!/bin/bash
# Restore a backup with visible progress indicators
# Usage: ./restore-backup-with-progress.sh <YYYYMMDD>

set -e

BACKUP_DATE=$1
BACKUP_BUCKET="gs://freegle_backup_uk"
BACKUP_DIR="/var/www/FreegleDocker/yesterday/data/backups"
LOG_FILE="/var/log/yesterday-restore-${BACKUP_DATE}.log"
COMPOSE_FILE="/var/www/FreegleDocker/docker-compose.yml"

if [ -z "$BACKUP_DATE" ]; then
    echo "Usage: $0 <YYYYMMDD>"
    exit 1
fi

if ! [[ "$BACKUP_DATE" =~ ^[0-9]{8}$ ]]; then
    echo "Error: Invalid date format. Use YYYYMMDD"
    exit 1
fi

exec > >(tee -a "$LOG_FILE") 2>&1

echo "=== Yesterday Restoration Started: $(date) ==="
echo "Restoring backup from date: ${BACKUP_DATE}..."

mkdir -p "$BACKUP_DIR"

FORMATTED_DATE="${BACKUP_DATE:0:4}-${BACKUP_DATE:4:2}-${BACKUP_DATE:6:2}"

echo "Finding backup for date ${FORMATTED_DATE}..."
BACKUP_FILE=$(gsutil ls "$BACKUP_BUCKET/iznik-${FORMATTED_DATE}-*.xbstream" 2>/dev/null | head -1)

if [ -z "$BACKUP_FILE" ]; then
    echo "❌ Could not find backup for date ${FORMATTED_DATE}"
    exit 1
fi

echo "Selected backup: $BACKUP_FILE"

BACKUP_SIZE=$(gsutil ls -l "$BACKUP_FILE" | grep -v TOTAL | awk '{print $1}')
BACKUP_SIZE_GB=$((BACKUP_SIZE / 1024 / 1024 / 1024))
echo "Backup size: ${BACKUP_SIZE_GB}GB (compressed)"

echo "Updating Yesterday code..."
cd /var/www/FreegleDocker
git pull
echo "✅ Code updated"

echo "Configuring Yesterday environment..."
if [ -f yesterday/docker-compose.override.yml ]; then
    cp yesterday/docker-compose.override.yml docker-compose.override.yml
    echo "✅ Copied docker-compose.override.yml (configured for production images)"
else
    echo "⚠️  Warning: yesterday/docker-compose.override.yml not found"
fi

echo "Stopping all Docker containers..."
docker compose down

echo "Removing old database data..."
docker volume rm freegle_db 2>/dev/null || true

echo "Creating fresh database volume..."
docker volume create freegle_db

echo "Getting volume path..."
VOLUME_PATH=$(docker volume inspect freegle_db -f '{{.Mountpoint}}')
echo "Volume path: ${VOLUME_PATH}"

ESTIMATED_FINAL_GB=$((BACKUP_SIZE_GB * 21 / 10))

echo ""
echo "=========================================="
echo "Streaming and extracting backup directly to volume..."
echo "Compressed: ${BACKUP_SIZE_GB}GB → Estimated final: ~${ESTIMATED_FINAL_GB}GB"
echo "This will take 10-15 minutes..."
echo "=========================================="
echo ""

# Show progress by monitoring extracted files in background
(
    sleep 5
    while [ ! -f "${VOLUME_PATH}/.extraction_done" ]; do
        FILE_COUNT=$(find "$VOLUME_PATH" -type f 2>/dev/null | wc -l)
        DIR_SIZE_RAW=$(du -sb "$VOLUME_PATH" 2>/dev/null | awk '{print $1}')
        DIR_SIZE=$(du -sh "$VOLUME_PATH" 2>/dev/null | awk '{print $1}')
        PERCENT=$((DIR_SIZE_RAW * 100 / (BACKUP_SIZE_GB * 1024 * 1024 * 1024)))
        echo "[$(date +%H:%M:%S)] Extracting: ${DIR_SIZE} / ~${ESTIMATED_FINAL_GB}GB (${PERCENT}% of compressed backup extracted)"
        sleep 10
    done
) &
PROGRESS_PID=$!

# Stream directly from GCS and extract to volume - no temp directory
gsutil cat "$BACKUP_FILE" | xbstream -x -C "$VOLUME_PATH"

# Signal extraction is done
touch "${VOLUME_PATH}/.extraction_done"
kill $PROGRESS_PID 2>/dev/null || true

echo ""
echo "✅ Extraction complete!"
FINAL_COUNT=$(find "$VOLUME_PATH" -type f 2>/dev/null | wc -l)
FINAL_SIZE=$(du -sh "$VOLUME_PATH" 2>/dev/null | awk '{print $1}')
echo "Total extracted: $FINAL_COUNT files, ${FINAL_SIZE}"
echo ""

echo "=========================================="
echo "Decompressing zstd files..."
echo "This will take 10-15 minutes..."
echo "=========================================="
echo ""

# Decompress all .zst files (zstd compression)
(
    ZST_COUNT=0
    while true; do
        CURRENT_ZST=$(find "$VOLUME_PATH" -type f -name "*.zst" 2>/dev/null | wc -l)
        if [ $CURRENT_ZST -eq 0 ]; then
            break
        fi
        if [ $ZST_COUNT -ne $CURRENT_ZST ]; then
            echo "[$(date +%H:%M:%S)] Decompressing... $CURRENT_ZST .zst files remaining"
            ZST_COUNT=$CURRENT_ZST
        fi
        sleep 10
    done
) &
ZSTD_PID=$!

for bf in $(find "$VOLUME_PATH" -type f -name "*.zst"); do
    zstd -d --rm "$bf"
done

kill $ZSTD_PID 2>/dev/null || true

echo ""
echo "✅ Decompression complete!"
echo ""

echo "=========================================="
echo "Preparing backup (applying transaction logs)..."
echo "This will take 10-15 minutes..."
echo "=========================================="
echo ""

# Show that preparation is running
(
    while ps aux | grep -q "[x]trabackup --prepare"; do
        echo "[$(date +%H:%M:%S)] Still preparing backup..."
        sleep 15
    done
) &
PREPARE_PID=$!

xtrabackup --prepare --apply-log-only --target-dir="$VOLUME_PATH"

kill $PREPARE_PID 2>/dev/null || true
echo ""
echo "✅ Preparation complete!"
echo ""

echo "Setting ownership..."
chown -R 999:999 "${VOLUME_PATH}"
rm -f "${VOLUME_PATH}/.extraction_done"
echo "✅ Volume ready"

# Function to wait for container to be healthy
wait_for_container_health() {
    local container=$1
    local max_wait=${2:-180}  # Default 3 minutes
    local waited=0

    echo "Waiting for $container to become healthy..."
    while [ $waited -lt $max_wait ]; do
        if docker compose ps "$container" 2>/dev/null | grep -q "healthy"; then
            echo "✅ $container is healthy"
            return 0
        fi

        if docker compose ps "$container" 2>/dev/null | grep -q "Up"; then
            # Container is up but may not have health check or not healthy yet
            sleep 5
            waited=$((waited + 5))
        else
            echo "⚠️  $container not running yet, waiting..."
            sleep 5
            waited=$((waited + 5))
        fi
    done

    echo "❌ Timeout waiting for $container to become healthy"
    return 1
}

# Function to check if all expected containers are running
verify_all_containers() {
    echo ""
    echo "Verifying all containers are running..."

    local failed_containers=()
    local created_containers=()

    # Get list of containers in Created state (not started)
    while IFS= read -r container; do
        if [ -n "$container" ]; then
            created_containers+=("$container")
        fi
    done < <(docker compose ps -a 2>/dev/null | grep "Created" | awk '{print $1}' | sed 's/freegle-//')

    # Get list of exited/unhealthy containers
    while IFS= read -r container; do
        if [ -n "$container" ]; then
            failed_containers+=("$container")
        fi
    done < <(docker compose ps 2>/dev/null | grep -E "(Exit|unhealthy)" | awk '{print $1}' | sed 's/freegle-//')

    if [ ${#created_containers[@]} -gt 0 ]; then
        echo "⚠️  Found containers in Created state (not started): ${created_containers[*]}"
        echo "Attempting to start them..."
        docker compose up -d
        sleep 10
    fi

    if [ ${#failed_containers[@]} -gt 0 ]; then
        echo "⚠️  Found failed containers: ${failed_containers[*]}"
        return 1
    fi

    echo "✅ All containers are running"
    return 0
}

echo ""
echo "Starting all Docker containers..."
docker compose up -d

echo ""
echo "Waiting for critical infrastructure containers..."

# Wait for critical containers with health checks
wait_for_container_health "percona" 240 || {
    echo "❌ Percona failed to start. Checking logs..."
    docker logs freegle-percona --tail 20
    exit 1
}

wait_for_container_health "reverse-proxy" 120 || {
    echo "❌ Traefik failed to start. Checking logs..."
    docker logs freegle-traefik --tail 20
    exit 1
}

wait_for_container_health "redis" 60
wait_for_container_health "postgres" 120
wait_for_container_health "beanstalkd" 60

echo ""
echo "Infrastructure containers ready. Waiting for application containers..."
sleep 10

# Verify all containers are running, retry if needed
MAX_RETRIES=3
RETRY=0
while [ $RETRY -lt $MAX_RETRIES ]; do
    if verify_all_containers; then
        break
    fi

    RETRY=$((RETRY + 1))
    if [ $RETRY -lt $MAX_RETRIES ]; then
        echo "Retry $RETRY/$MAX_RETRIES: Restarting failed containers..."
        docker compose up -d
        sleep 15
    fi
done

if [ $RETRY -eq $MAX_RETRIES ]; then
    echo "❌ Failed to start all containers after $MAX_RETRIES attempts"
    echo "Current container status:"
    docker compose ps
    exit 1
fi

# Wait for apiv2 to be healthy (it takes time to compile)
echo ""
echo "Waiting for API v2 to compile and start (this may take 60-90 seconds)..."
wait_for_container_health "apiv2" 180

echo ""
echo "Verifying database..."
if docker exec freegle-percona mysql -uroot -piznik -e "SHOW DATABASES;" 2>/dev/null | grep -q "iznik"; then
    echo "✅ Database verification successful!"

    TABLE_COUNT=$(docker exec freegle-percona mysql -uroot -piznik iznik -e "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema='iznik';" 2>/dev/null | tail -1)
    echo "Database contains ${TABLE_COUNT} tables"
else
    echo "❌ Database verification failed!"
    exit 1
fi

# Update current backup tracker
/var/www/FreegleDocker/yesterday/scripts/set-current-backup.sh "${BACKUP_DATE}"

echo ""
echo "=== Yesterday Restoration Completed: $(date) ==="
echo "✅ Backup from ${FORMATTED_DATE} restored successfully"
echo ""
echo "Access the system at:"
echo "  - Freegle: http://localhost:3000"
echo "  - ModTools: http://localhost:3001"
echo "  - Mailhog: http://localhost:8025"
