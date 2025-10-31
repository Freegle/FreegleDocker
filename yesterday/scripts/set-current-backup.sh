#!/bin/bash
# Track which backup is currently loaded
# Usage: ./set-current-backup.sh <YYYYMMDD>

BACKUP_DATE=$1
STATE_FILE="/var/www/FreegleDocker/yesterday/data/current-backup.json"

if [ -z "$BACKUP_DATE" ]; then
    echo "Usage: $0 <YYYYMMDD>"
    exit 1
fi

mkdir -p "$(dirname "$STATE_FILE")"

cat > "$STATE_FILE" << EOF
{
  "date": "${BACKUP_DATE}",
  "loaded_at": "$(date -u +"%Y-%m-%dT%H:%M:%SZ")",
  "loaded_by": "$(whoami)"
}
EOF

echo "✅ Current backup set to ${BACKUP_DATE}"
