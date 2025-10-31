# Freegle Yesterday - Historical Backup Environment

This directory contains the configuration for the "Yesterday" system - a GCP-hosted environment that provides access to historical production backups for data recovery and testing.

## Overview

The Yesterday environment:
- Runs on a dedicated GCP VM in `freegle-yesterday` project
- Restores nightly backups from `gs://freegle_backup_uk`
- Provides access to 2-7 days of historical snapshots
- Rebuilds containers daily with latest code
- Isolates all email to Mailhog (no external sending)
- Protected by 2FA authentication

## Architecture

- **Phase 1**: 2 days of backups (Day 0 and Day 1)
- **Phase 2**: 7 days of backups (Day 0 through Day 6)
- Multiple days can run simultaneously with auto-shutdown after 1 hour idle or at midnight UTC

## Domain Structure

- `yesterday.ilovefreegle.org` - Index page with 2FA login
- `fd.yesterday-0.ilovefreegle.org` - Freegle (most recent backup)
- `mt.yesterday-0.ilovefreegle.org` - ModTools (most recent backup)
- `mail.yesterday-0.ilovefreegle.org` - Mailhog (most recent backup)
- Pattern repeats for days 1-6

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

### 3. DNS Configuration

Set up DNS records pointing to the VM's external IP:

```
yesterday.ilovefreegle.org              A    <VM_EXTERNAL_IP>
*.yesterday-0.ilovefreegle.org          A    <VM_EXTERNAL_IP>
*.yesterday-1.ilovefreegle.org          A    <VM_EXTERNAL_IP>
... (through yesterday-6 for Phase 2)
```

### 4. Initial Backup Restoration

Run the restoration script to import the first backup:

```bash
cd /var/www/FreegleDocker/yesterday
./scripts/restore-day.sh 0
```

This will:
- Download the latest backup from GCS
- Extract the xbstream backup
- Import to the database container
- Start all services

### 5. Create 2FA Users

Create your first 2FA user:

```bash
cd /var/www/FreegleDocker/yesterday
./scripts/2fa-admin.sh create your-name
```

This will generate a QR code - scan it with Google Authenticator.

### 6. Access the System

1. Visit `https://yesterday.ilovefreegle.org`
2. Enter your 6-digit 2FA code
3. Your IP will be whitelisted for 24 hours
4. Click "Start Day 0" to launch the environment
5. Access Freegle, ModTools, and Mailhog

## Cost Estimate

**Phase 1 (2 days)**: ~$26/month
- Compute: $18/month (preemptible n2-standard-2)
- Storage: $8/month (200GB standard disk)

**Phase 2 (7 days)**: ~$40/month
- Compute: $18/month (same VM)
- Storage: $20/month (500GB standard disk)

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
