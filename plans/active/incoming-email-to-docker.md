# Incoming Email Migration to Docker (Consolidated Plan)

**Status**: Planning
**Branch**: `feature/incoming-email-migration`
**Supersedes**: `plans/active/incoming-email-migration-to-laravel.md` (to be archived after implementation)

## Executive Summary

This plan consolidates the incoming email migration strategy, covering:
1. **Mail reception** - Postfix container in Docker receiving via MX records
2. **Processing** - Laravel commands in iznik-batch handling routing, spam, bounces
3. **Monitoring** - ModTools UI for email statistics and queue status
4. **Archiving** - MailPit-style mail viewer for production debugging
5. **Switchover** - Phased migration from Exim/iznik-server to Postfix/iznik-batch
6. **Retirement** - Removing code from iznik-server after stable operation

---

## Part 1: Architecture Decision - Postfix as Buffer

### Current State (Bulk4)
- **MX records** point to bulk4.ilovefreegle.org
- **Exim 4** receives mail on port 25
- **Pipe transport** calls `incoming.php` for each message
- **Processing** via MailRouter in iznik-server

### Options Considered

| Option | Pros | Cons |
|--------|------|------|
| **A: Direct HTTP endpoint** | Simpler, no MTA | No buffering during outages, need custom SMTP server |
| **B: Postfix container in Docker** | Battle-tested MTA, queuing, standard protocols | Another service to maintain |
| **C: External relay (SES, Mailgun)** | Managed, reliable | User requirement to avoid third-party |

### Decision: Option B - Postfix Container

**Rationale:**
1. **Buffering** - If iznik-batch is restarting or overloaded, Postfix queues mail safely
2. **Proven** - Postfix is battle-tested for decades
3. **Standard** - Uses standard SMTP, easy to monitor, well-documented
4. **Already planned** - The existing plan already proposes this approach
5. **Mail archiving** - Postfix `always_bcc` easily archives all mail to MailPit

### Single vs Dual Postfix Architecture

**Question**: Should we use a single postfix instance for both incoming and outgoing mail, or separate instances?

#### Traffic Characteristics

| Direction | Volume | Importance | Latency Tolerance |
|-----------|--------|------------|-------------------|
| **Outgoing** | High (notifications, digests, 10k+/day) | Medium | Minutes acceptable |
| **Incoming** | Low (replies, posts, ~1k/day) | **High** (user messages) | Seconds preferred |

#### Analysis: Separate Instances (Recommended)

**Advantages:**
1. **Incoming prioritization** - Dedicated queue ensures user messages aren't delayed by outgoing batch
2. **Independent scaling** - Can tune resources separately
3. **Isolation** - Outgoing delivery issues don't affect incoming reception
4. **Monitoring clarity** - Separate queue depths for each direction
5. **Different configurations** - Incoming needs strict spam checks, outgoing needs delivery optimization

**Disadvantages:**
1. Two services to maintain
2. Slightly more resource usage

#### Architecture: Dual Postfix

```yaml
# Incoming mail - receives from internet, pipes to Laravel
postfix-incoming:
  ports:
    - "25:25"      # SMTP from internet
  environment:
    - INSTANCE_TYPE=incoming
  # Receives mail → pipes to artisan mail:incoming

# Outgoing mail - receives from Laravel, delivers to internet
postfix-outgoing:
  ports:
    - "587:587"    # Submission from internal services only (not exposed to internet)
  environment:
    - INSTANCE_TYPE=outgoing
  # Receives mail from Laravel Mail → delivers externally
```

#### Prioritization Without Dual Postfix

If using single instance, Postfix has limited prioritization options:
- `defer_transports` can delay certain transports
- But no true priority queuing
- Backpressure from outgoing would still delay incoming

**Recommendation: Use dual postfix instances** - the operational clarity and incoming prioritization outweighs the minor complexity increase.

### Postfix Container Design (Incoming)

