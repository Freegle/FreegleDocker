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

echo "Stopping all Docker containers..."
cd /var/www/FreegleDocker
docker compose down

echo "Removing old database data..."
docker volume rm freegle_db 2>/dev/null || true

echo "Creating fresh database volume..."
docker volume create freegle_db

echo "Getting volume path..."
VOLUME_PATH=$(docker volume inspect freegle_db -f '{{.Mountpoint}}')
echo "Volume path: ${VOLUME_PATH}"

echo ""
echo "=========================================="
echo "Streaming and extracting backup directly to volume..."
echo "Total backup size: ${BACKUP_SIZE_GB}GB (compressed)"
echo "This will take 10-15 minutes..."
echo "=========================================="
echo ""

# Show progress by monitoring extracted files in background
(
    sleep 5
    while [ ! -f "${VOLUME_PATH}/.extraction_done" ]; do
        FILE_COUNT=$(find "$VOLUME_PATH" -type f 2>/dev/null | wc -l)
        DIR_SIZE=$(du -sh "$VOLUME_PATH" 2>/dev/null | awk '{print $1}')
        echo "[$(date +%H:%M:%S)] Extracting: $FILE_COUNT files, ${DIR_SIZE}"
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

echo ""
echo "Starting all Docker containers..."
docker compose up -d

echo "Waiting for database to start (30 seconds)..."
sleep 30

echo "Verifying database..."
if docker exec freegle-db mysql -uroot -p"${IZNIK_DB_PASSWORD}" -e "SHOW DATABASES;" 2>/dev/null | grep -q "iznik"; then
    echo "✅ Database verification successful!"

    TABLE_COUNT=$(docker exec freegle-db mysql -uroot -p"${IZNIK_DB_PASSWORD}" iznik -e "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema='iznik';" 2>/dev/null | tail -1)
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
