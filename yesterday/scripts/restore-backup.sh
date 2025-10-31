#!/bin/bash
# Restore a specific backup by date from GCS
# Usage: ./restore-backup.sh <YYYYMMDD>
# Example: ./restore-backup.sh 20251031

set -e  # Exit on error

BACKUP_DATE=$1
BACKUP_BUCKET="gs://freegle_backup_uk"
BACKUP_DIR="/var/www/FreegleDocker/yesterday/data/backups"
LOG_FILE="/var/log/yesterday-restore-${BACKUP_DATE}.log"

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

# Download backup if not already present
LOCAL_BACKUP="$BACKUP_DIR/$(basename $BACKUP_FILE)"
if [ ! -f "$LOCAL_BACKUP" ]; then
    echo "Downloading backup from GCS..."
    echo "Size: $(gsutil ls -l "$BACKUP_FILE" | grep -v TOTAL | awk '{print $1}') bytes"
    gsutil cp "$BACKUP_FILE" "$LOCAL_BACKUP"
    echo "✅ Download complete"
else
    echo "Using existing local backup: $LOCAL_BACKUP"
fi

# Stop the database container if running
echo "Stopping database container yesterday-${BACKUP_DATE}-db..."
docker compose -f /var/www/FreegleDocker/yesterday/docker-compose.yesterday.yml stop yesterday-${BACKUP_DATE}-db 2>/dev/null || true

# Remove old database data
echo "Removing old database data..."
docker volume rm yesterday-${BACKUP_DATE}-db-data 2>/dev/null || true

# Create fresh database volume
echo "Creating fresh database volume..."
docker volume create yesterday-${BACKUP_DATE}-db-data

# Start database container
echo "Starting database container..."
# Update docker-compose to use date-based naming
export YESTERDAY_BACKUP_DATE=$BACKUP_DATE
docker compose -f /var/www/FreegleDocker/yesterday/docker-compose.yesterday.yml up -d yesterday-${BACKUP_DATE}-db

# Wait for database to be ready
echo "Waiting for database to initialize..."
sleep 30

# Extract and restore xbstream backup
echo "Extracting and restoring xbstream backup..."

# Create temporary directory for extraction
TEMP_DIR="$BACKUP_DIR/temp-${BACKUP_DATE}"
rm -rf "$TEMP_DIR"
mkdir -p "$TEMP_DIR"

# Extract xbstream
echo "Extracting xbstream..."
xbstream -x < "$LOCAL_BACKUP" -C "$TEMP_DIR"

# Prepare the backup (applies logs)
echo "Preparing backup (this may take several minutes)..."
xtrabackup --prepare --target-dir="$TEMP_DIR"

# Stop database to copy files
echo "Stopping database for file copy..."
docker compose -f /var/www/FreegleDocker/yesterday/docker-compose.yesterday.yml stop yesterday-${BACKUP_DATE}-db

# Copy restored data to database volume
echo "Copying restored data to database volume..."
# Get the volume mount point
VOLUME_PATH=$(docker volume inspect yesterday-${BACKUP_DATE}-db-data -f '{{.Mountpoint}}')

# Remove MySQL's default data
rm -rf "${VOLUME_PATH}"/*

# Copy prepared backup to volume
cp -r "$TEMP_DIR"/* "${VOLUME_PATH}/"

# Fix permissions
chown -R 999:999 "${VOLUME_PATH}"

# Clean up temp directory
rm -rf "$TEMP_DIR"

# Start database with restored data
echo "Starting database with restored data..."
docker compose -f /var/www/FreegleDocker/yesterday/docker-compose.yesterday.yml up -d yesterday-${BACKUP_DATE}-db

# Wait for database to be ready
echo "Waiting for database to be ready..."
sleep 30

# Verify restoration
echo "Verifying database..."
DB_PASSWORD=$(grep DB_ROOT_PASSWORD /var/www/FreegleDocker/yesterday/.env | cut -d'=' -f2)
if docker exec yesterday-${BACKUP_DATE}-db mysql -uroot -p"${DB_PASSWORD}" -e "SHOW DATABASES;" 2>/dev/null | grep -q "iznik"; then
    echo "✅ Database verification successful!"
else
    echo "❌ Database verification failed!"
    exit 1
fi

echo "=== Yesterday Restoration Completed: $(date) ==="
echo "✅ Backup ${BACKUP_DATE} restored successfully"
echo ""
echo "You can now access the restored environment at:"
echo "  - Database: yesterday-${BACKUP_DATE}-db (container)"
echo "  - Mailhog: https://mail.yesterday-${BACKUP_DATE}.ilovefreegle.org"
