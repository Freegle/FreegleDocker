# Yesterday: GCP Backup Restoration Environment

## Concept

Create a Docker Compose-based "Yesterday" environment that:
1. Runs on a GCP VM to avoid data egress charges
2. Receives nightly database dumps pushed from production (zero egress cost)
3. **Phase 1: Start with 2 rolling days** (validates approach, minimal resource usage)
4. **Phase 2: Expand to 7 rolling days** (full historical coverage)
5. Rebuilds Docker environments daily with latest code from repositories
6. Uses real domain names with day indexing:
   - `yesterday.ilovefreegle.org` - Index page listing all versions
   - `fd.yesterday-0.ilovefreegle.org` - Today's backup (most recent)
   - `fd.yesterday-1.ilovefreegle.org` - 1 day ago
   - Eventually: `fd.yesterday-2` through `fd.yesterday-6` (after validation)
   - Same pattern for ModTools: `mt.yesterday-0.ilovefreegle.org`, etc.
7. Isolates all outbound email to Mailhog per instance (prevents external mail sending)
8. Protected by HTTP basic auth on all domains

## Quick Reference

### Domain Names

**Index Page:**
- `yesterday.ilovefreegle.org` - Landing page with links to all 7 days

**Day 0 (Most Recent):**
- `fd.yesterday-0.ilovefreegle.org` - Freegle
- `mt.yesterday-0.ilovefreegle.org` - ModTools
- `mail.yesterday-0.ilovefreegle.org` - Mailhog

**Day 1-6 (Historical):**
- Same pattern: `fd.yesterday-1.ilovefreegle.org` through `fd.yesterday-6.ilovefreegle.org`

**All domains protected by HTTP basic auth**

### Architecture Overview

```
Production Server (GCP)
    |
    | rsync (nightly push)
    | - Database dump
    | - File storage sync
    v
Yesterday VM (GCP)
    |
    +-- Day 0 (latest)
    |   +-- Docker Compose Environment
    |       +-- freegle-prod
    |       +-- modtools-prod
    |       +-- apiv2
    |       +-- db
    |       +-- mailhog
    |
    +-- Day 1 (1 day ago)
    |   +-- [Same services]
    |
    ...
    |
    +-- Day 6 (6 days ago)
        +-- [Same services]

Daily: Rotate (drop day-6, shift all, restore new day-0)
```

## Use Cases

- **Disaster Recovery Testing**: Validate backup integrity and restoration procedures
- **Data Recovery**: Retrieve accidentally deleted data from any of last 7 days
- **Historical Analysis**: Investigate issues that occurred in production
- **Safe Experimentation**: Test data migrations or analyze data without production risk
- **Audit Trail**: Review the state of the system at a specific point in time
- **Development Against Real Data**: Test fixes against production data with latest code
- **Regression Testing**: Verify bugs don't exist in historical data

## Architecture

### GCP Infrastructure

**Dedicated Self-Contained Project:**
- **Project Name**: `freegle-yesterday` (or similar)
- All resources in isolated project for easy cleanup
- Delete entire project to shut down (one command)
- Separate billing, quotas, and permissions from production
- No risk of accidentally affecting production resources

**Run on GCP VM to avoid egress charges:**
- GCP Compute Engine VM in same region as production Cloud SQL/Storage
- Internal network access to backups (no egress fees)
- Public IP with firewall rules for HTTPS only
- Automated daily restoration via cron/Cloud Scheduler

**Easy Shutdown:**
```bash
# To completely remove yesterday environment:
gcloud projects delete freegle-yesterday

# All resources deleted:
# - VM instance
# - Disk storage
# - Firewall rules
# - IP addresses
# - Everything in the project
```

### Services to Restore

1. **Database** (MySQL/MariaDB)
   - Restore from Cloud SQL backup using `gcloud sql backups restore`
   - OR create temporary Cloud SQL instance from backup
   - OR import mysqldump via internal network
   - Mount as Docker container with yesterday's data

2. **File Storage** (Images, uploads, user content)
   - Access from Cloud Storage bucket via internal network
   - Use `gsutil` with internal endpoint to avoid egress
   - Mount into containers as volumes

3. **Application Containers**
   - Pull latest code from GitHub repositories
   - Rebuild Docker containers with latest code
   - Point to restored database and file storage
   - Configure with real domain names

### Domain Configuration

**Real domains (not .localhost):**
- `fd.yesterday.ilovefreegle.org` - Freegle user-facing site
- `mt.yesterday.ilovefreegle.org` - ModTools interface
- `api.yesterday.ilovefreegle.org` - API endpoints (if needed)

**Basic Auth Protection:**
- Traefik middleware for HTTP basic authentication
- Prevents crawlers and casual access
- Credentials stored in environment variables
- Applied to all yesterday domain routes

**DNS Configuration:**
- A/AAAA records pointing to GCP VM public IP
- Wildcard or individual records: `*.yesterday.ilovefreegle.org` or explicit per-day
- SSL/TLS certificates via Let's Encrypt (Traefik ACME)
- Automatic certificate renewal

### Backup Strategy Options

**Same-Region Transfer Costs:**
- **VM to VM** (same region): **FREE** ✅
- **Cloud SQL to Cloud Storage** (same region): **FREE for exports** ✅
- **Cloud Storage to VM** (same region): **FREE** ✅
- **Cloud SQL API operations**: Some costs for backups list/restore operations

Since both production and yesterday are in the same region (europe-west2), we have TWO viable options:

#### Option A: Push-Based Backups (Recommended for Simplicity)

**Production VM → Yesterday VM directly:**

```bash
# On production server - runs at 2 AM daily
mysqldump --single-transaction freegle | gzip > /tmp/freegle-$(date +%Y%m%d).sql.gz
rsync -avz /tmp/freegle-*.sql.gz yesterday-vm:/opt/yesterday-incoming/

# Also sync file storage
rsync -avz /var/www/storage/ yesterday-vm:/opt/yesterday-storage/
```

**Benefits:**
- ✅ Zero egress (VM to VM, same region)
- ✅ Simple: standard rsync, no GCP APIs
- ✅ Fast: direct transfer
- ✅ Controlled: production decides what/when to push
- ✅ No Cloud SQL API costs

**Drawbacks:**
- Requires SSH access between VMs
- Production server must run mysqldump (brief load)

#### Option B: Pull from Cloud SQL Backups (Also Free in Same Region)

**Yesterday VM → Cloud SQL backups/Storage:**

```bash
# On yesterday VM
# Export Cloud SQL backup to Cloud Storage (free in same region)
gcloud sql export sql prod-instance gs://yesterday-backups/backup.sql \
  --database=freegle

# Download from Cloud Storage (free in same region)
gsutil cp gs://yesterday-backups/backup.sql /opt/backups/
```

