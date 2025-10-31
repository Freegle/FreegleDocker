#!/bin/bash
# Restore a specific day's backup from GCS
# Usage: ./restore-day.sh <day-number>
# Example: ./restore-day.sh 0  (restores most recent backup as Day 0)

set -e  # Exit on error

DAY=${1:-0}
BACKUP_BUCKET="gs://freegle_backup_uk"
BACKUP_DIR="/var/www/FreegleDocker/yesterday/data/backups"
LOG_FILE="/var/log/yesterday-restore-day${DAY}.log"

# Setup logging
exec > >(tee -a "$LOG_FILE") 2>&1

echo "=== Yesterday Restoration Started: $(date) ==="
echo "Restoring Day ${DAY}..."

# Create backup directory if it doesn't exist
mkdir -p "$BACKUP_DIR"

# Find the backup to restore
# Day 0 = most recent, Day 1 = 1 day ago, etc.
echo "Finding backup for Day ${DAY}..."
BACKUP_FILE=$(gsutil ls -l "$BACKUP_BUCKET/iznik-*.xbstream" | sort -k2 -r | sed -n "$((DAY + 1))p" | awk '{print $3}')

if [ -z "$BACKUP_FILE" ]; then
    echo "❌ Could not find backup for Day ${DAY}"
    exit 1
fi

BACKUP_DATE=$(basename "$BACKUP_FILE" | sed 's/iznik-\(.*\)\.xbstream/\1/')
echo "Selected backup: $BACKUP_FILE (Date: $BACKUP_DATE)"

# Download backup if not already present
LOCAL_BACKUP="$BACKUP_DIR/$(basename $BACKUP_FILE)"
if [ ! -f "$LOCAL_BACKUP" ]; then
    echo "Downloading backup from GCS..."
    gsutil cp "$BACKUP_FILE" "$LOCAL_BACKUP"
    echo "✅ Download complete"
else
    echo "Using existing local backup: $LOCAL_BACKUP"
fi

# Stop the database container if running
echo "Stopping database container yesterday-${DAY}-db..."
docker compose -f /var/www/FreegleDocker/yesterday/docker-compose.yesterday.yml stop yesterday-${DAY}-db || true

# Remove old database data
echo "Removing old database data..."
docker volume rm yesterday-${DAY}-db-data || true

# Create fresh database volume
echo "Creating fresh database volume..."
docker volume create yesterday-${DAY}-db-data

# Start database container
echo "Starting database container..."
docker compose -f /var/www/FreegleDocker/yesterday/docker-compose.yesterday.yml up -d yesterday-${DAY}-db

# Wait for database to be ready
echo "Waiting for database to be ready..."
sleep 30

# Extract and restore xbstream backup
echo "Extracting and restoring xbstream backup..."

# Create temporary directory for extraction
TEMP_DIR="$BACKUP_DIR/temp-${DAY}"
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
docker compose -f /var/www/FreegleDocker/yesterday/docker-compose.yesterday.yml stop yesterday-${DAY}-db

# Copy restored data to database volume
echo "Copying restored data to database volume..."
# Get the volume mount point
VOLUME_PATH=$(docker volume inspect yesterday-${DAY}-db-data -f '{{.Mountpoint}}')

# Remove MySQL's default data
rm -rf "${VOLUME_PATH}"/*

# Copy prepared backup to volume
cp -r "$TEMP_DIR"/* "${VOLUME_PATH}/"

# Fix permissions
chown -R 999:999 "${VOLUME_PATH}"

# Clean up temp directory
rm -rf "$TEMP_DIR"

# Start database
echo "Starting database with restored data..."
docker compose -f /var/www/FreegleDocker/yesterday/docker-compose.yesterday.yml up -d yesterday-${DAY}-db

# Wait for database to be ready
echo "Waiting for database to be ready..."
sleep 30

# Verify restoration
echo "Verifying database..."
if docker exec yesterday-${DAY}-db mysql -uroot -p"${DB_ROOT_PASSWORD}" -e "SHOW DATABASES;" | grep -q "iznik"; then
    echo "✅ Database verification successful!"
else
    echo "❌ Database verification failed!"
    exit 1
fi

echo "=== Yesterday Restoration Completed: $(date) ==="
echo "✅ Day ${DAY} restored successfully from backup dated ${BACKUP_DATE}"
echo ""
echo "You can now access the restored environment at:"
echo "  - Database: yesterday-${DAY}-db (container)"
echo "  - Mailhog: https://mail.yesterday-${DAY}.ilovefreegle.org"
echo ""
echo "To start the full environment (Freegle/ModTools), start the application containers."
