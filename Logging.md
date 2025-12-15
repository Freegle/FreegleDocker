# Logging Configuration

This document describes the logging configuration for Freegle, including what is logged to Grafana Loki and retention policies.

## Overview

Freegle uses Grafana Loki for centralised log aggregation. Logs are collected from:

- **iznik-server** (PHP v1 API)
- **iznik-server-go** (Go v2 API)
- **iznik-batch** (Laravel batch processing)

All logging uses fire-and-forget async patterns to avoid impacting API latency.

## Accessing Logs

- **Grafana UI**: http://localhost:3200 (default credentials: admin/freegle)
- **Loki API**: http://localhost:3100

## Log Categories and Retention

Retention times are based on the existing `purge_logs.php` policies from the database.

| Category | Source | Labels | Retention | Notes |
|----------|--------|--------|-----------|-------|
| API Requests | api | `source=api` | 48 hours | Basic request info (endpoint, method, status, duration) |
| API Headers | api | `source=api_headers` | 7 days | Request/response headers for debugging |
| Login/Logout | logs_table | `subtype=Login` or `subtype=Logout` | 365 days | User authentication events |
| User Created | logs_table | `subtype=Created` | 31 days | New user registrations |
| User Deleted | logs_table | `subtype=Deleted` | 31 days | User account deletions |
| Bounces | logs_table | `subtype=Bounce` | 90 days | Email bounce events |
| Email Sends | email | `source=email` | 31 days | Outbound email tracking |
| Plugin | logs_table | `type=Plugin` | 1 day | Plugin activity (high volume) |
| Batch Jobs | laravel | `app=freegle-batch` | 31 days | Laravel queue/scheduled jobs |
| General Logs | logs_table | Default | 31 days | All other log entries |

## Log Labels

All logs include these base labels:

| Label | Description |
|-------|-------------|
| `app` | Application name (`freegle` or `freegle-batch`) |
| `source` | Log source (`api`, `api_headers`, `logs_table`, `email`, `laravel`) |

### API-specific labels

| Label | Description |
|-------|-------------|
| `api_version` | `v1` (PHP) or `v2` (Go) |
| `method` | HTTP method (GET, POST, etc.) |
| `status_code` | HTTP response status code |

### Logs table labels

| Label | Description |
|-------|-------------|
| `type` | Log type (User, Group, Message, etc.) |
| `subtype` | Log subtype (Login, Logout, Created, etc.) |
| `groupid` | Group ID if applicable |

## Configuration

### Environment Variables

| Variable | Default | Description |
|----------|---------|-------------|
| `LOKI_ENABLED` | `false` | Enable/disable Loki logging |
| `LOKI_URL` | `http://loki:3100` | Loki server URL |

In Docker development, `LOKI_ENABLED` is set to `true` in docker-compose.yml.

### Loki Configuration

The Loki configuration is in `conf/loki-config.yaml`. Key settings:

- **Default retention**: 31 days
- **Stream-specific retention**: Configured per log category (see table above)
- **Compaction**: Runs every 10 minutes with 2-hour delay

## Querying Logs

### Example LogQL Queries

```logql
# All v1 API errors in last hour
{source="api", api_version="v1", status_code=~"5.."}

# Login events for a specific user
{source="logs_table", subtype="Login"} |= "user_id\":12345"

# API headers for debugging a specific endpoint
{source="api_headers"} |= "/api/message"

# All batch job logs
{app="freegle-batch"}

# High latency API calls (>1000ms)
{source="api"} | json | duration_ms > 1000
```

### Useful Filters

- `|=` - Line contains string
- `!=` - Line does not contain string
- `|~` - Line matches regex
- `| json` - Parse JSON and enable field queries

## Header Logging

API headers are logged separately with a 7-day retention for debugging purposes.

### Headers Captured

**Included:**
- User-Agent
- Referer
- Content-Type
- Accept
- X-Forwarded-For
- X-Request-ID
- Origin
- Accept-Language

**Excluded (for security):**
- Authorization
- Cookie
- Any header containing "token", "key", "secret", or "password"