**Benefits:**
- ✅ Zero egress (same region transfers)
- ✅ Uses official Cloud SQL backups
- ✅ No production server load
- ✅ Automated Cloud SQL backup schedule

**Drawbacks:**
- Requires Cloud SQL permissions across projects
- Slightly more complex (GCP API setup)
- Small API operation costs (minimal)
- Requires service account cross-project setup

### Recommended Approach: Option B (Pull from Existing Backups)

**Since you already have Cloud SQL backups going to Cloud Storage, use those!**

**Why Option B is best in your case:**

1. **Leverage existing backups**: No duplicate backup process
2. **No production load**: Already happening automatically
3. **Reliable**: Uses GCP's managed backup system
4. **Point-in-time recovery**: Can restore from any Cloud SQL backup
5. **No mysqldump overhead**: Production server doesn't need to run extra dumps

**Setup requirements:**
- Cross-project IAM permissions (one-time setup)
- Service account with access to production Cloud SQL/Storage
- Same region means zero egress costs

### 7-Day Rolling History

**Container Architecture:**

```
yesterday-0-db (most recent, today)
yesterday-0-freegle
yesterday-0-modtools
yesterday-0-mailhog

yesterday-1-db (1 day ago)
yesterday-1-freegle
yesterday-1-modtools
yesterday-1-mailhog

...

yesterday-6-db (6 days ago)
yesterday-6-freegle
yesterday-6-modtools
yesterday-6-mailhog
```

**Port Allocation:**
- Day 0: Ports 3000-3099
- Day 1: Ports 3100-3199
- Day 6: Ports 3600-3699

**Daily Rotation Process:**
```bash
# Rotate: Drop day-6, move each day up by 1
docker-compose down yesterday-6-*
mv yesterday-5-data yesterday-6-data
mv yesterday-4-data yesterday-5-data
...
mv yesterday-0-data yesterday-1-data

# Import new backup as day-0
./scripts/restore-day-0.sh
```

### Index Page

**Landing Page: `yesterday.ilovefreegle.org`**

Simple HTML page served via nginx showing:

```html
<!DOCTYPE html>
<html>
<head>
  <title>Freegle Yesterday - Historical Snapshots</title>
  <style>
    body { font-family: sans-serif; max-width: 800px; margin: 50px auto; padding: 0 20px; }
    .day { border: 1px solid #ddd; padding: 20px; margin: 10px 0; border-radius: 4px; }
    .day h2 { margin: 0 0 10px 0; }
    .day.active { background: #e8f5e9; border-color: #4CAF50; }
    .links { margin-top: 10px; }
    .links a, .links button { display: inline-block; margin: 5px 10px 5px 0;
               padding: 10px 15px; background: #4CAF50; color: white;
               text-decoration: none; border-radius: 4px; border: none;
               cursor: pointer; font-size: 14px; }
    .links a:hover, .links button:hover { background: #45a049; }
    .links button.load { background: #2196F3; }
    .links button.load:hover { background: #0b7dda; }
    .warning { background: #fff3cd; border: 2px solid #ffc107;
               padding: 15px; margin: 20px 0; border-radius: 4px; }
    .warning h3 { margin-top: 0; color: #856404; }
    .warning ul { margin: 10px 0; }
    .status { color: #666; font-style: italic; margin-left: 10px; }
    #switching-modal { display: none; position: fixed; top: 0; left: 0;
                       width: 100%; height: 100%; background: rgba(0,0,0,0.5);
                       z-index: 1000; }
    #switching-content { position: absolute; top: 50%; left: 50%;
                         transform: translate(-50%, -50%); background: white;
                         padding: 30px; border-radius: 8px; text-align: center; }
    .spinner { border: 4px solid #f3f3f3; border-top: 4px solid #2196F3;
               border-radius: 50%; width: 40px; height: 40px;
               animation: spin 1s linear infinite; margin: 20px auto; }
    @keyframes spin { 0% { transform: rotate(0deg); }
                     100% { transform: rotate(360deg); } }
  </style>
  <script>
    function switchDay(day) {
      // Show loading modal
      document.getElementById('switching-modal').style.display = 'block';
      document.getElementById('switching-day').textContent = day;

      // Call API to switch day
      fetch('/api/switch-day?day=' + day, { method: 'POST' })
        .then(response => response.json())
        .then(data => {
          // Poll for completion
          checkSwitchStatus(day);
        })
        .catch(error => {
          alert('Error switching day: ' + error);
          document.getElementById('switching-modal').style.display = 'none';
        });
    }

    function checkSwitchStatus(day) {
      fetch('/api/switch-status')
        .then(response => response.json())
        .then(data => {
          if (data.switching) {
            // Still switching, check again in 5 seconds
            setTimeout(() => checkSwitchStatus(day), 5000);
          } else {
            // Done switching, reload page
            window.location.reload();
          }
        });
    }
  </script>
</head>
<body>
  <h1>Freegle Yesterday - Historical Snapshots</h1>
  <p>Protected environment containing production backups with latest code.</p>

  <div class="warning">
    <h3>⚠️ Important Limitations</h3>
    <ul>
      <li><strong>Login:</strong> Only email/password logins work. OAuth providers (Yahoo, Google, Facebook) are disabled because they're configured for production domains.</li>
      <li><strong>Email:</strong> All email is captured in Mailhog - no external mail is sent.</li>
      <li><strong>Data:</strong> Historical production data with current/latest code.</li>
      <li><strong>One Day Active:</strong> Only one day can run at a time. Click "Load" to switch days (takes 2-3 minutes).</li>
    </ul>
  </div>

  <!-- Active Day (currently running) -->
  <div class="day active">
    <h2>Day 0 - Most Recent ({{ date-0 }}) <span style="color: #2e7d32;">● ACTIVE</span></h2>
    <p>This environment is currently running and accessible.</p>
    <div class="links">
      <a href="https://fd.yesterday.ilovefreegle.org" target="_blank">Open Freegle</a>
      <a href="https://mt.yesterday.ilovefreegle.org" target="_blank">Open ModTools</a>
      <a href="https://mail.yesterday.ilovefreegle.org" target="_blank">Open Mailhog</a>
    </div>
  </div>

  <!-- Inactive Days (data on disk, not running) -->
  <div class="day">
    <h2>Day 1 - Yesterday ({{ date-1 }})</h2>
    <p>Data available on disk ({{ size-1 }} GB). Click "Load" to switch to this backup.</p>
    <div class="links">
      <button class="load" onclick="switchDay(1)">Load Day 1</button>
      <span class="status">Takes 2-3 minutes to switch</span>
    </div>
  </div>

  <!-- Repeat for days 2-6 as data becomes available -->
  <!-- Generated dynamically based on what's on disk -->

  <!-- Switching Modal -->
  <div id="switching-modal">
    <div id="switching-content">
      <h2>Switching to Day <span id="switching-day"></span></h2>
      <div class="spinner"></div>
      <p>Stopping current environment...</p>
      <p>Loading backup data...</p>
      <p>Starting containers...</p>
      <p><strong>This takes 2-3 minutes. Please wait...</strong></p>
    </div>
  </div>

  <hr>
  <p><strong>Technical Details:</strong></p>
  <ul>
    <li>Runs on GCP preemptible VM (may have brief downtime during restarts)</li>
    <li>Automated nightly restoration from production backups</li>
    <li>Only one day runs at a time to minimize costs</li>
    <li>All days accessible via web interface (no CLI/SSH needed)</li>
    <li>Protected by HTTP basic authentication</li>
  </ul>
</body>
</html>
```

