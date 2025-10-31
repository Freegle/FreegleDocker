#!/bin/bash
# Restore a backup into the existing FreegleDocker database
# Uses the existing docker-compose.yml infrastructure
# Usage: ./restore-backup-simple.sh <YYYYMMDD>
# Example: ./restore-backup-simple.sh 20251031

set -e  # Exit on error

BACKUP_DATE=$1
BACKUP_BUCKET="gs://freegle_backup_uk"
BACKUP_DIR="/var/www/FreegleDocker/yesterday/data/backups"
LOG_FILE="/var/log/yesterday-restore-${BACKUP_DATE}.log"
COMPOSE_FILE="/var/www/FreegleDocker/docker-compose.yml"

if [ -z "$BACKUP_DATE" ]; then
    echo "Usage: $0 <YYYYMMDD>"
    echo "Example: $0 20251031"
    exit 1
fi

# Validate date format
if ! [[ "$BACKUP_DATE" =~ ^[0-9]{8}$ ]]; then
    echo "Error: Invalid date format. Use YYYYMMDD (e.g., 20251031)"
    exit 1
fi

# Setup logging
exec > >(tee -a "$LOG_FILE") 2>&1

echo "=== Yesterday Restoration Started: $(date) ==="
echo "Restoring backup from date: ${BACKUP_DATE}..."
echo "Will import into existing FreegleDocker database"

# Create backup directory if it doesn't exist
mkdir -p "$BACKUP_DIR"

# Convert YYYYMMDD to YYYY-MM-DD for filename matching
FORMATTED_DATE="${BACKUP_DATE:0:4}-${BACKUP_DATE:4:2}-${BACKUP_DATE:6:2}"

# Find the backup file for this date
echo "Finding backup for date ${FORMATTED_DATE}..."
BACKUP_FILE=$(gsutil ls "$BACKUP_BUCKET/iznik-${FORMATTED_DATE}-*.xbstream" 2>/dev/null | head -1)

if [ -z "$BACKUP_FILE" ]; then
    echo "❌ Could not find backup for date ${FORMATTED_DATE}"
    echo "Searched for: ${BACKUP_BUCKET}/iznik-${FORMATTED_DATE}-*.xbstream"
    exit 1
fi

echo "Selected backup: $BACKUP_FILE"

# Get file size for progress estimate
BACKUP_SIZE=$(gsutil ls -l "$BACKUP_FILE" | grep -v TOTAL | awk '{print $1}')
BACKUP_SIZE_GB=$((BACKUP_SIZE / 1024 / 1024 / 1024))
echo "Backup size: ${BACKUP_SIZE_GB}GB (~${BACKUP_SIZE} bytes)"

# Download backup if not already present
LOCAL_BACKUP="$BACKUP_DIR/$(basename $BACKUP_FILE)"
if [ ! -f "$LOCAL_BACKUP" ]; then
    echo "Downloading backup from GCS (this may take 5-10 minutes for ${BACKUP_SIZE_GB}GB)..."
    gsutil cp "$BACKUP_FILE" "$LOCAL_BACKUP"
    echo "✅ Download complete"
else
    echo "Using cached local backup: $LOCAL_BACKUP"
fi

# Stop all containers to free resources
echo "Stopping all Docker containers..."
cd /var/www/FreegleDocker
docker compose down

# Remove old database data
echo "Removing old database data..."
docker volume rm freegle_db 2>/dev/null || true

# Create fresh database volume
echo "Creating fresh database volume..."
docker volume create freegle_db

# Extract and prepare xbstream backup
echo "Extracting xbstream backup (this may take 2-3 minutes)..."
TEMP_DIR="$BACKUP_DIR/temp-${BACKUP_DATE}"
rm -rf "$TEMP_DIR"
mkdir -p "$TEMP_DIR"

xbstream -x < "$LOCAL_BACKUP" -C "$TEMP_DIR"
echo "✅ Extraction complete"

# Prepare the backup (applies transaction logs)
echo "Preparing backup (this may take 5-10 minutes)..."
xtrabackup --prepare --target-dir="$TEMP_DIR"
echo "✅ Preparation complete"

# Copy restored data to database volume
echo "Copying restored data to database volume..."
VOLUME_PATH=$(docker volume inspect freegle_db -f '{{.Mountpoint}}')

# Copy prepared backup to volume
cp -r "$TEMP_DIR"/* "${VOLUME_PATH}/"

# Fix permissions for MariaDB
chown -R 999:999 "${VOLUME_PATH}"

# Clean up temp directory
rm -rf "$TEMP_DIR"
echo "✅ Data copied to database volume"

# Start all containers
echo "Starting all Docker containers..."
docker compose up -d

# Wait for database to be ready
echo "Waiting for database to start (30 seconds)..."
sleep 30

# Verify restoration
echo "Verifying database..."
if docker exec freegle-db mysql -uroot -p"${IZNIK_DB_PASSWORD}" -e "SHOW DATABASES;" 2>/dev/null | grep -q "iznik"; then
    echo "✅ Database verification successful!"

    # Get some stats about the restored database
    TABLE_COUNT=$(docker exec freegle-db mysql -uroot -p"${IZNIK_DB_PASSWORD}" iznik -e "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema='iznik';" 2>/dev/null | tail -1)
    echo "Database contains ${TABLE_COUNT} tables"
else
    echo "❌ Database verification failed!"
    echo "Check logs: docker compose logs db"
    exit 1
fi

echo ""
echo "=== Yesterday Restoration Completed: $(date) ==="
echo "✅ Backup from ${FORMATTED_DATE} restored successfully"

# Update current backup tracker
/var/www/FreegleDocker/yesterday/scripts/set-current-backup.sh "${BACKUP_DATE}"

echo ""
echo "All services are now running with the restored data:"
echo "  - Database: Restored from ${FORMATTED_DATE}"
echo "  - Freegle: http://freegle-prod.localhost (or yesterday domain)"
echo "  - ModTools: http://modtools.localhost (or yesterday domain)"
echo "  - Mailhog: http://localhost:8025 (or yesterday domain)"
echo ""
echo "Current backup loaded: ${BACKUP_DATE}"
echo "To switch to a different backup, run this script again with a different date"
