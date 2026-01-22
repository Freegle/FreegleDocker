# V1 to V2 API Migration

## Overview

Migration from PHP v1 API to Go v2 API for Freegle (FD) and ModTools (MT).

### Key Architecture Change: Nested Objects vs IDs

**V1 Pattern (PHP):** Returns large nested objects in single calls
```json
{
  "message": {
    "id": 123,
    "fromuser": { "id": 456, "displayname": "John", "email": "..." },
    "location": { "id": 789, "name": "London", "lat": 51.5, "lng": -0.1 },
    "groups": [{ "id": 101, "nameshort": "FreegleLondon", ... }]
  }
}
```

**V2 Pattern (Go):** Returns IDs for client-side caching, parallel fetches for nested data
```json
{
  "id": 123,
  "fromuser": 456,
  "location": { "id": 789, "name": "London", "lat": 51.5, "lng": -0.1 },
  "groups": [{ "groupid": 101, "arrival": "2024-01-15", "collection": "freegle" }]
}
```

**Key Differences:**
- V2 uses goroutines for parallel data fetching (reduces latency)
- V2 applies privacy filtering (hides emails/phones from non-owners)
- V2 computes derived fields (image paths, counts, flags)
- Client caches entities by ID, reducing redundant fetches

---

## Task Status

Each row is designed as a single RALPH task. Status legend:
- `⬜` Pending - not started
- `🔄` Partial - Go backend done, awaiting frontend changes
- `⏳` Waiting - deployed, awaiting CI/verification
- `✅` Complete - fully migrated and v1 deprecated

### Phase 0: Non-Email Endpoints (Current Focus)

| # | Endpoint | Verbs | Status | RALPH Task | Notes |
|---|----------|-------|--------|------------|-------|
| 1 | /job | GET, POST | ✅ Complete | - | Completed 2025-09-30 |
| 2 | /donations | GET | ✅ Complete | - | Completed 2025-10-01 |
| 3 | /giftaid | GET | ✅ Complete | - | Completed 2025-10-13 |
| 4 | /logo | GET | ✅ Complete | - | Completed 2025-10-13 |
| 5 | /microvolunteering | GET | ✅ Complete | - | Completed 2025-10-14 |
| 6 | /user/byemail | GET | ✅ Complete | - | Completed 2025-10-17 |
| 7 | /authority | GET | 🔄 Partial | `Update FD+MT to use /authority v2` | Go done, needs client changes |
| 8 | /address | PATCH, PUT | ⬜ Pending | `Migrate /address write ops to v2` | 5 FD usages |
| 9 | /isochrone | PUT, POST, PATCH | ⬜ Pending | `Migrate /isochrone write ops to v2` | 2 FD usages |
| 10 | /newsfeed | POST | ⬜ Pending | `Migrate /newsfeed POST to v2` | 10 FD usages |
| 11 | /notification | POST | ⬜ Pending | `Migrate /notification POST to v2` | 3 FD usages |
| 12 | /volunteering | POST, PATCH, DELETE | ⬜ Pending | `Migrate /volunteering write ops to v2` | 5 FD usages |
| 13 | /image | POST | ⬜ Pending | `Migrate /image POST to v2` | File upload support needed |
| 14 | /messages | POST (MarkSeen) | ⬜ Pending | `Migrate /messages MarkSeen to v2` | DB write only |

### Phase 0.5: MT Endpoints Using V1 (V2 Already Exists)

These ModTools endpoints use v1 but v2 GET already exists - easy wins:

| # | Endpoint | MT Usage | Status | RALPH Task |
|---|----------|----------|--------|------------|
| 15 | /chat | 16 v1 calls | 🔄 Partial | `Switch MT chat GETs to v2` |
| 16 | /config | 1 v1 call | 🔄 Partial | `Switch MT config to v2` |
| 17 | /location | 5 v1 calls | 🔄 Partial | `Switch MT location GETs to v2` |
| 18 | /story | 8 v1 calls | 🔄 Partial | `Switch MT story GETs to v2` |

---

## Email Queue Design (Unblocks Phase 1)