```yaml
# docker-compose.yml addition
postfix-incoming:
  build:
    context: ./conf/postfix-incoming
    dockerfile: Dockerfile
  container_name: freegle-postfix-incoming
  hostname: mail.ilovefreegle.org
  ports:
    - "25:25"     # SMTP from internet
  volumes:
    - postfix-incoming-spool:/var/spool/postfix
    - ./conf/postfix-incoming/main.cf:/etc/postfix/main.cf:ro
    - ./conf/postfix-incoming/master.cf:/etc/postfix/master.cf:ro
    - ./conf/postfix-incoming/transport:/etc/postfix/transport:ro
  environment:
    - MAILPIT_HOST=mailpit
    - BATCH_CONTAINER=batch
  networks:
    - default
  restart: unless-stopped
  depends_on:
    - batch
    - mailpit
  profiles:
    - production  # Only runs in production profile
```

### Key Configuration

**main.cf** (core settings):
```
# Domains we accept mail for
mydestination =
relay_domains = ilovefreegle.org, groups.ilovefreegle.org, users.ilovefreegle.org, user.trashnothing.com

# Transport to Laravel
transport_maps = hash:/etc/postfix/transport

# Concurrency limits - max 4 parallel deliveries to Laravel
freegle_destination_concurrency_limit = 4
default_process_limit = 50

# Archive all mail to MailPit for debugging
always_bcc = archive@mailpit
```

**master.cf** (transport definition):
```
# Pipe to Laravel artisan command
freegle unix - n n - 4 pipe
  flags=F user=www-data argv=/usr/bin/php /app/artisan mail:incoming ${sender} ${recipient}
```

**transport** (domain routing):
```
groups.ilovefreegle.org    freegle:
users.ilovefreegle.org     freegle:
user.trashnothing.com      freegle:
ilovefreegle.org           freegle:
```

---

## Part 2: Laravel Command Structure

### Entry Point: `mail:incoming`

```php
// app/Console/Commands/Mail/IncomingMailCommand.php
class IncomingMailCommand extends Command
{
    protected $signature = 'mail:incoming {sender} {recipient}';
    protected $description = 'Process incoming email from Postfix';

    public function handle(IncomingMailService $service): int
    {
        $sender = $this->argument('sender');
        $recipient = $this->argument('recipient');
        $rawEmail = file_get_contents('php://stdin');

        try {
            $result = $service->process($sender, $recipient, $rawEmail);
            $this->logToLoki($result);
            return Command::SUCCESS;
        } catch (\Exception $e) {
            Sentry::captureException($e);
            return Command::FAILURE;
        }
    }
}
```

### Service Structure

```
app/Services/Mail/Incoming/
├── IncomingMailService.php      # Main orchestrator
├── MailParserService.php        # MIME parsing (php-mime-mail-parser)
├── MailRouterService.php        # Routing logic (11 outcomes)
├── BounceService.php            # DSN parsing and processing
├── FBLService.php               # Feedback loop processing
├── SpamCheckService.php         # Dual SpamD + custom checks
├── FreegleSpamService.php       # Freegle-specific spam detection
├── ContentModerationService.php # Worry words + spam keywords
├── TrashNothingService.php      # TN header validation
├── EmailCommandService.php      # Subscribe/unsubscribe commands
└── AttachmentService.php        # TUSD upload handling
```

### Dual Spam Detection Approach

Freegle uses two complementary spam detection systems:

#### 1. SpamAssassin (SpamD)
- External daemon for content-based spam detection
- Connected via `lib/spamc.php` client library
- **Threshold**: Score >= 8 triggers spam classification
- Configuration via `SPAMD_HOST` and `SPAMD_PORT`
- Only used for content checks when subject not in standard format

#### 2. Custom Freegle Spam Checks (`Spam.php`)
- IP reputation checks (multiple users/groups from same IP)
- Subject duplication across groups (threshold: 30 groups)
- Country blocking (configurable)
- Greeting spam detection ("hello", "hey", etc. patterns)
- Domain blacklist (DBL) URL checks
- Known spam keywords
- Worry words detection
- Bulk volunteer mail detection
- Our domain spoofing detection
- Same image sent multiple times

#### Laravel Implementation