**Dynamic Date Generation:**
- Simple bash script generates HTML daily with actual dates
- Or use nginx SSI (Server Side Includes) for dynamic dates
- Shows which day is currently active (green highlight)
- "Load" buttons for inactive days

**Day Switching Script:**

```bash
#!/bin/bash
# scripts/switch-day.sh
# Switches to a different day's backup

set -e
DAY=$1

if [ -z "$DAY" ]; then
  echo "Usage: $0 <day-number>"
  exit 1
fi

if [ ! -d "/opt/yesterday-data/day-${DAY}" ]; then
  echo "Day ${DAY} data not found"
  exit 1
fi

echo "Switching to day ${DAY}..."

# Stop current containers
docker-compose down

# Update symlink to point to selected day's data
rm -f /opt/yesterday-data/current
ln -s /opt/yesterday-data/day-${DAY} /opt/yesterday-data/current

# Update docker-compose environment to use day-specific database
export YESTERDAY_ACTIVE_DAY=$DAY

# Start containers with selected day's data
docker-compose up -d

# Wait for services to be healthy
sleep 30

# Update index page to show new active day
./scripts/update-index-active-day.sh $DAY

echo "Switched to day ${DAY}. Services available at fd.yesterday.ilovefreegle.org"
```

**Backend API for Web Interface:**

Simple Flask/Express app to handle web interface requests:

```python
from flask import Flask, request, jsonify
import subprocess
import os
import threading

app = Flask(__name__)
switching_state = {'active': False, 'target_day': None}

def do_switch(day):
    """Background thread to handle day switching"""
    try:
        switching_state['active'] = True
        subprocess.run(['/opt/FreegleDocker/scripts/switch-day.sh', str(day)], check=True)
    finally:
        switching_state['active'] = False
        switching_state['target_day'] = day

@app.route('/api/switch-day', methods=['POST'])
def switch_day():
    """Trigger day switch (called by web interface button)"""
    day = request.args.get('day')
    if not day:
        return jsonify({'error': 'Missing day parameter'}), 400

    if switching_state['active']:
        return jsonify({'error': 'Already switching'}), 409

    # Validate day exists
    if not os.path.exists(f'/opt/yesterday-data/day-{day}'):
        return jsonify({'error': f'Day {day} not found'}), 404

    # Start switching in background thread
    thread = threading.Thread(target=do_switch, args=(day,))
    thread.start()

    return jsonify({'status': 'switching', 'day': day})

@app.route('/api/switch-status')
def switch_status():
    """Check if switching is in progress (polled by web interface)"""
    return jsonify({
        'switching': switching_state['active'],
        'target_day': switching_state['target_day']
    })

@app.route('/api/active-day')
def active_day():
    """Get currently active day"""
    try:
        with open('/opt/yesterday-data/active-day.txt', 'r') as f:
            day = f.read().strip()
        return jsonify({'day': int(day)})
    except:
        return jsonify({'day': 0})

if __name__ == '__main__':
    app.run(host='0.0.0.0', port=8080)
```

**Web Interface Features:**
- Click button → Shows loading modal with spinner
- Backend switches day in background
- Frontend polls `/api/switch-status` every 5 seconds
- When done, page auto-refreshes to show new active day
- No SSH/CLI access needed for users

## Implementation Plan

### Phase 1: GCP Project and VM Setup

1. **Create Dedicated GCP Project**
   ```bash
   # Create new project (self-contained for easy deletion)
   gcloud projects create freegle-yesterday \
     --name="Freegle Yesterday" \
     --organization=YOUR_ORG_ID

   # Link billing account
   gcloud beta billing projects link freegle-yesterday \
     --billing-account=YOUR_BILLING_ACCOUNT_ID

   # Set as active project
   gcloud config set project freegle-yesterday

   # Enable required APIs
   gcloud services enable compute.googleapis.com
   gcloud services enable storage.googleapis.com
   ```

   **Benefits of Dedicated Project:**
   - ✅ Complete isolation from production
   - ✅ Separate billing (easy to track costs)
   - ✅ One-command deletion: `gcloud projects delete freegle-yesterday`
   - ✅ No risk of affecting production resources
   - ✅ Can set project-level quotas and permissions
   - ✅ Easy to pause by stopping VM without affecting production

2. **Provision GCP VM in Yesterday Project**
   ```bash
   # Ensure we're in the yesterday project
   gcloud config set project freegle-yesterday

   # Create VM
   gcloud compute instances create yesterday-freegle \
     --project=freegle-yesterday \
     --zone=europe-west2-a \
     --machine-type=n2-standard-2 \
     --boot-disk-size=200GB \
     --boot-disk-type=pd-standard \
     --preemptible \
     --scopes=cloud-platform \
     --tags=https-server \
     --maintenance-policy=TERMINATE \
     --metadata=startup-script='#!/bin/bash
       # Auto-restart services after preemption
       cd /opt/FreegleDocker
       docker-compose -f docker-compose.yesterday.yml up -d
     '
   ```

   **VM Specifications (Phase 1: 2 days):**
   - **Machine Type**: n2-standard-2 (2 vCPU, 8GB RAM)
   - **Disk**: 200GB standard persistent disk (pd-standard)
   - **Preemptible**: Yes (can be terminated with 30s notice, ~70% cost savings)
   - **Auto-restart**: Startup script restarts containers after preemption

   **Scaling to Phase 2 (7 days):**
   - Resize disk to 500GB: `gcloud compute disks resize yesterday-freegle --size=500GB`
   - No VM size change needed
   - Add 5 more day configurations to docker-compose

   **Preemptible Considerations:**
   - VM may be shut down at any time (typically runs 12-24 hours before preemption)
   - Google gives 30 seconds notice before shutdown
   - Containers auto-restart via startup script
   - Data persists on disk (only VM compute stops)
   - Restoration script runs via cron when VM restarts
   - Acceptable downtime for non-critical historical environment