### Problem
Go v2 API cannot send emails directly. Previously, this blocked migration of all email-sending endpoints.

### Solution: Laravel Database Queue

Go writes minimal data to a queue table; Laravel processes and sends emails asynchronously.

```
┌─────────────┐     ┌──────────────────┐     ┌─────────────────┐
│  Go v2 API  │ ──▶ │ email_queue table│ ──▶ │ Laravel artisan │
│  (writes)   │     │   (IDs only)     │     │ mail:queue:process│
└─────────────┘     └──────────────────┘     └─────────────────┘
```

### Queue Table Schema

```sql
CREATE TABLE email_queue (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    email_type VARCHAR(50) NOT NULL,      -- 'welcome', 'verify', 'forgot_password', etc.
    user_id BIGINT UNSIGNED NULL,
    group_id BIGINT UNSIGNED NULL,
    message_id BIGINT UNSIGNED NULL,
    chat_id BIGINT UNSIGNED NULL,
    extra_data JSON NULL,                  -- Minimal additional IDs if needed
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    processed_at TIMESTAMP NULL,
    failed_at TIMESTAMP NULL,
    error_message TEXT NULL,
    INDEX idx_pending (processed_at, created_at),
    INDEX idx_type (email_type)
);
```

### Go Side: Queue Entry

```go
// In iznik-server-go/email/queue.go
func QueueEmail(emailType string, userID, groupID, messageID uint64, extra map[string]interface{}) error {
    extraJSON, _ := json.Marshal(extra)
    _, err := db.Exec(`
        INSERT INTO email_queue (email_type, user_id, group_id, message_id, extra_data)
        VALUES (?, ?, ?, ?, ?)
    `, emailType, userID, groupID, messageID, extraJSON)
    return err
}

// Usage in session.go
func ForgotPassword(c *fiber.Ctx) error {
    // ... validate email, find user ...
    email.QueueEmail("forgot_password", user.ID, 0, 0, nil)
    return c.JSON(fiber.Map{"ret": 0})
}
```

### Laravel Side: Queue Processor

```php
// In iznik-batch/app/Console/Commands/Mail/ProcessEmailQueueCommand.php
class ProcessEmailQueueCommand extends Command {
    protected $signature = 'mail:queue:process {--limit=100} {--dry-run}';

    public function handle() {
        $pending = DB::table('email_queue')
            ->whereNull('processed_at')
            ->whereNull('failed_at')
            ->orderBy('created_at')
            ->limit($this->option('limit'))
            ->get();

        foreach ($pending as $item) {
            try {
                $this->processEmail($item);
                DB::table('email_queue')
                    ->where('id', $item->id)
                    ->update(['processed_at' => now()]);
            } catch (\Exception $e) {
                DB::table('email_queue')
                    ->where('id', $item->id)
                    ->update(['failed_at' => now(), 'error_message' => $e->getMessage()]);
            }
        }
    }

    private function processEmail($item) {
        switch ($item->email_type) {
            case 'forgot_password':
                $user = User::find($item->user_id);
                Mail::send(new ForgotPasswordMail($user));
                break;
            case 'welcome':
                $user = User::find($item->user_id);
                Mail::send(new WelcomeMail($user));
                break;
            // ... other types
        }
    }
}
```

### Cron Schedule

```bash
# Process email queue every minute
* * * * * cd /path/to/iznik-batch && php artisan mail:queue:process --limit=50
```

### Email Types for API Migration

| Email Type | Triggered By | Queue Data | Laravel Mailable |
|------------|--------------|------------|------------------|
| `forgot_password` | POST /session (LostPassword) | user_id | ForgotPasswordMail |
| `verify_email` | PATCH /user | user_id, email | VerifyEmailMail |
| `welcome` | PUT /user, POST /message (JoinAndPost) | user_id | WelcomeMail (exists) |
| `unsubscribe` | POST /session (Unsubscribe) | user_id | UnsubscribeMail |
| `merge_offer` | PUT /merge | user_id, merge_user_id | MergeOfferMail |
| `modmail` | POST /user, /memberships, /message | user_id, group_id, subject, body | ModMail |