```php
class SpamCheckService
{
    private const SPAMASSASSIN_THRESHOLD = 8;

    public function __construct(
        private SpamDClient $spamd,
        private FreegleSpamService $freegleSpam
    ) {}

    public function check(ParsedEmail $email, bool $contentCheck = true): SpamResult
    {
        // 1. Run Freegle-specific checks first (faster, no network call)
        $freegleResult = $this->freegleSpam->checkMessage($email);
        if ($freegleResult->isSpam()) {
            return $freegleResult;
        }

        // 2. Run SpamAssassin for content check if enabled
        if ($contentCheck && !$this->hasStandardSubjectFormat($email)) {
            $saResult = $this->spamd->check($email->getRawMessage());
            if ($saResult->score >= self::SPAMASSASSIN_THRESHOLD) {
                return SpamResult::spam(SpamReason::SPAMASSASSIN, $saResult->score);
            }
        }

        return SpamResult::notSpam();
    }
}

class FreegleSpamService
{
    // Port from Spam.php - all custom checks
    public function checkMessage(ParsedEmail $email): SpamResult;
    public function checkIpReputation(string $ip): SpamResult;
    public function checkSubjectDuplication(string $subject): SpamResult;
    public function checkCountry(string $ip): SpamResult;
    public function checkGreetingSpam(string $body): SpamResult;
    public function checkDbl(array $urls): SpamResult;
    public function checkSpamKeywords(string $text): SpamResult;
    public function checkWorryWords(string $text): SpamResult;
}
```

#### SpamD Container (Docker)

```yaml
# docker-compose.yml addition
spamd:
  image: instantlinux/spamassassin:latest
  container_name: freegle-spamd
  volumes:
    - spamd-data:/var/lib/spamassassin
  environment:
    - SA_UPDATE_CRON=0 4 * * *  # Update rules at 4am
  networks:
    - default
  restart: unless-stopped
  profiles:
    - production
```

### Routing Outcomes (from MailRouter)

| Outcome | Description | Action |
|---------|-------------|--------|
| FAILURE | Could not process | Log error, return failure |
| INCOMING_SPAM | Detected as spam | Log, optionally store for review |
| APPROVED | Auto-approved for posting | Create message, notify |
| PENDING | Needs moderation | Create message, notify mods |
| TO_USER | Chat message | Route to chat system |
| TO_SYSTEM | System command | Process command |
| RECEIPT | Read receipt | Update chat message status |
| TRYST | Calendar response | Process meeting response |
| DROPPED | Silently dropped | Log only |
| TO_VOLUNTEERS | To moderators | Route to mod mail |

---

## Part 3: Bounce and FBL Processing

### Current Bounce Flow (VERP-based)
1. Outgoing mail uses `bounce-{userid}-{timestamp}@users.ilovefreegle.org`
2. Bounces arrive at that address
3. `bounce.php` cron extracts userid from address
4. Records in `bounces_emails` table
5. `bounce_users.php` suspends users with 3+ permanent bounces

### New Bounce Flow (DSN parsing)
1. Bounces arrive at `noreply@ilovefreegle.org` (new no-reply address)
2. `BounceService` detects DSN format or heuristic bounce patterns
3. Extracts original recipient from DSN `Final-Recipient` or body parsing
4. Records bounce and marks email as bounced
5. Scheduled command suspends users with threshold bounces

### BounceService Key Methods

```php
class BounceService
{
    // Detection
    public function isBounce(ParsedEmail $email): bool;
    public function isDsnCompliant(ParsedEmail $email): bool;

    // Extraction (cascading strategy)
    public function extractRecipient(string $rawMessage): ?string;
    private function extractFromDsn(string $rawMessage): ?string;
    private function extractHeuristically(string $rawMessage): ?string;
    private function extractFromOriginalMessage(ParsedEmail $email): ?string;

    // Classification
    public function isPermanent(?string $diagnosticCode): bool;
    public function shouldIgnore(?string $diagnosticCode): bool;

    // Processing
    public function processBounce(ParsedEmail $email): ?BounceResult;
}
```

### FBL Processing

FBL reports (when users mark our mail as spam) arrive at `fbl@users.ilovefreegle.org`.

```php
class FBLService
{
    public function isFBL(ParsedEmail $email): bool;
    public function processFBL(ParsedEmail $email): ?FBLResult;
}
```

Action: Set user's `simple_mail = NONE` to stop all email.

---

## Part 4: Spam Review UI in ModTools

### Current Problem
Currently, incoming mail classified as spam is simply discarded with no ability to review or recover false positives.

### Solution: Spam Review Queue

Store suspected spam for review instead of discarding:

```php
// Database table for spam queue
// Migration: create_incoming_spam_queue_table
Schema::create('incoming_spam_queue', function (Blueprint $table) {
    $table->id();
    $table->text('raw_message');          // Full RFC2822 message
    $table->string('envelope_from');
    $table->string('envelope_to');
    $table->string('from_address');
    $table->string('subject');
    $table->string('spam_reason');         // SpamAssassin, GreetingSpam, etc.
    $table->decimal('spam_score', 5, 2)->nullable();
    $table->json('spam_details')->nullable(); // Additional detection info
    $table->timestamp('received_at');
    $table->unsignedBigInteger('reviewed_by')->nullable();
    $table->enum('status', ['pending', 'approved', 'rejected'])->default('pending');
    $table->timestamp('reviewed_at')->nullable();
    $table->timestamps();

    $table->index(['status', 'received_at']);
    $table->index('envelope_to');
});
```

### API Endpoints

```
GET /api/v2/mail/spam-queue
    ?status=pending          # Filter by status
    &page=1                  # Pagination
    &per_page=20

GET /api/v2/mail/spam-queue/{id}
    Returns full message details including parsed headers, body preview

POST /api/v2/mail/spam-queue/{id}/approve
    Releases message from spam queue for delivery
    Creates whitelist entry if requested

POST /api/v2/mail/spam-queue/{id}/reject
    Marks as confirmed spam (for training)
    Optionally adds to blacklist

DELETE /api/v2/mail/spam-queue/purge
    Removes messages older than N days (default 7)
```

### ModTools Spam Review Component

```vue
<!-- modtools/components/ModSpamQueue.vue -->
<template>
  <div class="spam-queue">
    <h4>Incoming Spam Review</h4>

    <!-- Stats summary -->
    <div class="queue-stats">
      <span class="stat pending">{{ pendingCount }} pending</span>
      <span class="stat approved">{{ approvedToday }} approved today</span>
      <span class="stat rejected">{{ rejectedToday }} rejected today</span>
    </div>

    <!-- Filter tabs -->
    <b-tabs v-model="activeTab">
      <b-tab title="Pending Review" />
      <b-tab title="Recently Approved" />
      <b-tab title="Recently Rejected" />
    </b-tabs>

    <!-- Message list -->
    <div class="message-list">
      <div v-for="msg in messages" :key="msg.id" class="spam-item">
        <div class="spam-header">
          <span class="from">{{ msg.from_address }}</span>
          <span class="reason badge" :class="reasonClass(msg.spam_reason)">
            {{ msg.spam_reason }}
          </span>
          <span v-if="msg.spam_score" class="score">
            Score: {{ msg.spam_score }}
          </span>
        </div>
        <div class="spam-subject">{{ msg.subject }}</div>
        <div class="spam-preview">{{ msg.body_preview }}</div>
        <div class="spam-actions">
          <b-button variant="success" size="sm" @click="approve(msg.id)">
            Approve & Deliver
          </b-button>
          <b-button variant="danger" size="sm" @click="reject(msg.id)">
            Confirm Spam
          </b-button>
          <b-button variant="secondary" size="sm" @click="showDetails(msg)">
            View Full
          </b-button>
        </div>
      </div>
    </div>

    <!-- Detail modal -->
    <b-modal v-model="showDetailModal" size="xl" title="Spam Message Details">
      <div v-if="selectedMessage">
        <h5>Headers</h5>
        <pre class="headers">{{ selectedMessage.headers }}</pre>
        <h5>Body</h5>
        <pre class="body">{{ selectedMessage.body }}</pre>
        <h5>Spam Analysis</h5>
        <pre class="analysis">{{ JSON.stringify(selectedMessage.spam_details, null, 2) }}</pre>
      </div>
    </b-modal>
  </div>
</template>
```

### Whitelist Management

When approving spam, offer to whitelist:
- **Sender address**: `spam_whitelist_addresses` table
- **Sender IP**: `spam_whitelist_ips` table
- **Subject pattern**: `spam_whitelist_subjects` table

### Retention Policy

- **Pending messages**: 7 days, then auto-purge
- **Approved messages**: Delete immediately after delivery (logged)
- **Rejected messages**: 30 days for potential blacklist training

### Access Control

- All volunteers (moderators) can view the spam queue
- Support/Admin users can approve/reject spam
- All actions logged with user ID and timestamp
- Sentry alerts for high false positive rates