## Performance Considerations

All logging is designed to have minimal impact on API latency:

1. **PHP (v1 API)**: Uses `register_shutdown_function()` to log after response is sent, with non-blocking sockets.

2. **Go (v2 API)**: Uses goroutines for async logging with a background flusher.

3. **Laravel (batch)**: Custom Monolog handler with fire-and-forget socket writes.

4. **Batching**: Logs are batched (10 entries or 5 seconds) before sending to Loki.

## Troubleshooting

### Logs not appearing in Grafana

1. Check Loki is running: `docker logs freegle-loki`
2. Verify LOKI_ENABLED is set: Check container environment
3. Test Loki connection: `curl http://localhost:3100/ready`

### High memory usage

Reduce batch size or increase flush interval in the respective handler configurations.

### Missing old logs

Check retention configuration in `conf/loki-config.yaml`. Logs are automatically deleted after their retention period.

## Database Migration to Loki

Historical logs can be migrated from the database (`logs` and `logs_api` tables) to Loki using CLI scripts.

### Export Script: logs_dump.php

Run on production to export logs to a JSON file.

```bash
# Export last 7 days of logs table
php scripts/cli/logs_dump.php -d 7 -t logs -o /tmp/logs_7days.json

# Export specific date range, both tables
php scripts/cli/logs_dump.php -s "2025-12-01" -e "2025-12-15" -t both -o /tmp/logs_dec.json
```

**Arguments:**
- `-s <start>` - Start date (YYYY-MM-DD or YYYY-MM-DD HH:MM:SS)
- `-e <end>` - End date
- `-d <days>` - Days ago (alternative to start/end)
- `-t <table>` - Table: `logs`, `logs_api`, or `both` (default: both)
- `-o <file>` - Output file path
- `-b <batch>` - Batch size for DB queries (default: 10000)
- `-v` - Verbose output

**Output format (JSON Lines):**
```json
{"source":"logs","timestamp":"2025-12-15 10:30:00","type":"User","subtype":"Login","user":12345,"text":"via Google"}
{"source":"logs_api","timestamp":"2025-12-15 10:32:00","userid":12345,"request":{...},"response":{...}}
```

### Import Script: logs_loki_import.php

Run locally to import the JSON file into Loki.

```bash
# Import from file
php scripts/cli/logs_loki_import.php -i /tmp/logs_7days.json -v

# Dry run to verify file
php scripts/cli/logs_loki_import.php -i /tmp/logs_7days.json --dry-run
```

**Arguments:**
- `-i <file>` - Input JSON file (required)
- `-b <batch>` - Batch size for Loki sends (default: 100)
- `-v` - Verbose output
- `--dry-run` - Parse file and show stats without sending

## Log Viewer Framework (Planned)

A framework for viewing Loki logs in ModTools with role-based access control.

### User Roles & Perspectives

| Perspective | Who Can Access | What They See |
|-------------|----------------|---------------|
| **User** | All moderators | Logs for users in their groups |
| **Group** | All moderators | Logs for groups they moderate |
| **System** | Support/Admin only | API requests, errors, system events |

### Timeline Display

Logs are displayed in a visual timeline format, grouped by day with clear timestamps.

**Features:**
- Chronological timeline with day separators
- Activity icons for each log type (login, post, approval, etc.)
- Collapsible day sections for large date ranges
- Real-time updates for recent activity

### Human-Readable Log Display

Logs are displayed with human-readable text, hiding technical details by default.

**Example mappings:**
- `User/Login` → "Logged in via Google"
- `User/Bounce` → "Email bounced: mailbox full"
- `Message/Received` → "Posted message"
- `Message/Approved` → "Message approved"
- `Group/Joined` → "Joined Freegle Cambridge"

Raw JSON details are available via expandable panel for debugging.

### Entity Linking

Every entity mentioned in logs is clickable and links to the relevant ModTools page:

| Entity | Link Target | Example |
|--------|-------------|---------|
| **User** | Support Tools → Find User | "John Smith (#12345)" → `/support/12345` |
| **Group** | Group page / Settings | "Freegle Cambridge" → `/groups/456` |
| **Message** | Message details | "Offer: Garden table (#789)" → `/messages/pending/789` |
| **Chat** | Chat conversation | "Chat with Jane" → `/chats/123` |
| **Config** | Mod config settings | "Standard config" → `/settings/configs/456` |

**Implementation:**
- Use existing `ModLogUser`, `ModLogGroup`, `ModLogMessage` components as patterns
- Wrap entity names in clickable links
- Show hover preview with key details (user email, message subject, etc.)
- Support Ctrl+click to open in new tab

### API Endpoint

`GET /api/lokilogs`

**Parameters:**
- `perspective` - user | group | system
- `userid` - User ID (for user perspective)
- `groupid` - Group ID (for group perspective)
- `types[]` - Filter by log types
- `start`, `end` - Time range (ISO format)
- `limit`, `context` - Pagination

### Integration Points

1. **Support Tools → Find User** - "Activity Logs" button shows user's Loki logs
2. **Support Tools → System Logs** - Admin/Support can view API metrics and errors
3. **Group Settings** - Moderators can view group activity logs

### Visual Mockups

#### User Activity Timeline (Support Tools)

```
┌─────────────────────────────────────────────────────────────────────────────┐
│ Activity Logs for John Smith (#12345)                              [X Close]│
├─────────────────────────────────────────────────────────────────────────────┤
│ Filter: [All ▼]  Date: [Last 7 days ▼]  [Search...]           [🔄 Refresh] │
├─────────────────────────────────────────────────────────────────────────────┤
│                                                                             │
│ ── Today (15 Dec 2025) ─────────────────────────────────────────────────── │
│                                                                             │
│ 14:32  🔑  Logged in via Google                                            │
│        └── [Show details ▼]                                                │
│                                                                             │
│ 14:35  📝  Posted "Offer: Garden table" to Freegle Cambridge               │
│        └── Message #456789 • Currently Pending                             │
│                                                                             │
│ 14:36  ✅  Message approved by Sarah Mod (#98765)                          │
│        └── Message #456789                                                  │
│                                                                             │
│ 15:10  💬  Started chat with Jane Doe (#11111)                             │
│        └── Chat #789 • About: Garden table                                 │
│                                                                             │
│ ── Yesterday (14 Dec 2025) ─────────────────────────────────────────────── │
│                                                                             │
│ 09:15  🔑  Logged in via email link                                        │
│ 10:30  📧  Email bounced: "mailbox full"                                   │
│        └── [Unbounce user]                                                 │
│                                                                             │
│ ── 13 Dec 2025 ─────────────────────────────────────────────────────────── │
│                                                                             │
│ [+ Show 5 more entries]                                                    │
│                                                                             │
└─────────────────────────────────────────────────────────────────────────────┘
```

#### Expanded Log Entry Details

```
┌─────────────────────────────────────────────────────────────────────────────┐
│ 14:35  📝  Posted "Offer: Garden table" to Freegle Cambridge               │
├─────────────────────────────────────────────────────────────────────────────┤
│ ▼ Details                                                                  │
│ ┌─────────────────────────────────────────────────────────────────────────┐│
│ │ Type:      Message / Received                                           ││
│ │ Message:   #456789 (click to view)                                      ││
│ │ Group:     Freegle Cambridge (#456)                                     ││
│ │ Status:    Pending → Approved                                           ││
│ │ Source:    Web (via Chrome on Windows)                                  ││
│ │ Time:      14:35:22 UTC                                                 ││
│ │                                                                         ││
│ │ Raw JSON:                                                               ││
│ │ {                                                                       ││
│ │   "type": "Message",                                                    ││
│ │   "subtype": "Received",                                                ││
│ │   "user": 12345,                                                        ││
│ │   "msgid": 456789,                                                      ││
│ │   "groupid": 456,                                                       ││
│ │   "timestamp": "2025-12-15T14:35:22Z"                                   ││
│ │ }                                                                       ││
│ └─────────────────────────────────────────────────────────────────────────┘│
└─────────────────────────────────────────────────────────────────────────────┘
```

