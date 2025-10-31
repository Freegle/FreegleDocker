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
- Percona XtraBackup tools
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
4. Monitor progress (15-25 min for large backups)

**Via Command Line:**
```bash
cd /var/www/FreegleDocker/yesterday
./scripts/restore-backup-simple.sh 20251031  # YYYYMMDD format
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

Check the logs:
```bash
tail -f /var/log/yesterday-restore.log
```

### Services won't start

Check Docker status:
```bash
docker compose -f docker-compose.yesterday.yml ps
docker compose -f docker-compose.yesterday.yml logs
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