2. **Install Docker and Dependencies**
   ```bash
   # On VM
   curl -fsSL https://get.docker.com | sh
   sudo usermod -aG docker $USER
   sudo apt-get install -y git docker-compose-plugin
   ```

3. **Configure Cross-Project IAM Permissions**

   **Yesterday VM Service Account needs access to production resources:**

   ```bash
   # Get the yesterday VM's service account email
   YESTERDAY_SA=$(gcloud compute instances describe yesterday-freegle \
     --project=freegle-yesterday \
     --zone=europe-west2-a \
     --format='get(serviceAccount)')

   # In production project, grant permissions
   gcloud config set project freegle-production

   # Grant Cloud SQL backup access
   gcloud projects add-iam-policy-binding freegle-production \
     --member="serviceAccount:${YESTERDAY_SA}" \
     --role="roles/cloudsql.viewer"

   # Grant Cloud Storage read access (for backups bucket)
   gsutil iam ch serviceAccount:${YESTERDAY_SA}:objectViewer \
     gs://your-production-backup-bucket

   # Alternative: Create custom role with minimal permissions
   gcloud iam roles create yesterdayBackupReader \
     --project=freegle-production \
     --title="Yesterday Backup Reader" \
     --description="Read-only access to Cloud SQL backups" \
     --permissions=cloudsql.backups.get,cloudsql.backups.list,cloudsql.instances.get

   gcloud projects add-iam-policy-binding freegle-production \
     --member="serviceAccount:${YESTERDAY_SA}" \
     --role="projects/freegle-production/roles/yesterdayBackupReader"
   ```

   **Permissions needed:**
   - `cloudsql.backups.list` - List available backups
   - `cloudsql.backups.get` - Get backup details
   - `cloudsql.instances.get` - Get instance info
   - `storage.objects.get` - Download backup files from bucket
   - `storage.objects.list` - List backup files

   **Security notes:**
   - Read-only access to production
   - Cannot modify production resources
   - Cannot access Cloud SQL data directly (only backups)
   - Scoped to specific bucket if using custom role

4. **Firewall Configuration**
   ```bash
   # In yesterday project
   gcloud compute firewall-rules create allow-yesterday-https \
     --project=freegle-yesterday \
     --allow tcp:443,tcp:80 \
     --target-tags https-server \
     --description="Allow HTTPS traffic for yesterday domains"

   # Note: No SSH access needed from production since we pull from Cloud Storage
   # SSH only for admin access (use IAP tunnel or bastion host for security)

   # Optional: Allow SSH via Identity-Aware Proxy (more secure than public SSH)
   gcloud compute firewall-rules create allow-ssh-iap \
     --project=freegle-yesterday \
     --allow tcp:22 \
     --source-ranges=35.235.240.0/20 \
     --target-tags https-server \
     --description="Allow SSH via IAP tunnel"
   ```

### Phase 2: Mail Isolation

1. **Add Mailhog to docker-compose.yml**
   ```yaml
   mailhog:
     image: mailhog/mailhog:latest
     container_name: yesterday-mailhog
     networks:
       - default
     labels:
       - "traefik.enable=true"
       - "traefik.http.routers.mailhog.rule=Host(`mail.yesterday.ilovefreegle.org`)"
       - "traefik.http.routers.mailhog.entrypoints=websecure"
       - "traefik.http.routers.mailhog.tls.certresolver=letsencrypt"
       - "traefik.http.services.mailhog.loadbalancer.server.port=8025"
       - "traefik.http.routers.mailhog.middlewares=yesterday-auth"
   ```

2. **Configure Application Mail Settings**
   - Set SMTP host to `mailhog:1025` in all containers
   - Override production mail configuration
   - Environment variables:
     ```bash
     MAIL_HOST=mailhog
     MAIL_PORT=1025
     MAIL_ENCRYPTION=none
     MAIL_FROM_ADDRESS=noreply@yesterday.ilovefreegle.org
     ```

3. **Verify No External Mail**
   - Block outbound SMTP ports (25, 465, 587) at firewall level
   - Monitor Mailhog UI to confirm all mail is captured
   - Test registration/notification emails

### Phase 3: Automated Restoration Script

1. **Master Restoration Script**
   ```bash
   #!/bin/bash
   # scripts/restore-yesterday.sh
   # Runs daily via cron to restore latest backup

   set -e  # Exit on error
   LOG_FILE="/var/log/yesterday-restore.log"
   exec > >(tee -a "$LOG_FILE") 2>&1

   echo "=== Yesterday Restoration Started: $(date) ==="

   # 1. Stop existing containers
   echo "Stopping existing containers..."
   cd /opt/FreegleDocker
   docker-compose down

   # 2. Pull latest code from GitHub
   echo "Pulling latest code..."
   git fetch --all --recurse-submodules
   git reset --hard origin/master
   git submodule update --init --recursive --remote

   # 3. Find latest backup in Cloud Storage
   echo "Finding latest Cloud SQL backup in Cloud Storage..."
   # Assuming production already exports backups to this bucket
   BACKUP_BUCKET="gs://freegle-production-backups"
   LATEST_BACKUP=$(gsutil ls -l $BACKUP_BUCKET/sqldump-*.sql.gz | \
     sort -k 2 | tail -1 | awk '{print $3}')

   echo "Latest backup: $LATEST_BACKUP"

   # 4. Download backup from Cloud Storage (zero egress, same region)
   echo "Downloading database backup..."
   BACKUP_FILE="/opt/backups/yesterday-$(date +%Y%m%d).sql.gz"
   gsutil cp $LATEST_BACKUP $BACKUP_FILE

   # Uncompress if needed
   gunzip -c $BACKUP_FILE > /opt/backups/yesterday-$(date +%Y%m%d).sql

   # 5. Copy file storage from Cloud Storage (zero egress, same region)
   echo "Syncing file storage from Cloud Storage..."
   # Assuming production syncs storage to this bucket
   gsutil -m rsync -r \
     gs://freegle-production-storage/ \
     /opt/yesterday-data/storage/

   # Note: Both gsutil operations are FREE because same region (europe-west2)

   # 6. Update docker-compose.yml for yesterday environment
   echo "Configuring docker-compose for yesterday..."
   cp docker-compose.yml docker-compose.yesterday.yml

   # Update environment variables
   sed -i 's/ilovefreegle.org/yesterday.ilovefreegle.org/g' docker-compose.yesterday.yml
   sed -i 's/MAIL_HOST=.*/MAIL_HOST=mailhog/g' docker-compose.yesterday.yml
   sed -i 's/MAIL_PORT=.*/MAIL_PORT=1025/g' docker-compose.yesterday.yml

   # 7. Rebuild containers with latest code
   echo "Building containers..."
   docker-compose -f docker-compose.yesterday.yml build --pull

   # 8. Start database and import backup
   echo "Starting database container..."
   docker-compose -f docker-compose.yesterday.yml up -d db
   sleep 30

   echo "Importing database backup..."
   docker exec yesterday-db mysql -u root -p$DB_ROOT_PASSWORD freegle < /opt/backups/$BACKUP_FILE

   # 9. Start all services
   echo "Starting all services..."
   docker-compose -f docker-compose.yesterday.yml up -d

   # 10. Wait for services to be healthy
   echo "Waiting for services to become healthy..."
   sleep 60

   # 11. Run health checks
   echo "Running health checks..."
   ./scripts/verify-yesterday-health.sh

   echo "=== Yesterday Restoration Completed: $(date) ==="
   ```

