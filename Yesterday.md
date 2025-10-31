# Yesterday: GCP Backup Restoration Environment

## Concept

Create a Docker Compose-based "Yesterday" environment that:
1. Runs on a GCP VM to avoid data egress charges
2. Receives nightly database dumps pushed from production (zero egress cost)
3. Maintains 7 rolling days of complete system snapshots
4. Rebuilds Docker environments daily with latest code from repositories
5. Uses real domain names with day indexing:
   - `yesterday.ilovefreegle.org` - Index page listing all 7 versions
   - `fd.yesterday-0.ilovefreegle.org` - Today's backup (most recent)
   - `fd.yesterday-1.ilovefreegle.org` - 1 day ago
   - `fd.yesterday-6.ilovefreegle.org` - 6 days ago
   - Same pattern for ModTools: `mt.yesterday-0.ilovefreegle.org`, etc.
6. Isolates all outbound email to Mailhog per instance (prevents external mail sending)
7. Protected by HTTP basic auth on all domains

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

**Run on GCP VM to avoid egress charges:**
- GCP Compute Engine VM in same region as Cloud SQL/Storage
- Internal network access to backups (no egress fees)
- Public IP with firewall rules for HTTPS only
- Automated daily restoration via cron/Cloud Scheduler

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

### Push-Based Backup Strategy

**Production Push > Yesterday Pull (Zero Egress Cost)**

Instead of pulling from Cloud SQL backups, production server pushes dumps:

1. **Nightly Cron on Production Server**
   ```bash
   # Runs at 2 AM daily on production
   mysqldump --single-transaction freegle | gzip > /tmp/freegle-$(date +%Y%m%d).sql.gz
   rsync -avz /tmp/freegle-*.sql.gz yesterday-vm:/opt/yesterday-incoming/
   ```

2. **File Storage Sync**
   ```bash
   # Also push critical file storage changes
   rsync -avz /var/www/storage/ yesterday-vm:/opt/yesterday-storage/
   ```

3. **Benefits**
   - **Zero Cloud SQL Egress**: No backup retrieval costs
   - **Controlled**: Exactly what production wants to share
   - **Fast**: Direct server-to-server transfer within GCP
   - **Simple**: Standard rsync, no Cloud SQL API complexity