### UI Placement in ModTools

Add to left sidebar under Messages section:
- **Menu item**: "Incoming Spam" with blue badge showing pending count
- **Badge**: Shows pending spam count (blue, like other notification counts)
- **Purpose**: Shows volunteers the value of spam filtering without requiring action
- **Retention**: 7 days - auto-purged if not reviewed

```vue
<!-- In LeftMenu.vue Messages section -->
<MenuOption
  v-if="hasModeratorRole"
  name="Incoming Spam"
  icon="shield-alt"
  :badge="spamQueueCount"
  badge-variant="primary"
  :to="'/modtools/support/spam'"
/>
```

This placement:
1. Demonstrates the system's value by showing blocked spam
2. Allows curious moderators to review if they choose
3. Auto-purges after 7 days so no action required
4. Blue badge matches existing notification style

---

## Part 5: ModTools Email Statistics

### API Endpoint

`GET /api/v2/mail/stats` (Go API for speed)

**Response:**
```json
{
    "queue": {
        "size": 12,
        "checked_at": "2026-01-26T14:30:00Z",
        "status": "ok"
    },
    "incoming": {
        "last_hour": {
            "total": 245,
            "by_outcome": {
                "approved": 180,
                "pending": 32,
                "spam": 28,
                "dropped": 5
            },
            "by_source": {
                "trashnothing": 162,
                "direct": 83
            }
        },
        "last_24h": {
            "total": 4123,
            "bounces": 47,
            "fbl_reports": 3
        }
    },
    "processing": {
        "avg_time_ms": 156,
        "errors_last_hour": 2
    }
}
```

### ModTools UI Component

Add to Support Tools dashboard:

```vue
<!-- modtools/components/ModEmailStats.vue -->
<template>
  <div class="email-stats">
    <h4>Incoming Email Status</h4>

    <!-- Queue Status -->
    <div class="queue-status" :class="queueStatusClass">
      <span>Mail Queue: {{ stats.queue.size }}</span>
      <small>Last checked: {{ formatTime(stats.queue.checked_at) }}</small>
    </div>

    <!-- Hourly Stats -->
    <div class="hourly-stats">
      <div class="stat">
        <span class="value">{{ stats.incoming.last_hour.total }}</span>
        <span class="label">Last Hour</span>
      </div>
      <div class="stat">
        <span class="value">{{ stats.incoming.last_24h.total }}</span>
        <span class="label">Last 24h</span>
      </div>
      <div class="stat">
        <span class="value">{{ stats.incoming.last_24h.bounces }}</span>
        <span class="label">Bounces (24h)</span>
      </div>
    </div>

    <!-- Outcome Breakdown -->
    <div class="outcomes">
      <div v-for="(count, outcome) in stats.incoming.last_hour.by_outcome"
           :key="outcome"
           class="outcome-bar">
        <span>{{ outcome }}: {{ count }}</span>
      </div>
    </div>
  </div>
</template>
```

### Queue Monitoring Command

```php
// app/Console/Commands/Mail/MonitorMailQueueCommand.php
class MonitorMailQueueCommand extends Command
{
    protected $signature = 'mail:monitor-queue';

    private const WARNING_THRESHOLD = 100;
    private const CRITICAL_THRESHOLD = 500;

    public function handle(): int
    {
        $queueSize = $this->getPostfixQueueSize();

        Cache::put('mail:queue_size', $queueSize, now()->addMinutes(5));
        Cache::put('mail:queue_checked_at', now(), now()->addMinutes(5));

        if ($queueSize >= self::CRITICAL_THRESHOLD) {
            Sentry::captureMessage("Critical: Mail queue at {$queueSize}");
            return Command::FAILURE;
        }

        if ($queueSize >= self::WARNING_THRESHOLD) {
            Sentry::captureMessage("Warning: Mail queue at {$queueSize}");
        }

        return Command::SUCCESS;
    }

    private function getPostfixQueueSize(): int
    {
        // Execute mailq in postfix container
        $output = shell_exec('docker exec freegle-postfix-incoming mailq 2>/dev/null | grep -c "^[A-F0-9]" || echo 0');
        return (int) trim($output);
    }
}
```

---