#### Group Activity Logs

```
┌─────────────────────────────────────────────────────────────────────────────┐
│ Group Activity: Freegle Cambridge                                          │
├─────────────────────────────────────────────────────────────────────────────┤
│ [Messages ▼] [Members ▼] [Moderation ▼]  Date: [Today ▼]  [Search...]     │
├─────────────────────────────────────────────────────────────────────────────┤
│                                                                             │
│ 15:42  👤  John Smith joined (clicked Join button)                         │
│        └── User #12345 • Now has 3 memberships                             │
│                                                                             │
│ 15:38  ✅  Jane Doe approved message from Bob Wilson                       │
│        └── "Wanted: Kids bike" • Message #456790                           │
│                                                                             │
│ 15:30  ⚠️  Message flagged for worry words: "urgent cash"                  │
│        └── From: New User (#99999) • "Offer: urgent cash needed"           │
│                                                                             │
│ 15:25  🚫  Spam message rejected by auto-filter                            │
│        └── From: spammer@example.com • Score: 95%                          │
│                                                                             │
│ 14:50  📤  Auto-reposted 3 messages (7-day repost)                         │
│        └── Messages: #456700, #456701, #456702                             │
│                                                                             │
└─────────────────────────────────────────────────────────────────────────────┘
```

#### System Logs (Admin/Support Only)

```
┌─────────────────────────────────────────────────────────────────────────────┐
│ System Logs                                            [Admin/Support View] │
├─────────────────────────────────────────────────────────────────────────────┤
│ [API Requests] [Errors] [Performance] [Email]   Date: [Last hour ▼]        │
├─────────────────────────────────────────────────────────────────────────────┤
│                                                                             │
│ ┌─ API Summary ─────────────────────────────────────────────────────────┐  │
│ │ Requests/min: 1,234     Avg response: 45ms     Error rate: 0.3%      │  │
│ │ v1 API: 65%             v2 API: 35%            Peak: 2,100/min       │  │
│ └───────────────────────────────────────────────────────────────────────┘  │
│                                                                             │
│ ── Recent Errors ───────────────────────────────────────────────────────── │
│                                                                             │
│ 15:42:33  ❌  500 POST /api/message - Database timeout                     │
│           └── User #12345 • Duration: 30,042ms • [View in Sentry]         │
│                                                                             │
│ 15:38:11  ❌  500 GET /api/session - Redis connection failed               │
│           └── 3 occurrences in last 5 min • [View in Sentry]              │
│                                                                             │
│ ── Slow Requests (>1000ms) ─────────────────────────────────────────────── │
│                                                                             │
│ 15:40:22  ⚡  GET /api/messages (2,340ms)                                   │
│           └── User #54321 • Query: groupid=456, limit=100                  │
│                                                                             │
└─────────────────────────────────────────────────────────────────────────────┘
```

#### Icons Legend

| Icon | Meaning |
|------|---------|
| 🔑 | Login/Logout |
| 📝 | Message posted |
| ✅ | Approved (message or member) |
| 🚫 | Rejected/Deleted |
| ⚠️ | Warning/Flagged |
| 💬 | Chat activity |
| 👤 | Member activity (join/leave) |
| 📧 | Email event (bounce, send) |
| ⚡ | Performance warning |
| ❌ | Error |
| 🔄 | Repost/Retry |

## Production Architecture

### Current Phase: MySQL + Loki (Parallel, Feature-Flagged)

MySQL logging continues as source of truth. Loki runs in parallel for fast searching and visualisation.

