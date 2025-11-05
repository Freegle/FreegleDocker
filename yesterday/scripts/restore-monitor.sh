#!/bin/bash
# Monitor for restore trigger file and execute restores
# This runs on the host, not in a container

TRIGGER_DIR="/var/www/FreegleDocker/yesterday/data"
TRIGGER_FILE="$TRIGGER_DIR/restore-trigger"
RESTORE_SCRIPT="/var/www/FreegleDocker/yesterday/scripts/restore-backup.sh"

echo "Restore monitor started, watching for $TRIGGER_FILE"

while true; do
    if [ -f "$TRIGGER_FILE" ]; then
        # Read the backup date from the trigger file
        BACKUP_DATE=$(cat "$TRIGGER_FILE")

        echo "=== Restore triggered at $(date) ==="
        echo "Backup date: $BACKUP_DATE"

        # Remove trigger file immediately to prevent re-triggering
        rm -f "$TRIGGER_FILE"

        # Validate backup date format
        if [[ "$BACKUP_DATE" =~ ^[0-9]{8}$ ]]; then
            echo "Executing restore for backup $BACKUP_DATE..."
            "$RESTORE_SCRIPT" "$BACKUP_DATE"
            EXIT_CODE=$?

            if [ $EXIT_CODE -eq 0 ]; then
                echo "✅ Restore completed successfully"
            else
                echo "❌ Restore failed with exit code $EXIT_CODE"
            fi
        else
            echo "❌ Invalid backup date format: $BACKUP_DATE"
        fi

        echo "=== Restore monitor ready ==="
    fi

    # Check every 2 seconds
    sleep 2
done