2. **Schedule Daily Restoration**
   ```bash
   # Add to crontab
   0 2 * * * /opt/FreegleDocker/scripts/restore-yesterday.sh

   # OR use Cloud Scheduler for better reliability
   gcloud scheduler jobs create http yesterday-restore \
     --schedule="0 2 * * *" \
     --uri="https://yesterday-vm/api/restore" \
     --http-method=POST
   ```

### Phase 4: Traefik Configuration with Basic Auth

1. **Create Basic Auth Middleware**
   ```yaml
   # In docker-compose.yesterday.yml - Traefik service labels
   traefik:
     image: traefik:v2.10
     labels:
       # Basic auth middleware
       - "traefik.http.middlewares.yesterday-auth.basicauth.users=${YESTERDAY_AUTH_USERS}"
       # Generates: user:$$apr1$$... (htpasswd format)
   ```

2. **Generate Basic Auth Credentials**
   ```bash
   # Generate htpasswd entry
   htpasswd -nb admin "securepassword" | sed -e s/\\$/\\$\\$/g
   # Output: admin:$$apr1$$xxxxxxxx...

   # Add to .env file
   echo 'YESTERDAY_AUTH_USERS=admin:$$apr1$$xxxxxxxx...' >> .env
   ```

3. **Apply Basic Auth to Services**
   ```yaml
   # Freegle service
   freegle:
     labels:
       - "traefik.enable=true"
       - "traefik.http.routers.fd-yesterday.rule=Host(`fd.yesterday.ilovefreegle.org`)"
       - "traefik.http.routers.fd-yesterday.entrypoints=websecure"
       - "traefik.http.routers.fd-yesterday.tls.certresolver=letsencrypt"
       - "traefik.http.routers.fd-yesterday.middlewares=yesterday-auth"
       - "traefik.http.services.fd-yesterday.loadbalancer.server.port=3000"

   # ModTools service
   modtools:
     labels:
       - "traefik.enable=true"
       - "traefik.http.routers.mt-yesterday.rule=Host(`mt.yesterday.ilovefreegle.org`)"
       - "traefik.http.routers.mt-yesterday.entrypoints=websecure"
       - "traefik.http.routers.mt-yesterday.tls.certresolver=letsencrypt"
       - "traefik.http.routers.mt-yesterday.middlewares=yesterday-auth"
       - "traefik.http.services.mt-yesterday.loadbalancer.server.port=3000"

   # API service (if exposed)
   apiv2:
     labels:
       - "traefik.enable=true"
       - "traefik.http.routers.api-yesterday.rule=Host(`api.yesterday.ilovefreegle.org`)"
       - "traefik.http.routers.api-yesterday.entrypoints=websecure"
       - "traefik.http.routers.api-yesterday.tls.certresolver=letsencrypt"
       - "traefik.http.routers.api-yesterday.middlewares=yesterday-auth"
   ```

4. **Mail Configuration in Each Service**
   ```yaml
   # Add to all service environment variables
   environment:
     - MAIL_HOST=mailhog
     - MAIL_PORT=1025
     - MAIL_ENCRYPTION=none
     - MAIL_FROM_ADDRESS=noreply@yesterday.ilovefreegle.org
     # Override any production SMTP settings
     - MAIL_USERNAME=
     - MAIL_PASSWORD=
   ```

### Phase 5: Health Checks and Monitoring

1. **Health Check Script**
   ```bash
   #!/bin/bash
   # scripts/verify-yesterday-health.sh

   set -e
   FAILED=0

   # Check database connectivity
   echo "Checking database..."
   docker exec yesterday-db mysqladmin ping -h localhost || FAILED=1

   # Check Freegle responds
   echo "Checking Freegle site..."
   STATUS=$(curl -s -o /dev/null -w "%{http_code}" \
     -u admin:$YESTERDAY_PASSWORD \
     https://fd.yesterday.ilovefreegle.org/)
   if [ "$STATUS" != "200" ]; then
     echo "Freegle site returned $STATUS"
     FAILED=1
   fi

   # Check ModTools responds
   echo "Checking ModTools site..."
   STATUS=$(curl -s -o /dev/null -w "%{http_code}" \
     -u admin:$YESTERDAY_PASSWORD \
     https://mt.yesterday.ilovefreegle.org/)
   if [ "$STATUS" != "200" ]; then
     echo "ModTools site returned $STATUS"
     FAILED=1
   fi

   # Check Mailhog
   echo "Checking Mailhog..."
   STATUS=$(curl -s -o /dev/null -w "%{http_code}" \
     -u admin:$YESTERDAY_PASSWORD \
     https://mail.yesterday.ilovefreegle.org/)
   if [ "$STATUS" != "200" ]; then
     echo "Mailhog returned $STATUS"
     FAILED=1
   fi

   # Verify no external mail can be sent
   echo "Verifying outbound SMTP is blocked..."
   if docker exec freegle nc -zv -w 2 gmail-smtp-in.l.google.com 25 2>&1 | grep -q "succeeded"; then
     echo "WARNING: Outbound SMTP not blocked!"
     FAILED=1
   fi

   if [ $FAILED -eq 0 ]; then
     echo "All health checks passed!"
     exit 0
   else
     echo "Health checks failed!"
     exit 1
   fi
   ```

2. **Monitoring and Alerts**
   ```bash
   # Add to crontab for hourly health checks
   0 * * * * /opt/FreegleDocker/scripts/verify-yesterday-health.sh || \
     echo "Yesterday health check failed" | mail -s "Alert: Yesterday Down" admin@ilovefreegle.org

   # OR use Cloud Monitoring
   gcloud monitoring uptime-checks create https yesterday-uptime \
     --resource-type=uptime-url \
     --host=fd.yesterday.ilovefreegle.org \
     --path=/ \
     --period=300
   ```