## Part 6: Mail Archiving (MailPit Integration)

### Production Mail Debugging

Use Postfix `always_bcc` to copy all incoming mail to MailPit:

```
# In postfix main.cf
always_bcc = archive@mailpit
```

MailPit provides:
- Web UI for searching/viewing mail
- API for programmatic access
- 7-day retention (configurable)
- Full MIME viewing

### MailPit Configuration for Production

```yaml
# In docker-compose.yml - enhance existing mailpit service
mailpit:
  image: axllent/mailpit:latest
  container_name: freegle-mailpit
  environment:
    - MP_DATABASE=/data/mailpit.db
    - MP_MAX_MESSAGES=100000
    - MP_MAX_AGE=168h  # 7 days
    - MP_SMTP_AUTH_ACCEPT_ANY=true
    - MP_WEBROOT=/mailpit
  volumes:
    - mailpit-data:/data
  labels:
    # Expose via Traefik for Support staff
    - "traefik.http.routers.mailpit-prod.rule=Host(`mailpit.ilovefreegle.org`)"
    - "traefik.http.routers.mailpit-prod.middlewares=auth@docker"
```

### Access Control

MailPit should only be accessible to Support/Admin users:
- Use Traefik middleware with basic auth or OAuth
- Or integrate into ModTools with iframe + auth check

### Alternative: Piler (for longer retention)

If 7-day retention is insufficient, consider Piler for enterprise-grade archiving:
- Full-text search
- Longer retention periods
- Compliance features

See `plans/reference/logging-and-email-tracking-research.md` for Piler setup details.

---

## Part 7: Logging Architecture

### Loki for All Incoming Mail Logs

No database tables for logging. All incoming mail events go to Loki:

```php
// In IncomingMailService
Log::channel('incoming_mail')->info('Mail processed', [
    'envelope_from' => $envelope['from'],
    'envelope_to' => $envelope['to'],
    'from_address' => $parsed->getFromAddress(),
    'subject' => $parsed->getSubject(),
    'message_id' => $parsed->getMessageId(),
    'source' => $source,  // Email, Platform, TrashNothing
    'routing_result' => $result->getOutcome(),
    'rspamd_score' => $rspamdResult?->score,
    'processing_time_ms' => $duration,
    'user_id' => $user?->id,
    'group_id' => $group?->id,
]);
```

### Loki Queries for Support

```logql
# All mail in last hour
{app="iznik-batch", channel="incoming_mail"} | json

# Filter by outcome
{app="iznik-batch", channel="incoming_mail"} | json | routing_result="Spam"

# Filter by user
{app="iznik-batch", channel="incoming_mail"} | json | user_id="12345"

# Bounces
{app="iznik-batch", channel="incoming_mail"} | json | routing_result="Bounce"
```

---

## Part 8: Switchover Process

### Phase 0: Preparation (Before any mail migration)

1. **Email template cleanup**
   - Remove legacy mailto: unsubscribe links from MGML templates
   - Replace with website settings page links
   - Ensure List-Unsubscribe uses RFC 8058 one-click format

2. **Infrastructure setup**
   - Deploy Postfix container (not receiving mail yet)
   - Deploy/configure MailPit for archiving
   - Set up Loki logging channel

### Phase 1: Bounces Only (Lowest Risk)

**Duration**: 1-2 weeks

1. **Configure Exim to forward bounces to Postfix**
   ```
   # Exim router
   bounce_to_postfix:
     driver = manualroute
     domains = users.ilovefreegle.org
     local_parts = noreply : bounce-*
     transport = postfix_smtp
     route_list = * 127.0.0.1::2525
   ```

2. **Deploy Laravel BounceService**
   - Port `Bounce.php` logic to Laravel
   - Implement DSN parsing for no-reply bounces
   - Keep VERP support for transition period

3. **Monitor**
   - Compare bounce counts (old vs new system)
   - Watch for missed bounces
   - Verify user suspension working

4. **Success criteria**
   - 100% bounce detection rate
   - User suspension logic matches

### Phase 2: FBL Reports (Low Risk)

**Duration**: 1 week

1. **Forward FBL to Postfix**
   ```
   fbl_to_postfix:
     driver = manualroute
     domains = users.ilovefreegle.org
     local_parts = fbl
     transport = postfix_smtp
     route_list = * 127.0.0.1::2525
   ```

