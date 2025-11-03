# Freegle Yesterday - Historical Backup Environment

This directory contains the configuration for the "Yesterday" system - a GCP-hosted environment that provides access to historical production backups for data recovery and testing.

**Note:** This system was rapidly prototyped and vibe-coded. It works, but may need refinement for production use.

## Overview

The Yesterday environment:
- Runs on a dedicated GCP VM in `freegle-yesterday` project
- Restores nightly backups from `gs://freegle_backup_uk`
- Provides access to any historical backup by date
- Uses existing docker-compose.yml infrastructure (single database model)
- Isolates all email to Mailhog (no external sending)
- Backup browser UI with progress tracking

## Architecture

**Single Database Model:**
- Uses existing FreegleDocker docker-compose.yml infrastructure
- One backup loaded at a time
- Import different backups by date as needed
- Reuses all existing containers (Freegle, ModTools, API v1/v2, Mailhog)
- Backup selection via web UI with progress tracking

## Domain Structure

- `yesterday.ilovefreegle.org` - Backup browser and index page
- Application accessible via localhost or yesterday domain after restoration

## Important Limitations

- **OAuth logins don't work** - Only email/password authentication is functional
- OAuth providers (Yahoo, Google, Facebook) are configured for production domains only
- All outbound email is captured in Mailhog - no external mail is sent

## Setup Instructions

### 1. VM Setup (Already Complete)

The VM has been provisioned with:
- Ubuntu 22.04 LTS
- Docker and Docker Compose
- Percona XtraBackup tools and zstd compression
- Cross-project IAM permissions to read from `gs://freegle_backup_uk`

### 2. Environment Configuration

Create a `.env` file in `/var/www/FreegleDocker` with the following variables:

```bash
# Database credentials
DB_ROOT_PASSWORD=generate_random_password_here

# 2FA Admin API key
YESTERDAY_ADMIN_KEY=generate_random_admin_key_here

# Redis password
REDIS_PASSWORD=generate_random_redis_password_here
```