## Technical Considerations

### Data Size and Storage

- **Database Size**: Estimate disk space required (likely 10-100GB for production)
- **File Storage**: Potentially TB-scale for images/uploads
- **VM Disk**: Provision sufficient SSD storage (500GB-1TB recommended)
- **No Compression Needed**: Internal GCP network is fast enough

### Network and Performance

- **Zero Egress Charges**: All data transfer within GCP network
- **Fast Transfer**: Internal network provides high bandwidth (10+ Gbps)
- **Restoration Time**: Expected 30-90 minutes depending on data size
- **Container Resources**: n2-standard-4 provides 4 vCPUs, 16GB RAM

### Security Considerations

- **Production Data**: Contains real user data - handle with care
- **Basic Auth**: Protects against crawlers and casual access
- **Firewall**: Restrict to HTTPS only (ports 80/443)
- **Credentials**: Separate from production, stored in environment variables
- **Mail Isolation**: Mailhog captures all email, SMTP ports blocked
- **Access Logging**: Monitor access to yesterday environment
- **Data Retention**: Backup deleted after 7 days, VM disk wiped on rebuild
- **GDPR Compliance**: Same as production - yesterday data is production data

### Login Limitations

**OAuth Providers Disabled:**
- Yahoo, Google, Facebook, and other OAuth logins will NOT work
- OAuth providers are configured for production domains only
- Callback URLs point to production, not `*.yesterday.ilovefreegle.org`
- Attempting OAuth login will redirect to production or fail

**Email/Password Login Only:**
- Users must use email/password authentication
- This is the only login method that works in yesterday environment
- Historical data includes password hashes, so existing logins work
- Document this clearly on the index page warning

**Workaround for Testing:**
- If OAuth testing is needed, consider:
  - Creating test OAuth app registrations for yesterday domains
  - Or use email/password logins for all testing
  - Or mock OAuth in development mode

**Why This Limitation Exists:**
- OAuth providers require registered callback URLs
- Production OAuth apps point to `ilovefreegle.org` domains
- Creating new OAuth apps for temporary domains is complex
- Email/password is simpler and sufficient for data recovery use case

### GCP Authentication

- **VM Service Account**: Automatic authentication within GCP
- **Minimal Permissions Required**:
  - `cloudsql.backups.get` and `cloudsql.backups.list`
  - `storage.objects.get` and `storage.objects.list` for Cloud Storage
  - No write permissions needed (read-only access to backups)

### Backup Retention

- **Cloud SQL**: Typically 7-30 day retention (check production settings)
- **Cloud Storage**: Depends on lifecycle policies
- **Yesterday VM**: Keeps latest restoration only (overwrites daily)
- **Can restore**: Any backup within retention window

### Avoiding Egress Charges

**Key Strategy: Everything stays within GCP**

1. **VM in Same Region**: VM must be in same region as Cloud SQL/Storage
2. **Internal Network**: Use internal IP addresses and service names
3. **No Public Downloads**: Never download backups to local machine
4. **gsutil with Internal**: Configure gsutil to use internal endpoints

```bash
# Configure gsutil for internal access
gcloud config set api_endpoint_overrides/storage https://storage.googleapis.com/

# Verify no egress (check bill after first restoration)
gcloud billing accounts list
```

**Expected Egress**: $0 (all internal) + minimal HTTPS traffic for users accessing sites

### Cross-Project Communication

**Yesterday → Production Backups (Pull Model):**

Since production and yesterday are in different GCP projects, access is via IAM:

1. **Service Account IAM Permissions**
   - Yesterday VM's service account granted read access to production bucket
   - No network connectivity needed (uses GCP APIs)
   - See "Configure Cross-Project IAM Permissions" section above

2. **How It Works**
   ```bash
   # On yesterday VM, service account automatically authenticated
   gsutil ls gs://freegle-production-backups/  # Works via IAM
   gsutil cp gs://freegle-production-backups/backup.sql /opt/backups/
   ```

3. **No Egress Charges Because:**
   - Transfer is within same GCP region (europe-west2)
   - Even across projects, same-region traffic is free
   - Cloud Storage → VM in same region = $0

4. **Security Benefits vs Push Model:**
   - ✅ No SSH access needed
   - ✅ No open SSH ports
   - ✅ Read-only access (cannot modify production)
   - ✅ Audited via Cloud Audit Logs
   - ✅ Can revoke access instantly by removing IAM binding

## Alternative Approaches

### Option 1: Direct GCP Cloud SQL Clone
- Use Cloud SQL clone feature
- Faster than full backup/restore
- Requires GCP infrastructure access
- May have additional costs

### Option 2: Point-in-Time Recovery
- Restore to specific timestamp, not just nightly
- More complex but more flexible
- Requires transaction log backups

### Option 3: Staging Environment with Backup Sync
- Maintain dedicated staging that syncs nightly
- Always ready, no restoration delay
- Higher ongoing resource costs

## Next Steps

1. **Research Phase**
   - Audit current GCP backup configuration
   - Measure backup sizes and download times
   - Identify any gaps in current backup coverage

2. **Prototype**
   - Manual restoration of one backup
   - Document the process and timing
   - Identify pain points and automation opportunities

3. **Script Development**
   - Create download and restoration scripts
   - Implement health checks and validation
   - Add error handling and logging

4. **Testing**
   - Test full restoration process
   - Verify application functionality
   - Measure resource requirements

5. **Documentation**
   - Create runbook for restoration process
   - Document troubleshooting steps
   - Train team on usage

## Success Criteria

- [ ] Can download complete backup from GCP in reasonable time
- [ ] Can restore database to functional state
- [ ] Can restore file storage and link to application
- [ ] Application services start and respond correctly
- [ ] Can access yesterday's data through web interface
- [ ] Process is documented and repeatable
- [ ] Security and access controls are appropriate

## Questions to Answer

1. What is the current GCP backup schedule and retention policy?
2. What is the total size of database and file storage backups?
3. How long does it take to download a complete backup?
4. Are backups encrypted? What are the decryption requirements?
5. Do we have appropriate GCP permissions for backup access?
6. What is the acceptable restoration time objective?
7. Should the yesterday environment be read-only or allow modifications?
8. How will we prevent accidental interaction with production services?

## Risks and Mitigations