2. **Deploy Laravel FBLService**

3. **Success criteria**
   - FBL reports processed correctly
   - Users unsubscribed as expected

### Phase 3: Trash Nothing Chat Replies (Medium Risk)

**Duration**: 2 weeks

**Why TN first**: 66% of traffic, but validates via header so bypasses spam checks - simpler to test.

1. **Forward TN domain to Postfix**
   ```
   tn_to_postfix:
     driver = manualroute
     domains = user.trashnothing.com
     transport = postfix_smtp
     route_list = * 127.0.0.1::2525
   ```

2. **Deploy Laravel TrashNothingService**
   - Port TN header validation
   - Port chat routing logic

3. **Success criteria**
   - Chat messages delivered correctly
   - TN secret validation working

### Phase 4: Native Chat Replies (Medium Risk)

**Duration**: 2 weeks

1. **Forward chat reply addresses**
   ```
   chat_to_postfix:
     driver = manualroute
     domains = users.ilovefreegle.org
     local_parts = notify-*
     transport = postfix_smtp
     route_list = * 127.0.0.1::2525
   ```

2. **Deploy full chat routing**
   - Needs spam/content checks for non-TN mail

3. **Success criteria**
   - Chat replies work for all sources
   - Spam detection equivalent to existing

### Phase 5: Group Messages (Higher Risk)

**Duration**: 2-4 weeks

1. **Forward group domain**
   ```
   groups_to_postfix:
     driver = manualroute
     domains = groups.ilovefreegle.org
     transport = postfix_smtp
     route_list = * 127.0.0.1::2525
   ```

2. **Deploy full MailRouterService**
   - Complete routing logic (11 outcomes)
   - All email commands
   - Full spam/content moderation

3. **Success criteria**
   - All routing outcomes match existing behavior
   - Moderator workflows unchanged

### Phase 6: Full Cutover

1. **Update MX records** to point directly to Postfix
2. **Disable Exim forwarding rules**
3. **Monitor closely** for 1-2 weeks

### Phase 7: Retire iznik-server Code

**After 4+ weeks of stable operation:**

1. **Remove from crontab**
   - `bounce.php`
   - `bounce_users.php`
   - Any incoming mail related scripts

