#!/bin/bash
# Setup cron job to automatically restore latest backup
# Run this once to install the cron job

CRON_SCHEDULE="0 8 * * *"  # Run at 8 AM UTC daily (3 hours after 4-5 AM backup time)
SCRIPT_PATH="/var/www/FreegleDocker/yesterday/scripts/auto-restore-latest.sh"

echo "Setting up cron job for automatic backup restoration"
echo "Schedule: $CRON_SCHEDULE (8 AM UTC daily)"
echo "Script: $SCRIPT_PATH"
echo ""

# Check if cron job already exists
if crontab -l 2>/dev/null | grep -q "auto-restore-latest.sh"; then
    echo "⚠️  Cron job already exists. Updating..."
    # Remove old entry
    crontab -l 2>/dev/null | grep -v "auto-restore-latest.sh" | crontab -
fi

# Add new cron job
(crontab -l 2>/dev/null; echo "$CRON_SCHEDULE $SCRIPT_PATH >> /var/log/yesterday-cron.log 2>&1") | crontab -

echo "✅ Cron job installed successfully"
echo ""
echo "The Yesterday environment will automatically restore the latest backup daily at 8 AM UTC"
echo "This gives 3+ hours for the nightly backup (4-5 AM) to complete and stabilize"
echo ""
echo "View cron jobs:"
echo "  crontab -l"
echo ""
echo "View logs:"
echo "  tail -f /var/log/yesterday-cron.log"
echo "  tail -f /var/log/yesterday-auto-restore.log"
echo ""
echo "To manually trigger auto-restore:"
echo "  $SCRIPT_PATH"
