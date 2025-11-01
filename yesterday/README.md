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

Create a `.env` file in this directory with the following variables:

```bash
# Database credentials
DB_ROOT_PASSWORD=generate_random_password_here

# 2FA Admin API key
YESTERDAY_ADMIN_KEY=generate_random_admin_key_here

# Redis password
REDIS_PASSWORD=generate_random_redis_password_here
```

**IMPORTANT**: Never commit the `.env` file to git (it's in `.gitignore`)

### 3. DNS Configuration (Optional)

Set up DNS records pointing to the VM's external IP if using custom domains:

```
yesterday.ilovefreegle.org              A    <VM_EXTERNAL_IP>
```

Or access via localhost URLs after restoration.

### 4. Install Node.js (if needed)

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

### 5. Start the Backup API

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

### 6. Load a Backup

**Via Web UI:**
1. Visit `http://localhost:8082` or `https://yesterday.ilovefreegle.org`
2. Browse available backups from GCS
3. Click "Load This Backup" for the desired date
4. Monitor progress (30-45 min for large backups)

**Via Command Line:**
```bash
cd /var/www/FreegleDocker/yesterday
./scripts/restore-backup.sh 20251031  # YYYYMMDD format
```

This will:
- Download the backup from GCS (cached locally)
- Extract and prepare the xbstream backup
- Import into existing database volume
- Restart all containers

### 7. Access the Restored System

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
- `yesterday-api` on port 8082
- `yesterday-index` on port 8083 (nginx serving the UI with API proxy)

Access: `http://localhost:8083` or `http://yesterday.ilovefreegle.org:8083`

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

- 2FA authentication with TOTP (Google Authenticator)
- IP-based whitelisting for 24 hours after 2FA
- Multiple named, revokable users
- Read-only access to production backups
- All services isolated in dedicated GCP project

## Daily Operations

The system runs automatically:
- Nightly backup import at 2 AM UTC
- Auto-shutdown after 1 hour of no traffic
- Midnight shutdown of all days
- Automatic container restarts after VM preemption

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
   df -h /var/www/FreegleDocker/yesterday/data
   ```
   Need ~150GB free for extraction (47GB compressed → ~100GB uncompressed)

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
1. xbstream extraction (~10-15 min)
2. zstd decompression (~10-15 min)
3. xtrabackup prepare (~10-15 min)
4. Copy to volume (~2-3 min)

**Monitor progress:**
```bash
# Watch extraction
watch -n 5 'du -sh /var/www/FreegleDocker/yesterday/data/backups/temp-*'

# Watch zstd decompression
watch -n 5 'find /var/www/FreegleDocker/yesterday/data/backups/temp-* -name "*.zst" | wc -l'

# Watch xtrabackup
ps aux | grep xtrabackup
```

### Out of disk space

**Check usage:**
```bash
df -h
du -sh /var/www/FreegleDocker/yesterday/data/*
```

**Clean up old backups:**
```bash
cd /var/www/FreegleDocker/yesterday/data/backups
ls -lh  # See what's there
rm iznik-2025-10-*.xbstream  # Delete old cached backups
```

### Can't access via domain

1. Verify DNS is configured correctly
2. Check firewall rules allow HTTPS (ports 80/443)
3. Verify Traefik is running: `docker ps | grep traefik`
4. Check Let's Encrypt certificates: `docker logs yesterday-traefik`

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
