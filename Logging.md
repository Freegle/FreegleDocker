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

## Loki + Sentry Integration (Planned)

### Correlation via Trace IDs

- Generate unique request ID in API entry point
- Include in both Loki logs and Sentry events
- Link from Sentry error to related Loki logs

### Error Context Enrichment

When Sentry captures an error, query Loki for recent logs from same user/session and attach as context.

### Proactive Issue Detection

Use Loki queries to detect anomalies (high error rates, slow responses) and create Sentry issues programmatically.