```
┌─────────────────────────────────────────────────────────────────────────────┐
│                            Production Servers                               │
├─────────────────┬─────────────────┬─────────────────┬─────────────────────────┤
│   PHP Server    │   Go Server     │   Batch Jobs    │   Other Services      │
│   (API v1)      │   (API v2)      │   (Laravel)     │   (cron, etc.)        │
└────────┬────────┴────────┬────────┴────────┬────────┴───────────┬───────────┘
         │                 │                 │                     │
         │    ┌────────────┴─────────────────┴─────────────────────┘
         │    │
         ▼    ▼
┌─────────────────────────────────────────────────────────────────────────────┐
│                         JSON Log Files                                      │
│   /var/log/freegle/api.log                                                  │
│   /var/log/freegle/batch.log                                                │
│   (append-only, logrotate managed)                                          │
└────────────────────────────────┬────────────────────────────────────────────┘
                                 │
                    ┌────────────▼────────────┐
                    │    Grafana Alloy        │  ← Tails files, ships to Loki
                    │                         │
                    │  - Disk buffer (1GB)    │  ← Survives Loki outages
                    │  - positions.yaml       │  ← Tracks progress
                    │  - Retry with backoff   │
                    └────────────┬────────────┘
                                 │
         ┌───────────────────────┴───────────────────────┐
         │                                               │
         ▼                                               ▼
┌─────────────────────────┐                 ┌─────────────────────────┐
│        MySQL            │                 │         Loki            │
│   (Source of Truth)     │                 │   (Fast Search/Query)   │
│                         │                 │                         │
│  - logs table           │                 │  - GCS backend          │
│  - logs_api table       │                 │  - 31-day retention     │
│  - Retained as audit    │                 │  - Grafana dashboards   │
└─────────────────────────┘                 └────────────┬────────────┘
                                                         │
                                            ┌────────────▼────────────┐
                                            │   Google Cloud Storage  │
                                            │                         │
                                            │  - gs://freegle-loki/   │
                                            │  - Object versioning    │
                                            │  - Cross-region backup  │
                                            └─────────────────────────┘
```

### Feature Flags

| Variable | Default | Description |
|----------|---------|-------------|
| `LOKI_ENABLED` | `false` | Enable Loki logging (apps write to JSON files) |
| `ALLOY_ENABLED` | `false` | Enable Grafana Alloy agent (ships files to Loki) |
| `MYSQL_LOGGING` | `true` | Continue logging to MySQL (keep enabled for now) |

### JSON Log File Format

Apps write structured JSON logs to local files:

```json
{"ts":"2025-12-15T10:30:00Z","level":"info","source":"api","type":"User","subtype":"Login","user":12345,"text":"via Google","groupid":null}
{"ts":"2025-12-15T10:30:01Z","level":"info","source":"api","type":"Message","subtype":"Approved","user":12345,"msgid":789,"groupid":456,"byuser":98765}
```

**File locations:**
- `/var/log/freegle/api-v1.log` - PHP API logs
- `/var/log/freegle/api-v2.log` - Go API logs
- `/var/log/freegle/batch.log` - Laravel batch job logs

**Logrotate config:**
```
/var/log/freegle/*.log {
    daily
    rotate 7
    compress
    delaycompress
    missingok
    notifempty
    copytruncate
}
```

### Grafana Alloy Configuration

Alloy replaces Promtail (deprecated Feb 2026). Configuration:

```yaml
# /etc/alloy/config.alloy

// Tail Freegle log files
local.file_match "freegle_logs" {
  path_targets = [
    {__path__ = "/var/log/freegle/*.log"},
  ]
}

loki.source.file "freegle" {
  targets    = local.file_match.freegle_logs.targets
  forward_to = [loki.write.default.receiver]

  // Track position for crash recovery
  tail_from_end = true
}

// Ship to Loki with disk buffer
loki.write "default" {
  endpoint {
    url = "http://loki:3100/loki/api/v1/push"
  }

  // Disk buffer survives restarts/outages
  external_labels = {
    env = "production",
  }
}
```

### Why This Architecture?

1. **MySQL remains source of truth** - Critical audit logs preserved in database.
2. **JSON files decouple apps from Loki** - Apps never block on Loki availability.
3. **Alloy handles shipping** - Disk buffer, retries, position tracking built-in.
4. **GCS provides durability** - Object storage with versioning for backup.
5. **Gradual migration** - Can disable MySQL logging once Loki is proven reliable.

## Backup and Restore

### Loki Storage with Google Cloud Storage

Production Loki stores all data (chunks + TSDB index) in GCS:

```yaml
# conf/loki-config.yaml (production)
storage_config:
  gcs:
    bucket_name: freegle-loki
  tsdb_shipper:
    active_index_directory: /loki/index
    cache_location: /loki/cache
    shared_store: gcs
```

**GCS Bucket Configuration:**
- **Bucket**: `gs://freegle-loki/`
- **Location**: `europe-west2` (London)
- **Storage class**: Standard
- **Object versioning**: Enabled (protects against accidental deletion)
- **Lifecycle rules**: Delete non-current versions after 30 days

### Backup Strategy

Since Loki stores everything in GCS, backup uses GCS-native features:

**1. Cross-Region Replication (Primary Backup)**
```bash
# Set up replication to US region for disaster recovery
gsutil replication set gs://freegle-loki gs://freegle-loki-backup-us
```

**2. Daily Snapshot to Separate Bucket**
```bash
# Cron job on backup server (runs with DB backups)
#!/bin/bash
DATE=$(date +%Y%m%d)
gsutil -m rsync -r gs://freegle-loki/ gs://freegle-backups/loki/$DATE/
```

**3. Retention of Backups**
- Keep daily snapshots for 7 days
- Keep weekly snapshots for 4 weeks
- Keep monthly snapshots for 12 months

### Restore to Yesterday System

The yesterday system can restore Loki data for historical analysis.

**Option A: Point Yesterday's Loki at GCS (Recommended)**

Configure yesterday's Loki to read from the same GCS bucket (read-only):

```yaml
# conf/loki-config.yaml (yesterday)
storage_config:
  gcs:
    bucket_name: freegle-loki
  tsdb_shipper:
    active_index_directory: /loki/index
    cache_location: /loki/cache
    shared_store: gcs
    # Read-only mode - don't write new data
    ingester_name: yesterday-readonly

# Disable ingestion on yesterday
ingester:
  lifecycler:
    ring:
      replication_factor: 1
  chunk_idle_period: 999h  # Never flush
```

**Option B: Sync GCS to Local Filesystem**

For fully offline yesterday access:

```bash
# On yesterday server
#!/bin/bash
# Sync from GCS to local storage
gsutil -m rsync -r gs://freegle-loki/ /data/loki-restore/

# Then configure Loki to use filesystem storage
# conf/loki-config.yaml (yesterday - filesystem mode)
storage_config:
  filesystem:
    directory: /data/loki-restore/chunks
  tsdb_shipper:
    active_index_directory: /data/loki-restore/index
    cache_location: /data/loki-restore/cache
    shared_store: filesystem
```

**Option C: Selective Date Range Restore**

Restore only specific date ranges:

```bash
# Sync only December 2025 data
gsutil -m rsync -r \
  gs://freegle-loki/fake/index/tsdb/index_19722/ \
  /data/loki-restore/index/

gsutil -m rsync -r \
  gs://freegle-loki/fake/chunks/ \
  /data/loki-restore/chunks/
```

### Restore Verification

After restoring to yesterday:

```bash
# Check Loki is serving data
curl -s "http://localhost:3100/loki/api/v1/query?query={app=\"freegle\"}" | jq .

# Query specific date range
curl -G "http://localhost:3100/loki/api/v1/query_range" \
  --data-urlencode 'query={source="api"}' \
  --data-urlencode 'start=2025-12-01T00:00:00Z' \
  --data-urlencode 'end=2025-12-15T00:00:00Z'
```

### Migration Caveats

**Known issues with Loki data migration:**

1. **No official migration tool** - Loki's migrate command is experimental and "NOT WELL TESTED".
2. **Schema must match** - Source and dest Loki must use same schema version.
3. **Index format matters** - TSDB indexes are not easily portable between instances.
4. **GCS is simplest** - Reading from same bucket avoids migration entirely.

**Recommendation**: Always use GCS as shared storage. Yesterday reads from same bucket as production (with appropriate IAM permissions).

## Implementation Phases

