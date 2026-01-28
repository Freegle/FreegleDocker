# Loki Logging Consistency Review

## Components Reviewed

### 1. Go API (iznik-server-go/misc/loki.go)
- **Log file**: `go-api-YYYY-MM-DD.log`
- **Sources**: `api`, `api_headers`, `logs_table`, `client`, `chat_reply`

### 2. PHP API v1 (iznik-server/include/misc/Loki.php)
- **Log file**: `{source}.log` (e.g., `api.log`, `email.log`)
- **Sources**: `api`, `api_headers`, `email`, `logs_table`

### 3. Laravel Batch (iznik-batch/app/Services/LokiService.php)
- **Log file**: `{source}.log` (e.g., `batch.log`, `email.log`)
- **Sources**: `batch`, `email`, `batch_event`

---

## Consistency Analysis

### ✅ Consistent Elements

| Element | Go | PHP v1 | Batch |
|---------|-----|--------|-------|
| `app=freegle` label | ✓ | ✓ | ✓ |
| JSON file format | ✓ | ✓ | ✓ |
| Timestamp in RFC3339 | ✓ | ✓ | ✓ |
| String truncation (32 chars) | ✓ | ✓ | N/A |
| Sensitive header filtering | ✓ | ✓ | N/A |
| Email hashing for privacy | N/A | ✓ | ✓ |

### ⚠️ Inconsistencies

#### 1. Log Level Handling
- **Go**: Uses `level` label (info for 2xx-4xx, error for 5xx)
- **PHP v1**: No level label on API logs
- **Batch**: No level label
- **Impact**: Cannot filter by severity across all sources

#### 2. Trace Correlation Headers
- **Go**: Supports `x-freegle-session`, `x-freegle-page`, `x-freegle-modal`, `x-freegle-site`
- **PHP v1**: Same headers + legacy `x-trace-id`, `x-session-id`
- **Batch**: Only `trace_id` in email logs
- **Impact**: Limited end-to-end request tracing

#### 3. Duration Field
- **Go**: `duration_ms` (float)
- **PHP v1**: `duration_ms` (float)
- **Batch**: `duration_seconds` (for batch jobs)
- **Impact**: Inconsistent units for performance analysis

#### 4. User ID Placement
- **Go**: Label + message body
- **PHP v1**: Label + message body
- **Batch**: Label (some sources) + message body
- **Impact**: Minor - mostly consistent

---

## Missing Activities for End-to-End Tracing

### High Priority
1. **User authentication events**
   - Login success/failure
   - Session creation/destruction
   - Password reset requests

2. **Email engagement**
   - Opens (via tracking pixel)
   - Clicks (via tracking URLs)
   - Bounces/complaints

3. **Chat activity**
   - Message sent (website)
   - Message sent (AMP reply) - Go has this
   - Message read

### Medium Priority
4. **Database writes**
   - Currently only reads are logged (API requests)
   - Critical mutations (user updates, message posts) not traced

5. **Background job queue**
   - Job dispatched
   - Job processed (Task #1 adds this)
   - Job failed/retried

6. **Moderation actions**
   - Message approved/rejected
   - User banned/unbanned
   - Spam flagged

### Lower Priority
7. **Search operations**
   - Search queries (performance tracking)
   - No results searches (UX improvement data)

8. **External service calls**
   - MJML compilation
   - Geocoding requests
   - Image processing

---

## Recommendations

### Immediate Actions

1. **Add `level` label to PHP v1 and Batch logs**
   ```php
   // PHP v1
   $level = $statusCode >= 500 ? 'error' : 'info';
   $labels['level'] = $level;
   ```

2. **Standardize duration units**
   - Use `duration_ms` everywhere for consistency
   - Update Batch `LogsBatchJob` trait to use milliseconds

3. **Add batch job logging via trait**
   - ✅ Completed in Task #1

### Short-term Actions

4. **Add authentication event logging**
   - Log to `source=auth` stream
   - Include: event type, user_id, ip, user_agent, success/failure

5. **Add email engagement to Loki**
   - Currently tracked in `email_tracking_pixel_opens` table
   - Dual-write to Loki for unified observability

### Long-term Actions

6. **Implement distributed tracing**
   - Standardize on `x-freegle-trace-id` header
   - Pass trace ID through all services
   - Include in all log entries

7. **Add write operation logging**
   - Create `source=mutations` stream
   - Log significant database changes

---

## Dashboard Queries

With these improvements, you could build dashboards like:

```logql
# Request latency by endpoint
{app="freegle", source="api"} | json | duration_ms > 1000

# Failed batch jobs
{app="freegle", source="batch", event="failed"}

# User journey trace
{app="freegle", trace_id="abc123"}

# Email engagement funnel
rate({app="freegle", source="email", event=~"sent|opened|clicked"}[1h])
```

---

## Implementation Status

| Improvement | Status | Notes |
|-------------|--------|-------|
| Batch job logging trait | ✅ Done | Task #1 |
| Level label standardization | ⬜ TODO | |
| Duration unit standardization | ⬜ TODO | |
| Auth event logging | ⬜ TODO | |
| Email engagement logging | ⬜ TODO | |
| Distributed tracing | ⬜ TODO | Requires frontend changes |