| Risk | Impact | Mitigation |
|------|--------|-----------|
| Large backup size makes download impractical | High | Use incremental backups, compression, faster network connection |
| Backup restoration fails | High | Regular testing, multiple backup sources, documentation |
| Restored system connects to production APIs | Critical | Network isolation, separate credentials, configuration validation |
| Insufficient local storage | Medium | Cloud-based restoration environment, storage cleanup policies |
| Sensitive data exposure | Critical | Access controls, encryption, audit logging, time-limited access |
| Resource contention with dev/prod | Low | Resource limits, separate hardware, scheduled restoration windows |

## Estimated Timeline

### Phase 1 Implementation (2 Days)

- **Step 1 - VM Setup**: 1 day
- **Step 2 - Mail Isolation**: 1 day
- **Step 3 - Basic Restoration (2 days)**: 2-3 days
- **Step 4 - Traefik & Domains**: 2 days
- **Step 5 - Index Page (2 days)**: 1 day
- **Step 6 - Testing & Docs**: 1-2 days

**Phase 1 Total: 8-10 days** for 2-day historical environment

### Phase 2 Expansion (7 Days)

After validating Phase 1 in production for 1-2 weeks:

- **Disk Resize**: 1 hour (online operation)
- **Extend docker-compose**: 1 day (add 5 more day configs)
- **Update rotation script**: 1 day (handle 7 days instead of 2)
- **Update index page**: 1 hour (show 7 days)
- **Testing**: 2 days (validate all 7 environments)

**Phase 2 Total: 4-5 days** to expand to 7 days

**Grand Total**: 2 weeks for Phase 1, then 1 week for Phase 2 expansion

## Cost Analysis (Monthly)

### Phase 1: 2-Day Configuration (Starting Point)

**VM Specifications:**
- Machine Type: n2-standard-2 (2 vCPU, 8GB RAM)
- Disk: 200GB standard persistent disk
- Preemptible: Yes
- Region: europe-west2

#### Cost Breakdown

**Compute: ~$18/month**
- Preemptible n2-standard-2: ~$0.025/hour × 730 hours = **$18.25/month**
- Regular n2-standard-2 would be: ~$60/month (we save 70%)

**Storage: ~$8/month**
- 200GB standard disk (pd-standard): $0.04/GB × 200GB = **$8/month**
- Sufficient for 2 days of data + Docker images

**Network: ~$0.12/month**
- Egress: ~$0 (all within GCP)
- User HTTPS traffic: <1GB/month = **$0.12**

**Phase 1 Total: ~$26/month** for 2-day historical environment

#### Architecture Options

**Option A: All Days Running Simultaneously (Original Plan)**
- 2 complete Docker environments running 24/7
- Access any day instantly via its domain
- Higher resource usage (2× containers)

**Option B: On-Demand Day Selection (Cost Optimized)**
- Keep 2 days of backup data on disk
- Only ONE day running at a time
- Switch days via web interface or CLI
- **Saves compute/memory** (only 1× containers)

**Cost comparison:**
- Option A: ~$26/month (all days running)
- Option B: ~$26/month (same - VM already sized for multiple containers)

**Note:** With only 2 days and a small VM, both options cost the same. The difference becomes significant with 7 days (see Phase 2).

### Phase 2: 7-Day Configuration (After Validation)

**VM Specifications:**
- Disk: 500GB standard persistent disk (resize operation)
- Preemptible: Yes (same)
- Region: europe-west2 (same)
- **Machine Type**: Depends on architecture choice

#### Architecture Options for 7 Days

**Option A: All 7 Days Running Simultaneously**

Need larger VM to run 7 complete environments:

- **Machine Type**: n2-standard-4 (4 vCPU, 16GB RAM) - UPGRADE REQUIRED
- **Compute**: ~$36/month (preemptible n2-standard-4)
- **Storage**: ~$20/month (500GB standard disk)
- **Network**: ~$0.12/month
- **Total: ~$56/month**

**Benefits:**
- Instant access to any day via URL
- All days available 24/7
- No waiting for container startup

**Drawbacks:**
- Higher compute costs (larger VM needed)
- More resource intensive (28+ containers running)

**Option B: On-Demand Day Selection (Recommended)**

Keep same small VM, only run one day at a time:

- **Machine Type**: n2-standard-2 (2 vCPU, 8GB RAM) - NO CHANGE
- **Compute**: ~$18/month (same as Phase 1)
- **Storage**: ~$20/month (500GB for 7 days of data)
- **Network**: ~$0.12/month
- **Total: ~$40/month**

**Benefits:**
- ✅ 30% cheaper than running all days
- ✅ Same VM size as Phase 1
- ✅ All 7 days of data available
- ✅ Switch days in ~2-3 minutes

**Drawbacks:**
- Can only view one day at a time
- Need to stop/start containers to switch days
- Brief wait when switching

**How On-Demand Works:**

```bash
# Index page shows all 7 days available
# Click "Day 3" → triggers:
docker-compose down                     # Stop current day
./scripts/load-day.sh 3                # Load day-3 data
docker-compose up -d                    # Start containers

# 2-3 minutes later, day-3 is accessible
```

**Switching interface:**
- **Web UI only** - Click "Load Day X" button on index page
- Backend API handles the switching automatically
- Progress indicator shows switching status
- Refreshes to show active day when complete

### Cost Comparison

| Configuration | VM Size | Days Running | Monthly Cost | Notes |
|--------------|---------|--------------|--------------|-------|
| **Phase 1 - Both days** | n2-standard-2 | 2 simultaneous | **$26** | Small scale |
| **Phase 2A - All 7 running** | n2-standard-4 | 7 simultaneous | **$56** | Instant access |
| **Phase 2B - On-demand** | n2-standard-2 | 1 at a time | **$40** | Switch as needed |

**Recommendation: Phase 2B (On-Demand)**
- Saves $16/month vs running all days
- Same small VM (no upgrade needed)
- All 7 days of data still available
- 2-3 minute switch time is acceptable for this use case

### Trade-offs of Optimized Configuration

| Aspect | Trade-off | Mitigation |
|--------|-----------|-----------|
| **Preemptible VM** | Can be shut down with 30s notice | Startup script auto-restarts containers; acceptable downtime for non-critical use |
| **Smaller VM (2 vCPU)** | Slower container builds/restarts | Run production builds (faster startup); rotate one day at a time |
| **Standard disk** | Slower I/O vs SSD | Historical data access is infrequent; acceptable performance |
| **Potential preemption** | May occur during restoration | Run restoration at 2 AM when preemption less likely; retry logic |

### Preemption Management

**Typical preemption patterns:**
- Preemptible VMs typically run 12-24 hours before termination
- Google provides 30-second shutdown notice
- VM automatically restarts (GCP manages this)
- Startup script brings containers back up (~5 minutes)
- Persistent disk data is never lost

