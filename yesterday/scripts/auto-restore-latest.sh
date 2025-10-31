#!/bin/bash
# Automatically restore the latest backup if it's newer than current
# Designed to run via cron after nightly backups complete
# Usage: ./auto-restore-latest.sh

set -e

BACKUP_BUCKET="gs://freegle_backup_uk"
STATE_FILE="/var/www/FreegleDocker/yesterday/data/current-backup.json"
LOG_FILE="/var/log/yesterday-auto-restore.log"

exec > >(tee -a "$LOG_FILE") 2>&1

echo ""
echo "=== Auto-Restore Check: $(date) ==="

# Get the latest backup from GCS
echo "Checking for latest backup in ${BACKUP_BUCKET}..."
LATEST_BACKUP=$(gsutil ls -l "$BACKUP_BUCKET/iznik-*.xbstream" | grep -v TOTAL | sort -k2 -r | head -1)

if [ -z "$LATEST_BACKUP" ]; then
    echo "❌ No backups found in bucket"
    exit 1
fi

LATEST_FILE=$(echo "$LATEST_BACKUP" | awk '{print $3}')
LATEST_FILENAME=$(basename "$LATEST_FILE")
LATEST_TIMESTAMP=$(echo "$LATEST_BACKUP" | awk '{print $2}')

# Extract date from filename: iznik-2025-10-31-04-00.xbstream -> 20251031
LATEST_DATE=$(echo "$LATEST_FILENAME" | grep -oP 'iznik-\K\d{4}-\d{2}-\d{2}' | tr -d '-')

echo "Latest backup found: $LATEST_FILENAME (Date: $LATEST_DATE)"
echo "Backup timestamp: $LATEST_TIMESTAMP"

# Safety check: Only restore backups that are at least 2 hours old
# This ensures upload is complete and file is stable
BACKUP_AGE_SECONDS=$(( $(date +%s) - $(date -d "$LATEST_TIMESTAMP" +%s) ))
BACKUP_AGE_HOURS=$(( BACKUP_AGE_SECONDS / 3600 ))

echo "Backup age: ${BACKUP_AGE_HOURS} hours"

if [ $BACKUP_AGE_HOURS -lt 2 ]; then
    echo "⚠️  Backup is too recent (less than 2 hours old)"
    echo "Waiting for upload to complete and file to stabilize"
    echo "Will retry on next run"
    exit 0
fi

echo "✅ Backup is stable (${BACKUP_AGE_HOURS} hours old)"

# Check what's currently loaded
if [ -f "$STATE_FILE" ]; then
    CURRENT_DATE=$(jq -r '.date // ""' "$STATE_FILE")
    CURRENT_LOADED_AT=$(jq -r '.loaded_at // ""' "$STATE_FILE")
    echo "Currently loaded: $CURRENT_DATE (loaded at $CURRENT_LOADED_AT)"
else
    CURRENT_DATE=""
    echo "No backup currently loaded"
fi

# Compare dates
if [ "$LATEST_DATE" = "$CURRENT_DATE" ]; then
    echo "✅ Already running latest backup ($LATEST_DATE)"
    echo "No action needed"
    exit 0
fi

echo ""
echo "🔄 New backup available: $LATEST_DATE (current: ${CURRENT_DATE:-none})"
echo "Starting automatic restoration via API..."
echo ""

# Trigger restoration via API so progress is tracked
API_RESPONSE=$(curl -s -X POST http://localhost:8082/api/backups/${LATEST_DATE}/load)
echo "API Response: $API_RESPONSE"

# Check if API accepted the request
if echo "$API_RESPONSE" | grep -q "Started loading"; then
    echo "✅ Restoration started successfully via API"
    echo "Monitoring progress..."
    echo ""

    # Poll for completion
    while true; do
        PROGRESS=$(curl -s http://localhost:8082/api/backups/${LATEST_DATE}/progress)
        STATUS=$(echo "$PROGRESS" | jq -r '.status')
        PERCENT=$(echo "$PROGRESS" | jq -r '.progress')
        MESSAGE=$(echo "$PROGRESS" | jq -r '.message')

        echo "[$(date +%H:%M:%S)] Status: $STATUS ($PERCENT%) - $MESSAGE"

        if [ "$STATUS" = "completed" ]; then
            echo ""
            echo "✅ Auto-restore completed successfully"
            echo "Yesterday environment now running backup from $LATEST_DATE"
            break
        elif [ "$STATUS" = "failed" ]; then
            echo ""
            echo "❌ Auto-restore failed"
            ERROR=$(echo "$PROGRESS" | jq -r '.error')
            echo "Error: $ERROR"
            exit 1
        fi

        sleep 30  # Check every 30 seconds
    done

    # Optional: Send notification (email, Slack, etc.)
    # curl -X POST https://slack.webhook.url -d "{\"text\": \"Yesterday restored backup $LATEST_DATE\"}"
else
    echo "❌ Failed to start restoration via API"
    echo "Response: $API_RESPONSE"
    exit 1
fi