2. **Archive code** (don't delete immediately)
   - `scripts/incoming/incoming.php`
   - `include/mail/MailRouter.php`
   - `include/mail/Bounce.php`

3. **Update documentation**
   - Remove references to Exim configuration
   - Update architecture diagrams

4. **Clean up database**
   - `bounces` table (temporary storage) can be emptied
   - `bounces_emails` table continues to be used by Laravel

---

## Part 9: Test Migration Strategy

### Existing Tests (iznik-server)

- `MailRouterTest.php` - 1923 lines, 56+ test cases
- 77 test email files in `test/ut/php/msgs/`

### Laravel Test Structure

```
tests/
├── Unit/Services/Mail/Incoming/
│   ├── MailParserServiceTest.php
│   ├── MailRouterServiceTest.php
│   ├── BounceServiceTest.php
│   ├── FBLServiceTest.php
│   ├── SpamCheckServiceTest.php
│   └── ContentModerationServiceTest.php
├── Feature/Mail/
│   ├── IncomingMailCommandTest.php
│   ├── GroupMessageRoutingTest.php
│   ├── ChatReplyRoutingTest.php
│   └── BounceProcessingTest.php
└── fixtures/emails/
    ├── bounce/
    ├── fbl/
    ├── chat-replies/
    ├── group-messages/
    └── spam/
```

### Test Migration Approach

1. **Convert test files to templates**
   - Replace hardcoded group names with `{{GROUP_NAME}}`
   - Replace email addresses with `{{FROM_EMAIL}}`

2. **Port test assertions**
   - Each PHP test case becomes a Laravel test
   - Verify same routing outcomes

3. **Use DatabaseTransactions**
   - Each test isolated
   - Supports parallel execution

---

## Part 10: Implementation Phases

### Phase A: Foundation (Week 1-2)
- [ ] Create Postfix container configuration
- [ ] Implement `mail:incoming` command
- [ ] Implement `MailParserService`
- [ ] Set up Loki logging channel for incoming mail

### Phase B: Bounce Processing (Week 3-4)
- [ ] Port `Bounce.php` to Laravel `BounceService`
- [ ] Implement DSN parsing
- [ ] Implement heuristic extraction
- [ ] Port bounce tests
- [ ] Implement `mail:process-bounces` scheduled command

### Phase C: FBL Processing (Week 4)
- [ ] Implement `FBLService`
- [ ] Port FBL tests

### Phase D: Routing Logic (Week 5-6)
- [ ] Implement `MailRouterService` with all outcomes
- [ ] Port email command handling
- [ ] Implement chat routing
- [ ] Implement group message routing
- [ ] Port routing tests

### Phase E: Spam & Content Moderation (Week 7-8)
- [ ] Implement `SpamCheckService` (dual SpamD + custom checks)
- [ ] Port SpamAssassin/SpamD integration
- [ ] Port custom Freegle spam checks from `Spam.php`
- [ ] Implement `ContentModerationService`
- [ ] Port worry words and spam keywords
- [ ] Port spam detection tests

### Phase F: ModTools UI (Week 9-10)
- [ ] Implement Go API endpoint for mail stats
- [ ] Create Vue component for email statistics (`ModEmailStats.vue`)
- [ ] Add to Support Tools dashboard
- [ ] Create spam queue database table
- [ ] Implement spam queue API endpoints (Go)
- [ ] Create spam review Vue component (`ModSpamQueue.vue`)
- [ ] Implement whitelist management for approved spam

### Phase G: Production Deployment (Week 10-12)
- [ ] Deploy Postfix container
- [ ] Phased migration (bounces → FBL → TN → chat → groups)
- [ ] Monitor and tune

### Phase H: Retirement (Week 13+)
- [ ] Remove iznik-server incoming mail code from crontab
- [ ] Archive code
- [ ] Update documentation

---

## Part 11: Risk Mitigation

### Rollback Strategy

Each phase can be rolled back independently:
1. **Disable Exim router rule** for that traffic type
2. Traffic returns to iznik-server processing
3. No data loss - mail queued in Postfix if needed

### Monitoring

1. **Queue depth** - Alert if > 100 messages
2. **Processing errors** - Sentry alerts
3. **Routing outcome comparison** - Log discrepancies
4. **User complaints** - Support ticket monitoring

### Data Integrity

- Compare routing decisions between old/new during parallel operation
- Log all discrepancies for investigation
- Keep iznik-server code available for 4+ weeks after full cutover

---

## Part 12: Configuration

### Environment Variables

```env
# Incoming mail processing
MAIL_INCOMING_ENABLED=true
MAIL_INCOMING_LOG_RAW=true

# Rspamd
RSPAMD_HOST=rspamd
RSPAMD_PORT=11333
RSPAMD_SPAM_THRESHOLD=8.0

# TUSD (attachments)
TUS_UPLOADER_URL=http://tusd:1080/files

# Trash Nothing
TN_SECRET=your-secret-here

# Domains
USER_DOMAIN=ilovefreegle.org
GROUP_DOMAIN=groups.ilovefreegle.org

# Mail archiving
MAILPIT_ARCHIVE_ENABLED=true
```

---

## References

### Internal Documents
- `plans/active/incoming-email-migration-to-laravel.md` - Original detailed plan (to be superseded)
- `plans/reference/logging-and-email-tracking-research.md` - Piler/Loki research
- `iznik-batch/EMAIL-MIGRATION-GUIDE.md` - Migration lessons learned

### External
- [Postfix Pipe Configuration](https://thecodingmachine.io/triggering-a-php-script-when-your-postfix-server-receives-a-mail)
- [php-mime-mail-parser](https://github.com/php-mime-mail-parser/php-mime-mail-parser)
- [RFC 3464](https://datatracker.ietf.org/doc/html/rfc3464) - DSN format
- [RFC 5965](https://datatracker.ietf.org/doc/html/rfc5965) - FBL/ARF format
- [MailPit](https://github.com/axllent/mailpit) - Mail testing/archiving

### Source Code
- `iznik-server/include/mail/MailRouter.php` - Current routing logic
- `iznik-server/include/mail/Bounce.php` - Current bounce processing
- `iznik-server/test/ut/php/include/MailRouterTest.php` - Test cases to port