4. **Security**
   - SSH key-based authentication between production and yesterday VM
   - Firewall allows SSH only from production server IP
   - Automated, no manual intervention

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
    body { font-family: sans-serif; max-width: 800px; margin: 50px auto; }
    .day { border: 1px solid #ddd; padding: 20px; margin: 10px 0; }
    .day h2 { margin: 0 0 10px 0; }
    .links a { display: inline-block; margin: 5px 10px 5px 0;
               padding: 10px 15px; background: #4CAF50; color: white;
               text-decoration: none; border-radius: 4px; }
    .links a:hover { background: #45a049; }
  </style>
</head>
<body>
  <h1>Freegle Yesterday - Historical Snapshots</h1>
  <p>Protected environment containing 7 days of production backups with latest code.</p>

  <div class="day">
    <h2>Day 0 - Most Recent ({{ date-0 }})</h2>
    <div class="links">
      <a href="https://fd.yesterday-0.ilovefreegle.org">Freegle</a>
      <a href="https://mt.yesterday-0.ilovefreegle.org">ModTools</a>
      <a href="https://mail.yesterday-0.ilovefreegle.org">Mailhog</a>
    </div>
  </div>

  <div class="day">
    <h2>Day 1 - Yesterday ({{ date-1 }})</h2>
    <div class="links">
      <a href="https://fd.yesterday-1.ilovefreegle.org">Freegle</a>
      <a href="https://mt.yesterday-1.ilovefreegle.org">ModTools</a>
      <a href="https://mail.yesterday-1.ilovefreegle.org">Mailhog</a>
    </div>
  </div>

  <!-- Repeat for days 2-6 -->

  <div class="day">
    <h2>Day 6 - 6 Days Ago ({{ date-6 }})</h2>
    <div class="links">
      <a href="https://fd.yesterday-6.ilovefreegle.org">Freegle</a>
      <a href="https://mt.yesterday-6.ilovefreegle.org">ModTools</a>
      <a href="https://mail.yesterday-6.ilovefreegle.org">Mailhog</a>
    </div>
  </div>

  <hr>
  <p><strong>Note:</strong> All environments use latest code with historical data.
  All email is captured in Mailhog (no external mail sent).</p>
</body>
</html>
```

**Dynamic Date Generation:**
- Simple bash script generates HTML daily with actual dates
- Or use nginx SSI (Server Side Includes) for dynamic dates

## Implementation Plan

### Phase 1: GCP VM Setup

1. **Provision GCP VM**
   ```bash
   gcloud compute instances create yesterday-freegle \
     --zone=europe-west2-a \
     --machine-type=n2-standard-4 \
     --boot-disk-size=500GB \
     --boot-disk-type=pd-balanced \
     --scopes=cloud-platform \
     --tags=https-server
   ```

2. **Install Docker and Dependencies**
   ```bash
   # On VM
   curl -fsSL https://get.docker.com | sh
   sudo usermod -aG docker $USER
   sudo apt-get install -y git docker-compose-plugin
   ```

3. **Configure Service Account**
   - Grant VM service account access to:
     - Cloud SQL backups (read)
     - Cloud Storage buckets (read via internal network)
     - GitHub repository access (deploy keys or PAT)

4. **Firewall Configuration**
   ```bash
   gcloud compute firewall-rules create allow-yesterday-https \
     --allow tcp:443,tcp:80 \
     --target-tags https-server
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

   # 3. Identify latest backup (avoid egress charges)
   echo "Finding latest Cloud SQL backup..."
   INSTANCE_NAME="freegle-prod"
   BACKUP_ID=$(gcloud sql backups list \
     --instance=$INSTANCE_NAME \
     --limit=1 \
     --format="value(id)")

   echo "Latest backup ID: $BACKUP_ID"

   # 4. Restore database (within GCP network)
   echo "Restoring database from backup..."

   # Option A: Create temporary Cloud SQL instance from backup
   # (May incur costs, but fast and no egress)

   # Option B: Export backup to Cloud Storage, then import
   # (Using internal network to avoid egress)
   BACKUP_FILE="yesterday-$(date +%Y%m%d).sql"
   gcloud sql export sql $INSTANCE_NAME \
     gs://freegle-yesterday-backups/$BACKUP_FILE \
     --database=freegle \
     --offload

   # Download via internal network (no egress charge)
   gsutil -o "Credentials:gs_service_key_file=/opt/gcp-key.json" \
     cp gs://freegle-yesterday-backups/$BACKUP_FILE /opt/backups/

   # 5. Copy file storage (via internal network)
   echo "Syncing file storage from Cloud Storage..."
   gsutil -m rsync -r \
     gs://freegle-prod-storage/ \
     /opt/yesterday-data/storage/

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

- **Phase 1 - VM Setup**: 1 day
- **Phase 2 - Mail Isolation**: 1 day
- **Phase 3 - Basic Restoration**: 2-3 days
- **Phase 4 - Traefik & Domains**: 2 days
- **Phase 5 - 7-Day Rolling**: 2-3 days
- **Phase 6 - Index Page**: 1 day
- **Phase 7 - Testing & Docs**: 2-3 days

**Total**: 2-3 weeks for full implementation

## Cost Analysis (Monthly)

### Compute
- **GCP VM** (n2-standard-4, 4 vCPU, 16GB RAM): ~$120/month
- **VM Disk** (500GB SSD): ~$80/month
- **Subtotal**: ~$200/month

### Storage
- **No Cloud SQL Costs**: Using push-based backups (zero egress)
- **No Cloud Storage Egress**: All internal transfers
- **VM Disk Contains All**: 7 days of database + file storage

### Network
- **Egress Charges**: ~$0 (all within GCP network)
- **User HTTPS Traffic**: Minimal (estimated <1GB/month) = ~$0.12

### Total Estimated Cost
**~$200-210/month** for complete 7-day historical environment

**Cost Savings vs. Alternative Approaches:**
- Cloud SQL clones: Would cost $100-300/month per instance × 7 = $700-2100/month
- Pulling backups: Would incur egress charges ($0.12/GB) × data size × 7 days
- Local download: Network transfer time + local hardware costs

**This approach is most cost-effective**: Single VM, push-based backups, no egress

## Conclusion

This approach is **highly feasible and cost-effective** with the push-based backup strategy:

### Key Advantages

✅ **Zero Egress Costs**: Production pushes backups, no Cloud SQL/Storage pulling
✅ **7-Day History**: Complete rolling snapshots for flexible data recovery
✅ **Latest Code**: Each environment rebuilt daily with current codebase
✅ **Mail Isolation**: Mailhog per environment, no external email risk
✅ **Basic Auth**: Protected against crawlers and casual access
✅ **Real Domains**: Professional `*.yesterday.ilovefreegle.org` URLs
✅ **Index Page**: Easy navigation to any of 7 days
✅ **Docker Compose**: Leverages existing orchestration and definitions
✅ **Cost Effective**: ~$200/month vs $700-2100 for Cloud SQL clones

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