**IMPORTANT**: Never commit the `.env` file to git (it's in `.gitignore`)

### 3. DNS Configuration (Required for HTTPS)

To access Yesterday via HTTPS with automatic Let's Encrypt certificates:

1. **Reserve a static IP (if not already done):**
```bash
gcloud compute addresses create yesterday-static-ip --project=freegle-yesterday --region=europe-west2
gcloud compute addresses describe yesterday-static-ip --project=freegle-yesterday --region=europe-west2 --format="get(address)"
```

2. **Assign static IP to VM:**
```bash
gcloud compute instances delete-access-config yesterday-freegle --project=freegle-yesterday --zone=europe-west2-a --access-config-name="external-nat"
gcloud compute instances add-access-config yesterday-freegle --project=freegle-yesterday --zone=europe-west2-a --access-config-name="external-nat" --address=<STATIC_IP>
```

3. **Set up DNS A record:**
```
yesterday.ilovefreegle.org              A    <STATIC_IP>
```

4. **Wait for DNS propagation** (usually 5-10 minutes):
```bash
nslookup yesterday.ilovefreegle.org
```

Once DNS is configured, Traefik will automatically obtain a Let's Encrypt certificate when you start the services.

### 4. SSL Certificate Setup (Let's Encrypt with DNS-01 Challenge)

The Yesterday Traefik instance is configured to automatically obtain and renew SSL certificates using Let's Encrypt with DNS-01 challenge (no need for ports 80/443 to be publicly accessible).

**Prerequisites:**
1. Cloud DNS API must be enabled in your GCP project:
```bash
gcloud services enable dns.googleapis.com --project=freegle-yesterday
```

2. Create a service account with DNS admin permissions:
```bash
# Create service account
gcloud iam service-accounts create traefik-dns \
  --display-name="Traefik DNS Challenge" \
  --project=freegle-yesterday

# Grant DNS admin role
gcloud projects add-iam-policy-binding freegle-yesterday \
  --member="serviceAccount:traefik-dns@freegle-yesterday.iam.gserviceaccount.com" \
  --role="roles/dns.admin"

# Create and download key
gcloud iam service-accounts keys create /tmp/traefik-dns-key.json \
  --iam-account=traefik-dns@freegle-yesterday.iam.gserviceaccount.com
```

3. Install the DNS key on the server (outside repository):
```bash
# Create secrets directory
sudo mkdir -p /etc/traefik/secrets

# Copy the key file
sudo cp /tmp/traefik-dns-key.json /etc/traefik/secrets/gcloud-dns-key.json

# Set proper permissions
sudo chmod 600 /etc/traefik/secrets/gcloud-dns-key.json
sudo chown root:root /etc/traefik/secrets/gcloud-dns-key.json

# Clean up temp file
rm /tmp/traefik-dns-key.json
```

4. Certificate storage directory (for manual certificates if needed):
```bash
sudo mkdir -p /etc/traefik/certs
sudo chmod 755 /etc/traefik/certs
```

**How it works:**
- Traefik mounts `/etc/traefik/secrets/gcloud-dns-key.json` from the host
- When a certificate is needed, Traefik uses the DNS-01 challenge
- Creates a TXT record in Cloud DNS to prove domain ownership
- Let's Encrypt verifies the TXT record and issues the certificate
- Certificate is stored in `./data/letsencrypt/acme.json` (managed by Traefik)
- Automatic renewal happens 30 days before expiry

**Verify certificate after startup:**
```bash
# Check Traefik logs for certificate issuance
docker logs yesterday-traefik

# Test HTTPS connection
curl -v https://yesterday.ilovefreegle.org:8444/

# View certificate details
echo | openssl s_client -connect yesterday.ilovefreegle.org:8444 2>/dev/null | openssl x509 -noout -text
```

**Troubleshooting:**

If certificate issuance fails:
1. Check Cloud DNS API is enabled: `gcloud services list --enabled | grep dns`
2. Verify service account has correct permissions
3. Check Traefik logs: `docker logs yesterday-traefik 2>&1 | grep -i acme`
4. If you hit rate limits (5 failures per hour), wait or use staging server temporarily

**Using Let's Encrypt staging (for testing):**
Edit `traefik.yml` and change:
```yaml
certificatesResolvers:
  letsencrypt:
    acme:
      # caServer: "https://acme-staging-v02.api.letsencrypt.org/directory"  # Uncomment for staging
```

**File locations (all outside repository):**
```
/etc/traefik/
├── secrets/
│   └── gcloud-dns-key.json        # GCP service account key (read-only)
└── certs/                         # Optional: manual certificates
    ├── yesterday.ilovefreegle.org.crt
    └── yesterday.ilovefreegle.org.key
```

### 5. GCP Firewall Configuration

The Yesterday services require specific firewall rules to allow external access:

**Required firewall rules:**
```bash
# Allow Yesterday Traefik (HTTP/HTTPS on alternate ports to avoid conflict with main Traefik)
gcloud compute firewall-rules create allow-yesterday-services \
  --allow tcp:8090,tcp:8444 \
  --target-tags=https-server \
  --description="Allow Yesterday Traefik (HTTP/HTTPS on ports 8090/8444)" \
  --direction=INGRESS \
  --priority=1000

# Allow SSH access
gcloud compute firewall-rules create allow-yesterday-ssh \
  --allow tcp:22 \
  --target-tags=https-server \
  --description="Allow SSH access" \
  --direction=INGRESS \
  --priority=1000
```

**Port mappings:**
- `8090` - HTTP (auto-redirects to HTTPS)
- `8444` - HTTPS for web interface (Traefik with Let's Encrypt)
- `22` - SSH access

**Access URL:**
- **`https://yesterday.ilovefreegle.org:8444`** - Main access point (2FA protected)

**Internal services (not exposed):**
- `8082` - Yesterday API (backup management) - internal only
- `8083` - Yesterday Index (web UI) - internal only
- `8084` - Yesterday 2FA Gateway - internal only, routed through Traefik

**Verify firewall rules:**
```bash
gcloud compute firewall-rules list --format="table(name,allowed[].map().firewall_rule().list():label=ALLOW,targetTags.list())"
```

**Note:** Without these firewall rules, connections to the Yesterday services will timeout or hang. The rules must be created before you can access the services from outside the VM.

### 5. Install Node.js (if needed)

The API requires Node.js 22 LTS or later:

```bash
# Check current version
node -v

# If older than v22, update:
apt-get remove -y nodejs
curl -fsSL https://deb.nodesource.com/setup_22.x | bash -
apt-get install -y nodejs

# Verify
node -v  # Should show v22.x.x
npm -v   # Should show npm 10.x.x or higher
```

### 6. Start the Backup API

Install dependencies and start the API server:

```bash
cd /var/www/FreegleDocker/yesterday/api
npm install
npm start
# API runs on port 8082
```

**Running as a background service:**
```bash
# Install pm2 globally
npm install -g pm2

# Start API with pm2
cd /var/www/FreegleDocker/yesterday/api
pm2 start server.js --name yesterday-api

# View logs
pm2 logs yesterday-api

# Configure pm2 to start on boot
pm2 startup
pm2 save
```

### 7. Load a Backup

**Via Web UI:**
1. Visit `https://yesterday.ilovefreegle.org:8444` (or `http://localhost:8090` locally)
2. Authenticate with 2FA (TOTP code)
3. Browse available backups from GCS
4. Click "Load This Backup" for the desired date
5. Monitor progress (1-2 hours for large backups)

**Via Command Line:**
```bash
cd /var/www/FreegleDocker/yesterday
./scripts/restore-backup.sh 20251031  # YYYYMMDD format
```

This will:
- Automatically configure containers to use production image services
- Stream the backup directly from GCS (no local caching)
- Extract and decompress directly to Docker volume (no temp directory)
- Prepare the backup in place with xtrabackup
- Restart all containers

Peak disk usage: ~100GB (only the final volume, no temp copies)

Note: The script automatically copies `yesterday/docker-compose.override.yml` to configure all containers to use production image delivery and TUS uploader services, so restored backups display the correct images.

### 8. Set Up 2FA Gateway (Optional but Recommended)

The Yesterday backup UI is protected by 2FA (TOTP) authentication:

**Start the 2FA-protected services:**
```bash
cd /var/www/FreegleDocker
docker compose -f yesterday/docker-compose.yesterday-services.yml up -d
```

This starts:
- `yesterday-2fa` on port 8084 (2FA gateway - use this for public access)
- `yesterday-api` on port 8082 (backup API - internal)
- `yesterday-index` on port 8083 (web UI - internal)

**Add your first user:**
```bash
export YESTERDAY_ADMIN_KEY=your_admin_key_from_env_file
./yesterday/scripts/2fa-admin.sh add your_username
```

This will display a QR code. Scan it with Google Authenticator or any TOTP app.

**Access the system:**
- Public (2FA-protected): `https://yesterday.ilovefreegle.org:8444`
- HTTP: `http://yesterday.ilovefreegle.org:8090` (automatically redirects to HTTPS)
- Let's Encrypt certificate automatically obtained and renewed
- Uses alternate ports to avoid conflict with main Freegle Traefik

After successful 2FA login, your IP is whitelisted for 1 hour.

**Note**: On first startup after DNS configuration, Traefik will automatically request a Let's Encrypt certificate. This takes 30-60 seconds. Check logs:
```bash
docker logs yesterday-traefik
```

**Manage users:**
```bash
./yesterday/scripts/2fa-admin.sh list          # List users
./yesterday/scripts/2fa-admin.sh add alice     # Add user
./yesterday/scripts/2fa-admin.sh delete bob    # Remove user
./yesterday/scripts/2fa-admin.sh status        # Check gateway status
```

### 9. Access the Restored System

Once restoration completes, access the system:
- **Freegle**: http://localhost:3000 (freegle-prod container)
- **ModTools**: http://localhost:3001 (modtools-prod container)
- **Mailhog**: http://localhost:8025
- **API v1**: http://localhost:80
- **API v2**: http://localhost:8192

**Note**: Only email/password logins work. OAuth providers won't work on the Yesterday environment.

## Deployment Options

You can run the Yesterday API in three ways:

### Option 1: Docker Compose (Recommended)

Run as part of the main docker-compose stack:

```bash
cd /var/www/FreegleDocker
docker compose -f docker-compose.yml -f yesterday/docker-compose.yesterday-services.yml up -d
```

This starts:
- `yesterday-traefik` on ports 8090/8444 (reverse proxy with HTTPS)
- `yesterday-2fa` (2FA authentication gateway)
- `yesterday-api` (backup management API)
- `yesterday-index` (static web UI)

Access: `https://yesterday.ilovefreegle.org:8444`

### Option 2: Systemd Service

Run the API as a systemd service on the host:

```bash
cd /var/www/FreegleDocker/yesterday
./scripts/setup-systemd.sh
```

Commands:
```bash
systemctl status yesterday-api   # Check status
systemctl restart yesterday-api  # Restart
journalctl -u yesterday-api -f   # View logs
```

### Option 3: Manual (Development)

Run directly with Node.js:

```bash
cd /var/www/FreegleDocker/yesterday/api
npm start
```

## Automatic Nightly Restoration

To enable automatic restoration of the latest backup at 6 AM UTC:

```bash
cd /var/www/FreegleDocker/yesterday
./scripts/setup-cron.sh
```

This installs a cron job that:
- Runs daily at 6 AM UTC (1.5 hours after backup completion at 4:30 AM)
- Only restores backups that are 2+ hours old (safety check)
- Only restores if a newer backup is available
- Logs to `/var/log/yesterday-auto-restore.log`

To manually trigger auto-restore:
```bash
./scripts/auto-restore-latest.sh
```

## Security

The Yesterday system is secured with multiple layers:

**2FA Authentication Gateway:**
- TOTP-based authentication (Google Authenticator compatible)
- IP-based whitelisting for 1 hour after successful authentication
- Multiple named users with individual TOTP secrets
- Users can be added/removed instantly via admin CLI
- Failed login attempts are logged

**Infrastructure Security:**
- Read-only access to production backups via GCP IAM
- All services isolated in dedicated `freegle-yesterday` GCP project
- Preemptible VM to minimize costs
- Firewall rules allow only required ports (22, 80, 443, 8082-8084)
- 2FA gateway protects access to backup management UI
- All outbound email captured in Mailhog (no external sending)

**Known Security Consideration:**
- **IP Whitelisting Limitation**: Current implementation whitelists by IP address for 1 hour after successful 2FA. If a user's IP address changes (e.g., mobile network), another user could temporarily gain access if they receive that IP within the whitelist window. Future enhancement: implement secure cookie-based session authentication instead of IP-based whitelisting.

**Data Access:**
- Restored databases are snapshots from production
- OAuth logins don't work (production-only configuration)
- Email/password authentication works for testing
- Images/uploads served from production storage (read-only)

## Daily Operations

The system runs automatically:
- Nightly backup import at 2 AM UTC
- Auto-shutdown after 1 hour of no traffic
- Midnight shutdown of all days
- Automatic container restarts after VM preemption

### VM Management

Start the Yesterday VM:

```bash
gcloud compute instances start yesterday-freegle --project=freegle-yesterday --zone=europe-west2-a
```

Stop the Yesterday VM:

```bash
gcloud compute instances stop yesterday-freegle --project=freegle-yesterday --zone=europe-west2-a
```

SSH into the VM:

```bash
gcloud compute ssh yesterday-freegle --project=freegle-yesterday --zone=europe-west2-a
```

## Monitoring

Check the status:

```bash
docker compose -f docker-compose.yesterday.yml ps
```

View logs:

```bash
docker compose -f docker-compose.yesterday.yml logs -f
```

## Troubleshooting

### Backup restoration fails

**Check restoration logs:**
```bash
tail -f /var/log/yesterday-restore-YYYYMMDD.log
```

**Common issues:**

1. **Missing zstd** - Backups are zstd-compressed:
   ```bash
   apt-get install -y zstd
   ```

2. **Extraction fails** - Check disk space:
   ```bash
   df -h
   ```
   Need ~100GB free (extracts directly to volume, no temp directory)

3. **xtrabackup prepare fails** - Check if backup file is corrupt:
   ```bash
   xbstream -x < backup.xbstream -C /tmp/test
   # Should extract without errors
   ```

4. **Partial upload** - Auto-restore skips backups less than 2 hours old:
   ```bash
   # Check backup timestamp
   gsutil ls -l gs://freegle_backup_uk/iznik-*.xbstream | tail -5
   ```

### API won't start

**Check if port 8082 is in use:**
```bash
netstat -tlnp | grep 8082
```

**View API logs:**
```bash
# Docker compose
docker logs yesterday-api

# Systemd
journalctl -u yesterday-api -f

# Manual
tail -f /var/log/yesterday-api.log
```

**Common fixes:**
```bash
# Restart API
docker restart yesterday-api

# Or systemd
systemctl restart yesterday-api
```

### Can't access web UI

**Check nginx is running:**
```bash
docker ps | grep yesterday-index
curl http://localhost:8083
```

**Check API connectivity:**
```bash
curl http://localhost:8082/health
curl http://localhost:8082/api/backups
```

### Services won't start

**Check Docker status:**
```bash
docker compose ps
docker compose logs db
```

**Check database is running:**
```bash
docker exec freegle-db mysql -uroot -p"${IZNIK_DB_PASSWORD}" -e "SHOW DATABASES;"
```

### Slow restoration

**Restoration takes 30-45 minutes** due to:
1. Streaming and extracting from GCS (~10-15 min)
2. zstd decompression (~10-15 min)
3. xtrabackup prepare (~10-15 min)

**Monitor progress:**
```bash
# Watch volume size
VOLUME_PATH=$(docker volume inspect freegle_db -f '{{.Mountpoint}}')
watch -n 5 "du -sh $VOLUME_PATH"

# Watch zstd decompression
watch -n 5 "find $VOLUME_PATH -name '*.zst' | wc -l"

# Watch xtrabackup
ps aux | grep xtrabackup
```

### Out of disk space

**Check usage:**
```bash
df -h
docker system df
```

**Clean up Docker resources:**
```bash
# Remove unused volumes
docker volume prune

# Remove old images
docker image prune -a
```

Note: The restoration script streams from GCS and extracts directly to the Docker volume without caching or temp directories, using only ~100GB at peak.

### Can't access via domain

**Check each layer:**

1. **Verify DNS is configured correctly:**
   ```bash
   nslookup yesterday.ilovefreegle.org
   ```

2. **Check GCP firewall rules:**
   ```bash
   gcloud compute firewall-rules list --filter="name~'yesterday'" --format="table(name,allowed)"
   ```

   Required rules:
   - `allow-yesterday-services` (tcp:8090,tcp:8444)
   - `allow-yesterday-ssh` (tcp:22)

3. **Test connectivity from outside:**
   ```bash
   curl -I https://yesterday.ilovefreegle.org:8444
   # Should return 401 (authentication required) or login page
   ```

4. **Verify services are running:**
   ```bash
   docker ps | grep yesterday
   docker logs yesterday-2fa
   docker logs yesterday-api
   docker logs yesterday-index
   ```

5. **Check Traefik (if using HTTPS):**
   ```bash
   docker ps | grep traefik
   docker logs yesterday-traefik
   ```

## Complete Removal

To completely remove the yesterday environment:

```bash
gcloud projects delete freegle-yesterday
```

This deletes everything (VM, disk, firewall rules, etc.) with one command.

## Files in This Directory

- `docker-compose.yesterday.yml` - Main compose configuration
- `scripts/restore-day.sh` - Backup restoration script
- `scripts/2fa-admin.sh` - 2FA user management
- `scripts/auto-shutdown.py` - Traffic monitoring and auto-shutdown
- `2fa-gateway/` - 2FA authentication service
- `index/` - Landing page HTML
- `.env.example` - Template for environment variables

## See Also

- Full planning document: `/plans/Yesterday.md`
- Production backup bucket: `gs://freegle_backup_uk`
- GCP project: `freegle-yesterday`
- Production project: `freegle-1139`