### Migration Dependency

Before migrating an email-sending endpoint to v2:
1. Create the Laravel Mailable class in iznik-batch
2. Add the email type handler to ProcessEmailQueueCommand
3. Test the Laravel email path works
4. Then implement the Go v2 endpoint using QueueEmail()

---

## Phase 1: Email-Dependent Endpoints

These can be migrated once the email queue is implemented:

| # | Endpoint | Email Types | Status | RALPH Task |
|---|----------|-------------|--------|------------|
| 19 | /session | forgot_password, unsubscribe | ⬜ Pending | `Migrate /session to v2 with email queue` |
| 20 | /user | welcome, verify_email | ⬜ Pending | `Migrate /user write ops to v2` |
| 21 | /memberships | modmail | ⬜ Pending | `Migrate /memberships to v2` |
| 22 | /message | modmail, welcome | ⬜ Pending | `Migrate /message write ops to v2` |
| 23 | /chatmessages | chat_notification | ⬜ Pending | `Migrate /chatmessages POST to v2` |
| 24 | /merge | merge_offer | ⬜ Pending | `Migrate /merge to v2` |
| 25 | /invitation | invitation | ⬜ Pending | `Migrate /invitation to v2` |

### Prerequisites for Phase 1

- [ ] Create `email_queue` table migration
- [ ] Implement `QueueEmail()` in Go
- [ ] Create `ProcessEmailQueueCommand` in Laravel
- [ ] Migrate these Laravel Mailables (if not exists):
  - [ ] ForgotPasswordMail
  - [ ] VerifyEmailMail
  - [ ] UnsubscribeMail
  - [ ] MergeOfferMail
  - [ ] ModMail (generic moderator message)

---

## Phase 2: MT-Specific Endpoints

| # | Endpoint | Verbs | Status | RALPH Task |
|---|----------|-------|--------|------------|
| 26 | /modconfig | GET, PATCH, POST, DELETE | ⬜ Pending | `Migrate /modconfig to v2` |
| 27 | /stdmsg | GET, PATCH, POST, DELETE | ⬜ Pending | `Migrate /stdmsg to v2` |
| 28 | /spammers | All | ⬜ Pending | `Migrate /spammers to v2` |
| 29 | /bulkop | POST | ⬜ Pending | `Migrate /bulkop to v2` |

---

## RALPH Task Format

Each task should be invoked as:
```bash
/ralph "Migrate /endpoint to v2 API"
```

### Task Checklist (Auto-applied by RALPH)

1. **Read coding standards** - Check `iznik-server-go/CLAUDE.md`
2. **Analyze v1 implementation** - Read PHP endpoint, identify data/email needs
3. **Check if email queue needed** - If yes, ensure Laravel Mailable exists
4. **Implement v2 Go handler** with tests
5. **Wait for CI** - All 4 test suites must pass
6. **Wait for deployment** - Verify in production Swagger
7. **Update FD client** - Change `$get` to `$getv2` etc.
8. **Update MT client** - If applicable
9. **Mark v1 deprecated** - Add comment with date
10. **Update this document** - Change status, add completion date

### Example RALPH Task

```
Migrate /session LostPassword to v2 API

Prerequisites:
- ForgotPasswordMail exists in iznik-batch (check app/Mail/)
- email_queue table exists
- ProcessEmailQueueCommand handles 'forgot_password' type

Steps:
1. Read iznik-server/http/api/session.php LostPassword action
2. Implement POST /session in iznik-server-go/session/session.go
3. Use email.QueueEmail("forgot_password", userID, 0, 0, nil)
4. Add tests in iznik-server-go/test/session_test.go
5. Push and wait for CI green
6. Wait for production deployment
7. Update iznik-nuxt3/api/SessionAPI.js to use $postv2
8. Add deprecation comment to session.php
9. Update migration plan status
```

---

## V1 vs V2 Transformation Patterns