**Handling preemption during restoration:**
```bash
# In restoration script, add lock file and resume capability
if [ -f /var/lock/restoration.lock ]; then
  echo "Previous restoration interrupted, resuming..."
  # Resume from last checkpoint
fi
```

**Monitoring preemption:**
```bash
# Check preemption status via metadata
curl -H "Metadata-Flavor: Google" \
  http://metadata.google.internal/computeMetadata/v1/instance/preempted
```

### Cost Comparison

| Approach | Monthly Cost | Notes |
|----------|-------------|-------|
| **Our optimized approach** | **$40** | Preemptible VM, standard disk, push-based |
| Non-preemptible version | $80 | Same but no preemption risk |
| With SSD instead | $100 | Faster I/O |
| Non-preemptible + SSD | $140 | Maximum performance |
| Cloud SQL clones (7 instances) | $700-2,100 | Most expensive option |
| Pull from Cloud SQL backups | $240+ | Egress charges per pull |

### Cost Savings Summary

**Annual savings with optimized configuration:**
- vs Cloud SQL clones: **$660-2,060/month = $7,920-24,720/year**
- vs pulling backups: **$200/month = $2,400/year**
- vs non-preemptible VM: **$40/month = $480/year**

**The key innovations:**
1. ✅ Push-based backups = **zero egress costs**
2. ✅ Preemptible VM = **70% compute savings**
3. ✅ Standard disk = **75% storage savings**
4. ✅ Single VM for all 7 days = **shared infrastructure**

**Result: $40/month for 7 complete historical environments**

## Conclusion

This approach is **highly feasible and cost-effective** with the push-based backup strategy:

### Key Advantages

✅ **Self-Contained Project**: Entire environment in dedicated GCP project - delete project to shut down completely
✅ **Zero Egress Costs**: Production pushes backups, no Cloud SQL/Storage pulling
✅ **Phased Rollout**: Start with 2 days ($26/month), expand to 7 days ($40/month)
✅ **Latest Code**: Each environment rebuilt daily with current codebase
✅ **Mail Isolation**: Mailhog per environment, no external email risk
✅ **Basic Auth**: Protected against crawlers and casual access
✅ **Real Domains**: Professional `*.yesterday.ilovefreegle.org` URLs
✅ **Index Page**: Easy navigation to available snapshots
✅ **Docker Compose**: Leverages existing orchestration and definitions
✅ **Preemptible VM**: 70% compute savings with auto-restart capability
✅ **Standard Disk**: 75% storage savings, acceptable for historical data
✅ **Low Risk Start**: Validate with 2 days before committing to 7
✅ **Complete Isolation**: No risk of affecting production infrastructure
✅ **Web Interface Only**: No CLI/SSH needed - switch days via browser with loading indicator
✅ **On-Demand Switching**: 30% cost savings by running one day at a time

### Key Requirements

1. **GCP VM** in same region as production (europe-west2)
2. **SSH key auth** from production server to yesterday VM
3. **Firewall rules** for SSH (production only) and HTTPS (public)
4. **DNS configuration** for wildcard or individual day domains
5. **Cron jobs** on both production (push) and yesterday (process)
6. **Sufficient disk** for 7 days of data (500GB-1TB recommended)

### Success Criteria

- [ ] Can receive database dumps from production via rsync
- [ ] Can maintain 7 independent Docker Compose environments
- [ ] Can rotate days automatically (drop day-6, shift all, add day-0)
- [ ] All 7 environments accessible via their respective domains
- [ ] Basic auth protects all domains including index page
- [ ] Mail captured in Mailhog, outbound SMTP blocked
- [ ] Index page dynamically shows all available snapshots
- [ ] SSL certificates auto-renewed via Let's Encrypt
- [ ] Health checks verify all environments daily
- [ ] Process is fully automated and documented

### Next Steps

1. **Provision GCP VM** and configure firewall
2. **Setup SSH keys** between production and yesterday VM
3. **Create production push script** for nightly database dumps
4. **Implement basic single-day restoration** to validate approach
5. **Expand to 7-day rolling** with rotation logic
6. **Create index page** with links to all environments
7. **Configure Traefik** with basic auth and SSL
8. **Test mail isolation** and verify no external SMTP
9. **Document runbook** for operations team
10. **Monitor costs** to confirm zero egress charges

### Risks and Mitigations

| Risk | Mitigation |
|------|-----------|
| VM disk fills up | Monitor disk usage, adjust retention or storage size |
| Rotation fails | Comprehensive error handling, alerts, manual recovery procedure |
| Production push fails | Retry logic, alerts, fallback to Cloud SQL backups |
| SSL cert renewal fails | Let's Encrypt auto-renewal, monitoring, backup certificates |
| Resource contention (7 environments) | Use production containers (built once), adjust VM size |

**Recommendation**: Start with Phase 1-3 (single-day restoration) to validate the approach, then expand to 7-day rolling history once proven.

## Shutdown and Cleanup

### Complete Removal (Delete Everything)

To completely remove the yesterday environment:

```bash
# One command to delete everything
gcloud projects delete freegle-yesterday
```

This deletes:
- ✅ VM instance
- ✅ All disk storage
- ✅ All firewall rules
- ✅ All IP addresses
- ✅ All logs and monitoring data
- ✅ Literally everything in the project

**Recovery period:** 30 days to undelete before permanent deletion

### Temporary Shutdown (Keep Data, Stop Costs)

To temporarily pause (stops compute costs, keeps storage):

```bash
# Stop the VM (keeps disk, stops $18/month compute)
gcloud compute instances stop yesterday-freegle \
  --project=freegle-yesterday \
  --zone=europe-west2-a

# Billing after stop: $8-20/month (storage only)

# Restart when needed
gcloud compute instances start yesterday-freegle \
  --project=freegle-yesterday \
  --zone=europe-west2-a
```

### Partial Cleanup (Keep Some Days)

To reduce costs by keeping fewer days:

```bash
# Remove older day containers and data
docker-compose down yesterday-{2..6}-*
rm -rf /opt/yesterday-{2..6}-data

# Shrink disk if desired (can't shrink below data size)
# Must create snapshot, smaller disk from snapshot
```

### Cost of Keeping Project Empty

If you delete the VM but keep the project:
- Empty project: $0/month
- Just useful to maintain the project structure
- Can recreate VM later with same configuration

### Why Dedicated Project Is Important

**Without dedicated project:**
- Must manually delete: VM, disk, firewall rules, IPs, etc.
- Risk of missing resources that continue billing
- Hard to track what belongs to yesterday vs production

**With dedicated project:**
- One command deletes everything: `gcloud projects delete freegle-yesterday`
- Separate billing makes cost tracking trivial
- Zero risk of affecting production infrastructure
- Clean slate if you want to restart later