### Phase 1: Current (MySQL Primary)
- [x] MySQL `logs` and `logs_api` tables as source of truth
- [x] Direct Loki integration in PHP/Go (feature-flagged, dev only)
- [x] CLI tools for database-to-Loki migration

### Phase 2: Parallel Logging (In Progress)
- [ ] Apps write to JSON log files (in addition to MySQL)
- [ ] Deploy Grafana Alloy to tail log files
- [ ] Configure Loki with GCS backend
- [ ] Set up GCS backup/replication
- [ ] Build ModTools log viewer UI

### Phase 3: Loki Primary (Future)
- [ ] Verify Loki reliability over 3+ months
- [ ] Migrate historical logs from MySQL to Loki
- [ ] Disable MySQL logging (keep tables for audit compliance)
- [ ] Yesterday system reads from GCS

## Client-Side Tracing

Frontend logging with trace correlation allows grouping all client actions and backend API calls into a single traceable flow.

### Architecture

```
┌─────────────────────────────────────────────────────────────────────────────┐
│                           Nuxt3 Client                                      │
│                                                                             │
│  ┌─────────────────────────────────────────────────────────────────────┐   │
│  │  useTrace() composable                                               │   │
│  │  - Generates trace ID on: route change, modal open, user action     │   │
│  │  - Stores current trace ID in reactive state                        │   │
│  └──────────────────────────┬──────────────────────────────────────────┘   │
│                             │                                               │
│  ┌──────────────────────────▼──────────────────────────────────────────┐   │
│  │  useClientLog() composable                                           │   │
│  │  - Logs client events (page view, click, error)                     │   │
│  │  - Batches logs and sends to v2 API                                 │   │
│  └──────────────────────────┬──────────────────────────────────────────┘   │
│                             │                                               │
│  ┌──────────────────────────▼──────────────────────────────────────────┐   │
│  │  API layer (useAPI, $fetch)                                          │   │
│  │  - Adds X-Trace-ID header to all API requests                       │   │
│  │  - Adds X-Session-ID header (browser session)                       │   │
│  └──────────────────────────┬──────────────────────────────────────────┘   │
│                             │                                               │
│  ┌──────────────────────────▼──────────────────────────────────────────┐   │
│  │  Sentry Integration                                                  │   │
│  │  - Sets trace_id and session_id tags on all events                  │   │
│  │  - Enables correlation between Sentry errors and Loki logs          │   │
│  └─────────────────────────────────────────────────────────────────────┘   │
└─────────────────────────────────────────────────────────────────────────────┘
                                    │
                                    ▼
┌─────────────────────────────────────────────────────────────────────────────┐
│                           Backend APIs                                      │
│                                                                             │
│  Extract X-Trace-ID and X-Session-ID from request headers                  │
│  Include trace_id and session_id in all log entries                        │
│  Set Sentry tags when exceptions occur                                      │
│  Write logs to JSON files → Alloy ships to Loki                            │
└─────────────────────────────────────────────────────────────────────────────┘
                                    │
              ┌─────────────────────┼─────────────────────┐
              ▼                     ▼                     ▼
     ┌─────────────────┐   ┌─────────────────┐   ┌─────────────────┐
     │      Loki       │   │     Sentry      │   │     MySQL       │
     │                 │   │                 │   │                 │
     │ {trace_id="x"}  │◄──┤ trace_id:x      │   │ (audit trail)   │
     │ {session_id="y"}│   │ session_id:y    │   │                 │
     └─────────────────┘   └─────────────────┘   └─────────────────┘
```

### Trace and Session IDs

| ID | Scope | Generated When | Purpose |
|----|-------|----------------|---------|
| `session_id` | Browser session | Page load (stored in sessionStorage) | Group all activity in one browser session |
| `trace_id` | User interaction | Route change, modal open, form submit | Group related actions into one trace |

**Example flow:**
1. User opens Browse page → new `trace_id` generated
2. User clicks on a message → new `trace_id` generated
3. User opens chat modal → new `trace_id` generated
4. All API calls during that modal interaction share the same `trace_id`

### HTTP Headers

All API requests include these headers:

| Header | Value | Example |
|--------|-------|---------|
| `X-Trace-ID` | Current trace UUID | `a1b2c3d4-e5f6-7890-abcd-ef1234567890` |
| `X-Session-ID` | Browser session UUID | `11111111-2222-3333-4444-555555555555` |
| `X-Client-Timestamp` | ISO timestamp | `2025-12-15T14:30:00.123Z` |

### Client Log Events

The client logs these events to the v2 API:

| Event | Trigger | Data |
|-------|---------|------|
| `page_view` | Route change | path, referrer, viewport |
| `modal_open` | Modal opened | modal name, trigger element |
| `modal_close` | Modal closed | modal name, duration |
| `user_action` | Button click, form submit | action name, target |
| `error` | JavaScript error | message, stack, component |
| `performance` | Page load complete | LCP, FID, CLS metrics |

### Log Format (JSON)

All logs (client and server) use this JSON structure:

```json
{
  "ts": "2025-12-15T14:30:00.123Z",
  "level": "info",
  "source": "client",
  "trace_id": "a1b2c3d4-e5f6-7890-abcd-ef1234567890",
  "session_id": "11111111-2222-3333-4444-555555555555",
  "user_id": 12345,
  "event": "page_view",
  "data": {
    "path": "/browse",
    "referrer": "/",
    "viewport": "1920x1080"
  }
}
```

### Sentry Integration

Sentry events are tagged with trace and session IDs for correlation:

```javascript
// In Sentry configuration
Sentry.setTag('trace_id', traceId)
Sentry.setTag('session_id', sessionId)

// Construct Loki query URL for linking
const lokiUrl = `${GRAFANA_URL}/explore?left=["now-1h","now","Loki",{"expr":"{trace_id=\\"${traceId}\\"}"}]`
```

From Sentry, you can:
1. See `trace_id` tag on any error
2. Search for related errors: `trace_id:a1b2c3d4-*`
3. Click through to Loki to see full trace logs

### Querying Traces in Loki

```logql
# All logs for a specific trace
{app="freegle"} | json | trace_id="a1b2c3d4-e5f6-7890-abcd-ef1234567890"

# All traces for a user session
{app="freegle"} | json | session_id="11111111-2222-3333-4444-555555555555"

# Client-side errors with their traces
{source="client"} | json | event="error"

# Slow API calls with trace context
{source="api"} | json | duration_ms > 1000 | line_format "{{.trace_id}} {{.endpoint}} {{.duration_ms}}ms"
```

### Implementation Files

**Nuxt3 (iznik-nuxt3):**
- `composables/useTrace.js` - Trace ID generation and management
- `composables/useClientLog.js` - Client event logging
- `plugins/trace.client.js` - Initialize tracing on app start
- `plugins/sentry.client.js` - Updated with trace tags

**Go API (iznik-server-go):**
- `misc/trace.go` - Extract trace headers, include in logs

**PHP API (iznik-server):**
- `include/misc/Trace.php` - Extract trace headers
- `include/misc/Loki.php` - Updated to include trace IDs

## Loki + Sentry Integration

### Correlation via Trace IDs

Every request/interaction has a trace ID that appears in:
- Client logs (Loki)
- API logs (Loki)
- Sentry errors (as tag)

This enables:
- Click from Sentry error → see all related Loki logs
- See client action that triggered backend error
- Understand full user journey leading to issue

### Error Context Enrichment

When viewing a Sentry error:
1. Copy the `trace_id` tag value
2. Query Loki: `{app="freegle"} | json | trace_id="<value>"`
3. See timeline of client actions + API calls leading to error

### Grafana Dashboard Links

Configure Grafana data links to jump from Loki logs to Sentry:

```
Title: View in Sentry
URL: https://sentry.io/organizations/freegle/issues/?query=trace_id:${__value.raw}
```

### Proactive Issue Detection

Use Loki queries to detect anomalies and create alerts:

```logql
# High error rate alert
sum(rate({source="api"} | json | level="error" [5m])) > 10

# Slow response alert
avg(avg_over_time({source="api"} | json | unwrap duration_ms [5m])) > 500
```