### Pattern 1: Simple Data (No Nesting)
```go
// job.go - Direct response, no transformation needed
func GetJob(c *fiber.Ctx) error {
    var job Job
    db.Raw("SELECT ... FROM jobs WHERE id = ?", id).Scan(&job)
    return c.JSON(job)
}
```

### Pattern 2: Nested Objects with Parallel Fetch
```go
// authority.go - Build nested response from multiple queries
func Single(c *fiber.Ctx) error {
    var wg sync.WaitGroup
    var authority AuthorityBase
    var groups []Group

    wg.Add(2)
    go func() { db.Raw("SELECT ... FROM authorities").Scan(&authority); wg.Done() }()
    go func() { db.Raw("SELECT ... FROM groups").Scan(&groups); wg.Done() }()
    wg.Wait()

    return c.JSON(Authority{
        ID: authority.ID,
        Centre: Centre{Lat: authority.Lat, Lng: authority.Lng},  // Nested
        Groups: groups,  // Array of objects
    })
}
```

### Pattern 3: Privacy Filtering
```go
// message.go - Different data for owner vs others
func GetMessage(c *fiber.Ctx) error {
    myid := user.WhoAmI(c)
    var msg Message
    db.Raw("SELECT ... FROM messages").Scan(&msg)

    if msg.Fromuser != myid {
        msg.Textbody = hideEmails(msg.Textbody)
        msg.Textbody = hidePhones(msg.Textbody)
    }
    return c.JSON(msg)
}
```

---

## Migration Procedure

**IMPORTANT**: A migration is NOT complete until BOTH backend AND frontend changes are done.

### 1. Implement v2 Go API
- Create handler in `iznik-server-go/{domain}/{domain}.go`
- Add route in `iznik-server-go/router/routes.go` with Swagger annotations
- Regenerate Swagger: `./generate-swagger.sh`

### 2. Add Tests
- Add test functions to `iznik-server-go/test/{domain}_test.go`
- Run: `docker exec freegle-apiv2 go test ./test/...`

### 3. Wait for CI (CRITICAL)
- Push and wait for CircleCI pipeline
- All four suites must pass: Go, PHPUnit, Laravel, Playwright

### 4. Wait for Deployment (CRITICAL)
- Verify endpoint in production Swagger: `https://apiv2.ilovefreegle.org/swagger/`
- Mark status as ⏳ Waiting until confirmed

### 5. Update Client Code
- FD: `iznik-nuxt3/api/{Domain}API.js` - `$get` → `$getv2`
- MT: `iznik-nuxt3-modtools/api/{Domain}API.js`

### 6. Mark v1 Deprecated
```php
// TODO: DEPRECATED - Migrated to v2 Go API
// Migrated: YYYY-MM-DD
// V2 endpoints: /endpoint (GET, POST)
```

---

## Statistics

- **FD API usage**: 70 unique endpoints, 235 total API calls
- **MT API usage**: 70 unique endpoints, 266 total API calls
- **Total v1 endpoints**: 58 PHP files
- **MT v1 calls with v2 available**: 30 (easy wins)
- **Email-dependent endpoints**: 10 (unblocked by queue design)

---

## Analysis Scripts

Re-run API usage analysis:
```bash
cd /home/edward/FreegleDockerWSL
./plans/scripts/run-parallel-api-analysis.sh
```

---

## Complete PHP Endpoint List

All 58 endpoints in `/iznik-server/http/api/`:

```
abtest.php       authority.php    bulkop.php       changes.php      chatmessages.php
chatrooms.php    comment.php      communityevent.php config.php     dashboard.php
domains.php      donations.php    error.php        export.php       giftaid.php
group.php        groups.php       image.php        invitation.php   isochrone.php
item.php         jobs.php         locations.php    logo.php         logs.php
memberships.php  mentions.php     merge.php        message.php      messages.php
microvolunteering.php modconfig.php newsfeed.php   notification.php noticeboard.php
poll.php         profile.php      request.php      session.php      shortlink.php
socialactions.php spammers.php    src.php          status.php       stdmsg.php
stories.php      stripecreateintent.php stripecreatesubscription.php
team.php         tryst.php        user.php         usersearch.php   visualise.php
volunteering.php
```
