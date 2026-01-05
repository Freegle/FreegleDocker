# Incoming Email Migration to Laravel (IZNIK Batch)

## Executive Summary

This plan outlines the migration of incoming email processing from IZNIK server (PHP with Exim 4) to IZNIK batch (Laravel with Postfix). This is a major architectural change affecting approximately 2,500+ lines of core email processing code and 2,000+ lines of tests.

## Current Architecture

### Entry Point
- **Script**: `/iznik-server/scripts/incoming/incoming.php`
- **Called by**: Exim 4 mail server
- **Method**: Reads raw email from STDIN, extracts SENDER/RECIPIENT from environment
- **Flow**: `incoming.php` → `MailRouter::received()` → `MailRouter::route()`

### Key Components

1. **MailRouter** (`include/mail/MailRouter.php` - ~1,200 lines)
   - Main routing engine with 11 routing outcomes
   - Handles: spam detection, commands, chat routing, group routing, user-to-user messages

2. **Message Parser** (`include/message/Message.php`)
   - MIME parsing via `php-mime-mail-parser` library
   - Extracts headers, body, attachments
   - User lookup/creation
   - Group detection

3. **Spam Detection** (`include/spam/Spam.php`)
   - SpamAssassin integration (spamc on port 783)
   - Custom spam checks (IP, subject, country, greeting patterns)
   - Threshold: 8.0 for SpamAssassin

4. **Worry Words** (`include/message/WorryWords.php`)
   - Regulated substances (UK law compliance)
   - Reportable substances
   - Medicines/supplements
   - General review items
   - Uses fuzzy matching (Levenshtein distance)

5. **Spam Keywords** (`spam_keywords` table)
   - Spam/scam detection words
   - Actions: Review, Spam, Whitelist
   - Supports literal and regex matching

### Routing Outcomes
```
FAILURE         - Could not process
INCOMING_SPAM   - Marked as spam
APPROVED        - Approved for posting
PENDING         - Awaiting moderation
TO_USER         - Routed to chat
TO_SYSTEM       - System command
RECEIPT         - Read receipt
TRYST           - Calendar event response
DROPPED         - Silently dropped
TO_VOLUNTEERS   - Routed to moderators
```

### Email Commands Supported

**Note**: Legacy subscription management commands (digestoff, eventsoff, newslettersoff, relevantoff, volunteeringoff, notificationmailsoff) have been removed. Users should be directed to the settings page on the website instead. MGML email templates must not include mailto: links for these commands.

| Pattern | Action |
|---------|--------|
| `readreceipt-{chatid}-{uid}-{msgid}@` | Chat read receipt |
| `handover-{trystid}-{uid}@` | Calendar event response |
| `{group}-volunteers@` | Mail to moderators |
| `{group}-auto@` | Auto address |
| `{group}-subscribe@` | Subscribe to group |
| `{group}-unsubscribe@` | Unsubscribe from group |
| `unsubscribe-{uid}-{key}-{type}@` | One-click unsubscribe (RFC 8058) |

**Removed Commands** (users should use website settings instead):
- ~~`digestoff-{uid}-{gid}@`~~
- ~~`eventsoff-{uid}-{gid}@`~~
- ~~`newslettersoff-{uid}@`~~
- ~~`relevantoff-{uid}@`~~
- ~~`volunteeringoff-{uid}-{gid}@`~~
- ~~`notificationmailsoff-{uid}@`~~

### Trash Nothing Integration
- **Authentication**: `X-Trash-Nothing-Secret` header
- **Headers extracted**:
  - `X-Trash-Nothing-User-ID` - User mapping
  - `X-Trash-Nothing-Post-ID` - Post reference
  - `X-Trash-Nothing-User-IP` / `X-Trash-Nothing-IP-Hash` - IP detection
  - `X-Trash-Nothing-Post-Coordinates` - Geolocation
  - `X-Trash-Nothing-Source` - Source tracking
  - `X-Trash-Nothing-Withdrawn` - Withdrawal flag
- **Effect**: Bypasses spam checks when secret validates

### TUSD Integration
- Images are uploaded to TUSD server
- Referenced by `freegletusd-*` UID
- Perceptual hashing for deduplication (ImageHash)
- Served via IMAGE_DELIVERY (weserv/images)

---

## Proposed Architecture

### Email Reception Method: Postfix Pipe to Laravel Command

Based on research, the recommended approach is:

**Option Selected**: Postfix piping to a Laravel Artisan command

This is preferred over:
- ❌ Running Laravel as SMTP server (complex, not Laravel's strength)
- ❌ Third-party services like Mailgun/SendGrid (user requirement to avoid)
- ❌ Laravel Mailbox package (designed for web hooks, not direct MTA integration)

### Postfix Docker Container

Postfix needs to be added to docker-compose.yml:

<details>
<summary>Docker Compose Configuration</summary>

```yaml
postfix:
  build:
    context: ./conf/postfix
    dockerfile: Dockerfile
  container_name: freegle-postfix
  hostname: mail.ilovefreegle.org
  ports:
    - "25:25"
    - "587:587"
  volumes:
    - ./conf/postfix/main.cf:/etc/postfix/main.cf:ro
    - ./conf/postfix/master.cf:/etc/postfix/master.cf:ro
    - ./conf/postfix/transport:/etc/postfix/transport:ro
    - postfix-spool:/var/spool/postfix
  networks:
    - default
  restart: unless-stopped
  depends_on:
    - rspamd
    - app

volumes:
  postfix-spool:
```

</details>

### Synchronous Processing with Concurrency Control

Incoming mail is processed **synchronously** to provide natural flow control - if we're overloaded, Postfix will queue messages. However, we need to handle multiple emails in parallel with a limit to avoid hogging the machine.

**Key parameters** (in `main.cf`):
- `freegle_destination_concurrency_limit = 4` - Max parallel deliveries to Laravel
- `default_process_limit = 50` - Overall Postfix process limit

This allows up to 4 simultaneous email processing jobs, providing:
- **Flow control**: Postfix queues excess mail automatically
- **Parallelism**: Better throughput than single-threaded
- **Resource limits**: Won't overwhelm the server

### Postfix Configuration

<details>
<summary>master.cf - Transport definition</summary>

```
freegle unix - n n - - pipe
  flags=F user=www-data argv=/usr/bin/php /path/to/iznik-batch/artisan mail:incoming ${sender} ${recipient}
```

</details>

<details>
<summary>main.cf - Main configuration</summary>

```
transport_maps = hash:/etc/postfix/transport

# Concurrency limits for Laravel pipe transport
freegle_destination_concurrency_limit = 4
default_process_limit = 50

# Archive incoming mail to Piler for content moderation testing
always_bcc = archive@piler.ilovefreegle.org
```

</details>

<details>
<summary>transport - Domain routing</summary>

```
groups.ilovefreegle.org    freegle:
user.trashnothing.com      freegle:
@ilovefreegle.org          freegle:
```

</details>

### Laravel Command Structure

```
app/Console/Commands/Mail/
├── IncomingMailCommand.php      # Entry point (reads STDIN)
├── ProcessIncomingMailCommand.php  # Main processing (for queue)
```

```
app/Services/Mail/
├── IncomingMailService.php      # Main orchestrator
├── MailParserService.php        # MIME parsing
├── MailRouterService.php        # Routing logic
├── SpamCheckService.php         # Spam detection (Rspamd + custom)
├── ContentModerationService.php # Worry words + spam keywords unified
├── TrashNothingService.php      # TN-specific handling
├── EmailCommandService.php      # Command processing
├── AttachmentService.php        # TUSD upload handling
```

---

## Current Spam Detection Module (Port Unchanged)

**IMPORTANT**: For this migration, we are porting the current spam detection approach unchanged. This keeps the migration focused and reduces risk. Future improvements to spam detection are documented separately in [Spam and Content Moderation Rethink](../future/spam-and-content-moderation-rethink.md).

### What Gets Ported

The Laravel `SpamCheckService` will implement the exact same logic as currently exists in IZNIK server:

| Component | Current Location | Laravel Location |
|-----------|-----------------|------------------|
| Rspamd/SpamAssassin check | `Spam.php::check()` | `SpamCheckService::checkRspamd()` |
| IP-based spam checks | `Spam.php::checkIP()` | `SpamCheckService::checkIP()` |
| Subject line checks | `Spam.php::checkSubject()` | `SpamCheckService::checkSubject()` |
| Country-based checks | `Spam.php::checkCountry()` | `SpamCheckService::checkCountry()` |
| Greeting pattern checks | `Spam.php::checkGreeting()` | `SpamCheckService::checkGreeting()` |
| Worry words check | `WorryWords.php` | `ContentModerationService::checkWorryWords()` |
| Spam keywords check | `Spam.php::checkSpamKeywords()` | `ContentModerationService::checkSpamKeywords()` |

### Spam Keywords Table

The existing `spam_keywords` table (311 entries) is used unchanged:
- Literal and regex matching preserved
- Actions (Review, Spam, Whitelist) preserved
- Per-group overrides preserved

### Worry Words Table

The existing `worrywords` table (272 entries) is used unchanged:
- Types (Regulated, Reportable, Medicine, Review, Allowed) preserved
- Levenshtein matching (threshold=1, effectively exact match) preserved
- Per-group custom words (`spammers.worrywords` setting) preserved

### Why Port Unchanged?

1. **Proven**: Current system has been working in production for years
2. **Known behaviour**: Moderators understand what to expect
3. **Lower risk**: No surprises during migration
4. **Faster delivery**: Less scope = faster completion
5. **Future flexibility**: Clean module boundary allows future improvements

### Future Improvements (Separate Project)

Improvements like spam signatures, LLM-based intent detection, and unified content moderation are documented in [Spam and Content Moderation Rethink](../future/spam-and-content-moderation-rethink.md). These will be implemented after the email migration is stable.

---

## Logging Architecture

### Why Loki Instead of Database

**No new database tables for logging.** All incoming mail logs go to Loki, which provides:

- **Automatic retention/pruning**: Configure retention period (e.g., 30 days) and Loki handles cleanup
- **Efficient storage**: Loki is optimised for log data with compression and indexing
- **Rich querying**: LogQL allows filtering by any field, aggregations, and time-based queries
- **Grafana integration**: Visualise trends, create dashboards, set up alerts
- **No database bloat**: Keeps the production database lean and focused on application data

### Support Tools Integration

Support Tools queries Loki via the Grafana/Loki HTTP API to display incoming email logs.

**Query examples**:
```logql
# All incoming mail in last hour
{app="iznik-batch", channel="incoming_mail"} | json

# Filter by routing result
{app="iznik-batch", channel="incoming_mail"} | json | routing_result="Spam"

# Filter by user
{app="iznik-batch", channel="incoming_mail"} | json | user_id="12345"

# Aggregate by outcome
sum by (routing_result) (count_over_time({app="iznik-batch", channel="incoming_mail"}[1h]))
```

### Loki Log Structure

Each incoming mail is logged as a structured JSON entry:

```php
// In IncomingMailService
Log::channel('incoming_mail')->info('Mail processed', [
    // Envelope
    'envelope_from' => $envelope['from'],
    'envelope_to' => $envelope['to'],

    // Headers
    'from_address' => $parsed->getFromAddress(),
    'from_name' => $parsed->getFromName(),
    'subject' => $parsed->getSubject(),
    'message_id' => $parsed->getMessageId(),
    'raw_size' => strlen($rawEmail),

    // Source tracking
    'source' => $source,  // Email, Platform, TrashNothing
    'tn_user_id' => $tnUserId,
    'tn_post_id' => $tnPostId,

    // Routing
    'routing_result' => $result->getOutcome(),

    // Spam analysis
    'rspamd_score' => $rspamdResult?->score,
    'rspamd_action' => $rspamdResult?->action,
    'rspamd_symbols' => $rspamdResult?->symbols,

    // Content moderation
    'worry_words_matched' => $moderationResult?->worryWords,

    // Result references
    'message_id_created' => $createdMessage?->id,
    'chat_id_created' => $createdChat?->id,
    'user_id' => $user?->id,
    'group_id' => $group?->id,

    // Performance
    'processing_time_ms' => $duration,
]);
```

**Retention**: Configure in Loki (e.g., 30 days). Old logs are automatically pruned.

---

## Queue Monitoring & Alerting

### Postfix Queue Monitoring

Monitor the Postfix mail queue to detect backlog situations. If mail is arriving faster than we can process it (or processing is stalled), the queue will grow.

**Queue check command**:
```bash
# Get queue size
mailq | grep -c "^[A-F0-9]"

# Or via postqueue
postqueue -p | tail -n 1
```

**Queue locations**:
- Active queue: `/var/spool/postfix/active/`
- Deferred queue: `/var/spool/postfix/deferred/`
- Incoming queue: `/var/spool/postfix/incoming/`

### Sentry Alerting for Queue Buildup

A scheduled Laravel command checks queue size and raises Sentry alerts when thresholds are exceeded.

<details>
<summary>Queue Monitor Command</summary>

```php
// app/Console/Commands/Mail/MonitorMailQueueCommand.php
class MonitorMailQueueCommand extends Command
{
    protected $signature = 'mail:monitor-queue';
    protected $description = 'Check Postfix queue size and alert if excessive';

    private const WARNING_THRESHOLD = 100;   // Warn at 100 queued messages
    private const CRITICAL_THRESHOLD = 500;  // Critical at 500 queued messages

    public function handle(): int
    {
        $queueSize = $this->getQueueSize();

        // Store for ModTools statistics
        Cache::put('mail:queue_size', $queueSize, now()->addMinutes(5));
        Cache::put('mail:queue_checked_at', now(), now()->addMinutes(5));

        if ($queueSize >= self::CRITICAL_THRESHOLD) {
            \Sentry\captureMessage(
                "Critical: Postfix mail queue has {$queueSize} messages",
                \Sentry\Severity::error()
            );
            $this->error("CRITICAL: Queue size is {$queueSize}");
            return 1;
        }

        if ($queueSize >= self::WARNING_THRESHOLD) {
            \Sentry\captureMessage(
                "Warning: Postfix mail queue has {$queueSize} messages",
                \Sentry\Severity::warning()
            );
            $this->warn("WARNING: Queue size is {$queueSize}");
            return 0;
        }

        $this->info("Queue size: {$queueSize}");
        return 0;
    }

    private function getQueueSize(): int
    {
        // Count messages in Postfix queue
        $output = shell_exec('mailq 2>/dev/null | grep -c "^[A-F0-9]" || echo 0');
        return (int) trim($output);
    }
}
```

</details>

**Schedule** (in `app/Console/Kernel.php`):
```php
$schedule->command('mail:monitor-queue')->everyMinute();
```

### ModTools Mail Statistics

Display incoming mail queue status in ModTools statistics dashboard.

**API endpoint**: `GET /api/mail/stats`

**Response**:
```json
{
    "queue": {
        "size": 12,
        "checked_at": "2025-01-05T14:30:00Z",
        "status": "ok"  // "ok", "warning", "critical"
    },
    "processing": {
        "last_hour": {
            "total": 245,
            "approved": 180,
            "pending": 32,
            "spam": 28,
            "dropped": 5
        },
        "avg_processing_time_ms": 156
    }
}
```

**ModTools UI**:
- Show queue size with colour coding (green/amber/red based on thresholds)
- Show "last checked" timestamp
- Show processing statistics for last hour/day
- Alert banner if queue is in warning/critical state

---

## Test Parallelisation Strategy

### Current Test Structure

The existing PHP tests use:
- 77 test message files in `/test/ut/php/msgs/`
- Hard-coded group names like "testgroup"
- Fixed email addresses
- Sequential execution assumed

### Laravel Test Structure

```
tests/
├── Unit/
│   └── Services/Mail/
│       ├── MailParserServiceTest.php
│       ├── MailRouterServiceTest.php
│       ├── SpamCheckServiceTest.php
│       ├── ContentModerationServiceTest.php
│       └── EmailCommandServiceTest.php
├── Feature/
│   └── Mail/
│       ├── IncomingMailCommandTest.php
│       ├── GroupMessageRoutingTest.php
│       ├── ChatReplyRoutingTest.php
│       ├── SpamDetectionTest.php
│       ├── TrashNothingIntegrationTest.php
│       └── EmailCommandsTest.php
└── fixtures/
    └── emails/
        ├── basic/
        ├── spam/
        ├── replies/
        ├── trash-nothing/
        └── commands/
```

### Parallelisation Approach

1. **Unique identifiers per test**: Use factory methods that generate unique values:

```php
trait UniqueMailTestData
{
    protected function uniqueGroupName(): string
    {
        return 'testgroup_' . Str::random(8) . '_' . getmypid();
    }

    protected function uniqueEmail(): string
    {
        return 'test_' . Str::random(8) . '_' . getmypid() . '@example.com';
    }

    protected function uniqueMessageId(): string
    {
        return '<' . Str::uuid() . '@test.ilovefreegle.org>';
    }
}
```

2. **Database isolation**: Use database transactions that rollback:

```php
use Illuminate\Foundation\Testing\DatabaseTransactions;

class MailRouterServiceTest extends TestCase
{
    use DatabaseTransactions;

    // Each test runs in its own transaction
}
```

3. **Test data files as templates**: Parse and modify dynamically:

```php
protected function loadTestEmail(string $name, array $replacements = []): string
{
    $template = file_get_contents(base_path("tests/fixtures/emails/{$name}"));

    // Apply unique replacements
    $defaults = [
        '{{GROUP_NAME}}' => $this->uniqueGroupName(),
        '{{MESSAGE_ID}}' => $this->uniqueMessageId(),
        '{{FROM_EMAIL}}' => $this->uniqueEmail(),
    ];

    return strtr($template, array_merge($defaults, $replacements));
}
```

4. **Parallel test execution**: Use PHPUnit's `--parallel` flag:

```bash
php artisan test --parallel --processes=4
```

---

## MIME Parsing

### Recommended Library: `php-mime-mail-parser`

Same library currently used in iznik-server. Install via Composer:

```bash
composer require php-mime-mail-parser/php-mime-mail-parser
```

### Laravel Service

```php
class MailParserService
{
    public function parse(string $rawEmail): ParsedEmail
    {
        $parser = new \PhpMimeMailParser\Parser();
        $parser->setText($rawEmail);

        return new ParsedEmail(
            messageId: $parser->getHeader('message-id'),
            from: $this->parseAddress($parser->getHeader('from')),
            to: $this->parseAddresses($parser->getHeader('to')),
            cc: $this->parseAddresses($parser->getHeader('cc')),
            subject: $parser->getHeader('subject'),
            date: $parser->getHeader('date'),
            textBody: $parser->getMessageBody('text'),
            htmlBody: $parser->getMessageBody('html'),
            attachments: $this->parseAttachments($parser->getAttachments()),
            headers: $this->extractAllHeaders($parser),
        );
    }
}
```

---

## Attachment Handling with TUSD

### Current Flow
1. Image extracted from email
2. Perceptual hash computed (ImageHash)
3. Check cache for duplicate
4. Upload to TUSD if new
5. Store `freegletusd-{uid}` reference in database

### Laravel Service

```php
class AttachmentService
{
    private TusClient $tusClient;
    private ImageHash $hasher;

    public function processAttachment(Attachment $attachment): ?ProcessedAttachment
    {
        $imageData = $attachment->getContent();

        // Compute perceptual hash
        $hash = $this->hasher->hash($imageData);

        // Check cache
        if ($cached = Cache::get("imagehash:{$hash}")) {
            return new ProcessedAttachment($cached, $hash);
        }

        // Upload to TUSD
        $uid = $this->tusClient->upload($imageData, 'image/jpeg');

        // Cache the mapping
        Cache::put("imagehash:{$hash}", $uid, now()->addHours(24));

        return new ProcessedAttachment($uid, $hash);
    }
}
```

---

## Mail Volume and Timing

This section documents the measured mail volume for capacity planning. For future LLM-based content detection improvements, see [Spam and Content Moderation Rethink](../future/spam-and-content-moderation-rethink.md).

### Timing Requirements

**Incoming email is background processing** - doesn't need user-perceptible response times.

Key constraint is **throughput**, not latency:
- Must process mail faster than it arrives (avoid queue buildup)
- Individual message latency can be 500ms-2s without issue
- Only becomes a problem if we can't keep up with volume

**Measuring current volume** (run on production server):
```bash
# Messages per hour (last 24h)
grep "<=.*@" /var/log/exim4/mainlog | awk '{print $1, $2}' | cut -d: -f1,2 | sort | uniq -c | tail -24

# Peak messages per minute
grep "<=.*@" /var/log/exim4/mainlog | awk '{print $1, $2}' | cut -d: -f1,2,3 | sort | uniq -c | sort -rn | head -20

# Total today
grep "<=.*@" /var/log/exim4/mainlog | grep "$(date +%Y-%m-%d)" | wc -l
```

**Sizing guidance**:
- If peak is 100 messages/minute → need to process each in <600ms average
- If peak is 10 messages/minute → can take up to 6 seconds each
- With 4 parallel workers (`freegle_destination_concurrency_limit = 4`), multiply budget by 4

**Actual measured volume** (January 2026):
```
Per minute:  Peak 12, Average 5-6
Per second:  Peak 20 (burst from batch sends)
Total daily: ~4,000 messages
```

**Traffic source breakdown**:
| Source | Daily Volume | % of Total | Notes |
|--------|-------------|------------|-------|
| user.trashnothing.com | ~2,650 | 66% | TN integration - bypasses spam checks |
| gmail.com | ~126 | 3% | Regular users |
| users.ilovefreegle.org | ~43 | 1% | Internal Freegle traffic |
| Other ISPs (hotmail, btinternet, etc.) | ~1,180 | 30% | Regular users |

**Key insight**: Only ~34% of traffic (~1,350 msgs/day) goes through full spam detection. Trash Nothing traffic validates via `X-Trash-Nothing-Secret` header and bypasses spam checks.

**Email-based chat replies**:
Based on `chat_messages_byemail` table (January 2026 data):
- **~32-33% of chat messages come via email** (~2,500-3,000 per day)
- ~8,000 total chat messages per day
- Email remains a significant user interface, not a legacy edge case

This is much higher than anticipated - a third of all chat interactions still happen via email. This has implications for:
- Investment in email infrastructure (still heavily used)
- Migration planning (can't deprecate email quickly)
- Content moderation (need consistent experience across email and web)

### Email Usage Trends (Support Tools Reporting)

As more users adopt web/app, we need to track email usage trends to inform future investment decisions.

**Key metrics to track**:

| Metric | Description | Why It Matters |
|--------|-------------|----------------|
| Email reply % | Chat messages via email vs total | Overall email dependency |
| Email user % | Users who use email vs web-only | User base segmentation |
| New user email % | Email usage by account age cohort | Is email declining naturally? |
| Email-only users | Users who ONLY reply via email | Migration difficulty |
| Weekly trend | Email % over time | Rate of decline |

**Support Tools Dashboard**:

```
┌─────────────────────────────────────────────────────────────┐
│ Email Usage Trends                                          │
├─────────────────────────────────────────────────────────────┤
│ Current Period (3 days):                                    │
│   Chat messages: 24,100 total, 7,839 via email (32.5%)     │
│   Daily average: ~8,000 chats, ~2,600 via email            │
│                                                             │
│ User Segmentation:                                          │
│   Email-only users: ??? (need query 2)                     │
│   Mostly email (>50%): ???                                 │
│   Mixed: ???                                                │
│   Web/app only: ???                                         │
│                                                             │
│ Trend (when more data available):                           │
│   [Chart showing email % over time]                        │
│                                                             │
│ New User Adoption:                                          │
│   [Cohort analysis when data available]                    │
└─────────────────────────────────────────────────────────────┘
```

**Note**: The `chat_messages_byemail` table only retains recent data (CASCADE delete from `messages` table which purges after 31 days). For long-term trend analysis, we'll need to periodically snapshot these statistics to a separate table or log them to Loki.

This data helps answer:
- How much effort should we invest in email infrastructure?
- Can we deprecate email replies eventually?
- Which users would be affected by email changes?
- Is the natural trend towards web/app accelerating?

Burst pattern: Most seconds have 0-2 messages, with occasional 15-20 message bursts (likely digest emails or batch notifications). Postfix queues bursts and workers process steadily.

With 4 workers at 1-2s per message:
- Steady state (5 msgs/min): Queue stays empty
- Burst (20 msgs/sec): Queue grows to ~20, clears in ~5-10 seconds
- **Plenty of headroom** for 1-2s LLM classification

**Effective content analysis load**:
Given that 66% of traffic (Trash Nothing) bypasses spam/content checks, the actual load on the LLM classifier is:
- ~1,350 messages/day requiring full analysis
- Peak ~4-5 messages/minute (not 12)
- Average ~1 message/minute

This means we can use a **larger, more capable LLM model** (Phi-3-mini at 3.8B params) without throughput concerns. The 1-2 second processing time is entirely acceptable.

### Parallel Testing Before Switchover

Although we're doing a hard switchover for mail reception, we can **test the new spam detection in parallel** by processing messages that are already in the database.

**Approach**:
1. New messages arrive via existing Exim → stored in `messages` table
2. Background job processes recent messages through new detection pipeline
3. Compare results with existing spam classifications
4. Log discrepancies for review

<details>
<summary>Parallel Testing Command</summary>

```php
// app/Console/Commands/Mail/TestSpamDetectionCommand.php
class TestSpamDetectionCommand extends Command
{
    protected $signature = 'mail:test-spam-detection
        {--hours=24 : Process messages from last N hours}
        {--limit=1000 : Max messages to process}';

    public function handle()
    {
        $messages = Message::where('arrival', '>=', now()->subHours($this->option('hours')))
            ->limit($this->option('limit'))
            ->get();

        $results = [
            'matched_existing' => 0,
            'new_detection_would_flag' => 0,
            'existing_flagged_we_missed' => 0,
            'both_clean' => 0,
        ];

        foreach ($messages as $message) {
            $existingSpam = $message->spamtype !== null;
            $newResult = $this->contentAnalysisService->analyse(
                $message->subject . ' ' . $message->textbody
            );
            $newWouldFlag = $newResult->shouldFlag();

            if ($existingSpam && $newWouldFlag) {
                $results['matched_existing']++;
            } elseif (!$existingSpam && $newWouldFlag) {
                $results['new_detection_would_flag']++;
                Log::info('New detection would flag', [
                    'message_id' => $message->id,
                    'reason' => $newResult->explanation,
                ]);
            } elseif ($existingSpam && !$newWouldFlag) {
                $results['existing_flagged_we_missed']++;
                Log::warning('Existing flagged but we missed', [
                    'message_id' => $message->id,
                    'existing_reason' => $message->spamreason,
                ]);
            } else {
                $results['both_clean']++;
            }
        }

        $this->table(
            ['Metric', 'Count'],
            collect($results)->map(fn($v, $k) => [$k, $v])->toArray()
        );
    }
}
```

</details>

**Success criteria before switchover**:
- New detection catches ≥95% of what existing system catches
- False positive rate <1% on messages existing system approved
- Processing time is sustainable for observed mail volume

### LLM Infrastructure Decisions

**GPU vs CPU-Only**:

Given the measured volume (~1,350 messages/day needing content analysis, peak ~5/minute), **CPU-only is sufficient**:

| Approach | Hardware | Inference Time | Throughput | Cost |
|----------|----------|----------------|------------|------|
| CPU-only | Standard server | 1-2s per message | ~30-60/min | $0 |
| GPU | NVIDIA T4 or similar | 100-200ms | ~300-600/min | $200-400/mo |

**Recommendation**: CPU-only. We have 20x headroom (can process 30/min, need ~1/min average). GPU is overkill for our volume.

**Model choice**: Start with Phi-3-mini (3.8B params) on CPU. If too slow, fall back to smaller model or sentence embeddings.

### Piler Email Archive

**What is Piler?**

Piler is an open-source email archiving system. It stores copies of all emails for compliance, search, and analysis purposes.

**How we use it**:
- Postfix `always_bcc` sends a copy of every incoming email to Piler
- Provides searchable archive of historical messages
- Useful for testing content moderation changes against real data

**Do we need it for the migration?**

**No - Piler is optional.** It's useful for:
- Testing new spam detection against historical messages
- Debugging email processing issues
- Compliance/audit requirements

But it's not required for the email migration to work. We can add it later if needed.

**If we want Piler**:
- Separate container or server
- ~10GB storage per year (estimate based on volume)
- Search interface for support team

---

## Spam Reporting & Detection Rethink (Future)

> **See**: [Spam and Content Moderation Rethink](../future/spam-and-content-moderation-rethink.md) for future improvements including spam signatures, LLM-based detection, and unified ModTools interface.
>
> For the initial migration, we are porting the current spam detection approach unchanged. See the "Current Spam Detection Module" section above.

---

## Content Moderation (Current Approach)

For this migration, content moderation uses the existing spam detection logic ported unchanged:

```php
class ContentModerationService
{
    public function checkMessage(Message $message): ContentModerationResult
    {
        $results = collect();

        // 1. Rspamd check (spam, phishing, malware)
        $results->push($this->rspamd->check($message->getRawEmail()));

        // 2. Spam keywords check (311 entries)
        $results->push($this->checkSpamKeywords($message));

        // 3. Worry words check (272 entries - regulatory compliance)
        $results->push($this->checkWorryWords($message));

        // 4. Custom pattern checks (IP, subject, country, greeting)
        $results->push($this->checkPatterns($message));

        return new ContentModerationResult($results);
    }
}
```

### Support Tools Content Moderation UI

A dedicated interface for managing content moderation rules with **impact preview** using archived emails.
- Uses fuzzy hashing (simhash/minhash) to group similar content
- Extracts common patterns and variable parts
- Identifies the spam "template"

**Step 3**: Spam team reviews and approves:
- Sees grouped sample messages
- Confirms it's spam
- Approves signature creation

**Step 4**: Signature auto-blocks future occurrences:
- New messages matching signature are blocked
- No human review needed for known patterns
- Confidence increases with more matches

### Fuzzy Content Hashing

**Simhash** creates content fingerprints where similar messages produce similar hashes.

<details>
<summary>Simhash Implementation</summary>

```php
class SpamSignatureService
{
    public function createSignature(string $content): SpamSignature
    {
        // Normalize: lowercase, remove punctuation, stem words
        $normalized = $this->normalize($content);

        // Create shingles (word n-grams)
        $shingles = $this->ngrams($normalized, 3);

        // Compute simhash
        $hash = $this->simhash($shingles);

        // Extract semantic features
        $features = $this->extractFeatures($content);

        return new SpamSignature(
            fuzzyHash: $hash,
            semanticFeatures: $features,
        );
    }

    public function findMatchingSignature(string $content): ?SpamMatch
    {
        $msgSig = $this->createSignature($content);

        $signatures = SpamSignature::where('status', 'active')->get();

        foreach ($signatures as $sig) {
            // Hamming distance for fuzzy hash comparison
            $hashDistance = $this->hammingDistance($msgSig->fuzzyHash, $sig->fuzzy_hash);

            // Feature similarity
            $featureScore = $this->compareFeatures($msgSig->semanticFeatures, $sig->semantic_features);

            // Combined score (hash match is stronger signal)
            $score = ($hashDistance < 10 ? 0.6 : 0) + ($featureScore * 0.4);

            if ($score >= $sig->confidence_threshold) {
                return new SpamMatch(
                    signature: $sig,
                    confidence: $score,
                    hashDistance: $hashDistance,
                );
            }
        }

        return null;
    }

    private function extractFeatures(string $content): array
    {
        return [
            'has_url' => (bool) preg_match('/https?:\/\//', $content),
            'has_external_email' => (bool) preg_match('/[\w.-]+@[\w.-]+\.\w+/', $content),
            'mentions_money' => $this->intentClassifier->detectIntent($content, 'payment'),
            'mentions_shipping' => (bool) preg_match('/courier|shipping|deliver|post/i', $content),
            'asks_for_contact' => (bool) preg_match('/contact me|email me|call me|whatsapp/i', $content),
            'uses_urgency' => (bool) preg_match('/urgent|asap|quickly|immediately|today/i', $content),
            'greeting_style' => $this->detectGreetingStyle($content), // "dear", "hello friend", etc.
            'language' => $this->detectLanguage($content),
            'length_bucket' => (int) floor(strlen($content) / 100),
        ];
    }
}
```

</details>

### Template Extraction

From multiple similar spam messages, extract the underlying template:

**Input messages**:
```
"Hello dear, I am interested in your sofa. Please contact me at john123@gmail.com"
"Hello friend, I am interested in your table. Please contact me at mary456@gmail.com"
"Hello dear, I am interested in your chairs. Please contact me at bob789@gmail.com"
```

**Extracted template**:
```
"Hello {greeting}, I am interested in your {item}. Please contact me at {external_email}"
```

The LLM can help extract templates and identify which parts are variable vs fixed.

### Simplified UI for Volunteers

**Current (confusing)**:
```
[Report User as Spammer] [Report as Spam] [Report as Scam] [Block User]
```

**Proposed (simple)**:
```
[This is Spam ▼]
```

**After clicking**:
```
✓ Message marked as spam

What kind of spam? (helps block similar messages)
○ Trying to sell / asking for money
○ Romance scam / catfishing
○ Phishing / suspicious links
○ Other / not sure

[Submit] [Skip]
```

The categorisation is optional but helps the system learn faster.

### Spam Team Review Queue

For novel spam patterns (not matching existing signatures):

```
┌─────────────────────────────────────────────────────────┐
│ New Spam Pattern Detected                               │
│ 12 similar messages reported in last 24 hours           │
├─────────────────────────────────────────────────────────┤
│ Sample messages (click to expand):                      │
│ • "Hello dear, I am interested in your sofa..."        │
│ • "Hello friend, I am interested in your table..."     │
│ • "Hello dear, I am interested in your chairs..."      │
├─────────────────────────────────────────────────────────┤
│ Detected patterns:                                      │
│ ✓ Asks to move off-platform (external email)           │
│ ✓ Uses "dear/friend" greeting (common in scams)        │
│ ✓ Generic interest (not specific to item)              │
│ ✓ New accounts (avg age: 2 hours)                      │
├─────────────────────────────────────────────────────────┤
│ Extracted template:                                     │
│ "Hello {greeting}, I am interested in your {item}.     │
│ Please contact me at {external_email}"                  │
├─────────────────────────────────────────────────────────┤
│ [Create Signature] [Not Spam] [Need More Examples]      │
└─────────────────────────────────────────────────────────┘
```

### Escalation & Approval Workflow

**Automated (no approval needed)**:
- Message matches high-confidence signature (95%+)
- Signature has 50+ previous matches with <1% false positive rate
- Message matches Rspamd spam detection

**Escalated to spam team**:
- Novel pattern (first occurrence of this type)
- Creating new signature (needs approval)
- Borderline confidence (70-95%)
- Any appeal from a user

**Graduated response for new patterns**:
1. **First report**: Flag for review, don't auto-block
2. **2-3 reports**: Auto-hide from recipients, queue for spam team
3. **5+ similar reports**: Create candidate signature, spam team reviews
4. **Approved signature**: Immediate block for all future matches

### Spammer vs Spam Distinction

The system distinguishes between:

**Spam (content-based)**:
- The message is problematic
- Sender may be innocent (compromised account) or one-shot
- Block by signature, not by sender
- Focus on preventing similar messages

**Spammer (actor-based)**:
- Persistent bad actor with pattern of abuse
- Worth reporting to central spammer database
- Characteristics that suggest spammer:
  - 3+ spam messages from same account
  - Account created < 24h ago + spam report
  - Multiple groups targeted simultaneously
  - Email domain is known spam source

**Auto-escalation to spammer status**:
```php
if ($user->spam_reports_count >= 3) {
    $this->flagAsPotentialSpammer($user);
}

if ($user->created_at > now()->subDay() && $user->spam_reports_count >= 1) {
    $this->flagAsLikelySpammer($user);
}

if ($this->isKnownSpamDomain($user->email)) {
    $this->flagAsLikelySpammer($user);
}
```

### False Positive Prevention

**Signature quality metrics**:
- Track false positive rate per signature
- Auto-disable signatures with >5% false positive rate
- Require human review before re-enabling

**Appeals process**:
- User can appeal spam classification
- If appeal succeeds:
  - Message is restored
  - Signature is reviewed
  - False positive count increments

**Signature aging**:
- Signatures decay if not triggered for 90 days
- Old unused signatures are deactivated
- Prevents permanent blocking of evolving legitimate content

### Database Schema

<details>
<summary>Spam Signature Tables</summary>

```sql
CREATE TABLE spam_signatures (
    id BIGINT PRIMARY KEY AUTO_INCREMENT,
    fuzzy_hash VARCHAR(64),
    semantic_features JSON,
    template TEXT,
    description TEXT,  -- Human-readable explanation
    spam_type ENUM('selling', 'romance', 'phishing', 'advance_fee', 'off_platform', 'other'),
    confidence_threshold DECIMAL(3,2) DEFAULT 0.85,
    auto_action ENUM('none', 'flag', 'hide', 'block') DEFAULT 'flag',
    match_count INT DEFAULT 0,
    false_positive_count INT DEFAULT 0,
    last_matched_at TIMESTAMP NULL,
    created_by BIGINT,  -- Spam team member who approved
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    status ENUM('proposed', 'active', 'disabled') DEFAULT 'proposed',

    INDEX idx_status (status),
    INDEX idx_fuzzy_hash (fuzzy_hash)
);

CREATE TABLE spam_reports (
    id BIGINT PRIMARY KEY AUTO_INCREMENT,
    message_id BIGINT,
    chat_id BIGINT NULL,
    content_hash VARCHAR(64),  -- For grouping similar reports
    reported_by BIGINT,
    reported_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    spam_type ENUM('selling', 'romance', 'phishing', 'advance_fee', 'off_platform', 'other', 'unknown'),
    matched_signature_id BIGINT NULL,
    resolution ENUM('pending', 'confirmed_spam', 'false_positive', 'needs_review'),
    resolved_by BIGINT NULL,
    resolved_at TIMESTAMP NULL,

    INDEX idx_content_hash (content_hash),
    INDEX idx_resolution (resolution),
    INDEX idx_reported_at (reported_at)
);

CREATE TABLE spam_signature_matches (
    id BIGINT PRIMARY KEY AUTO_INCREMENT,
    signature_id BIGINT,
    message_id BIGINT NULL,
    chat_message_id BIGINT NULL,
    matched_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    confidence_score DECIMAL(3,2),
    action_taken ENUM('flagged', 'hidden', 'blocked'),
    was_false_positive BOOLEAN DEFAULT FALSE,

    INDEX idx_signature_id (signature_id),
    INDEX idx_matched_at (matched_at)
);
```

</details>

### Integration with Content Analysis

The spam signature check integrates into the content analysis pipeline:

```php
class ContentAnalysisService
{
    public function analyse(string $content, string $context = 'message'): AnalysisResult
    {
        // 1. Check spam signatures first (fastest path to block)
        $spamMatch = $this->spamSignatureService->findMatchingSignature($content);
        if ($spamMatch && $spamMatch->signature->auto_action === 'block') {
            return AnalysisResult::blocked(
                reason: 'spam_signature',
                explanation: $spamMatch->signature->description,
                confidence: $spamMatch->confidence,
            );
        }

        // 2. Regulatory keywords
        $regulatory = $this->checkRegulatoryKeywords($content);

        // 3. LLM intent classification
        $llmResult = $this->llmClassifier->classify($content);

        // 4. Combine results
        return new AnalysisResult(
            spamMatch: $spamMatch,  // May be flagged even if not auto-blocked
            regulatoryMatches: $regulatory,
            intents: $llmResult,
        );
    }
}
```

### Summary

| Problem | Solution |
|---------|----------|
| Multiple confusing spam buttons | Single "This is spam" button |
| Disposable emails make blocking pointless | Content signatures instead of sender blocking |
| Spammers vary wording | Fuzzy hashing + semantic features catch variations |
| Volunteers don't know which button to use | Simple UI with optional categorisation |
| Need to identify patterns in spam | Cluster similar reports, extract templates |
| Need approval for new patterns | Spam team review queue with escalation workflow |
| False positives are damaging | Graduated response, appeals, signature aging |
| Need to explain why something was blocked | Signature includes human-readable description |

---

## Content Moderation

> **See also**: [Spam and Content Moderation Rethink](../future/spam-and-content-moderation-rethink.md) for the full discussion of spam detection approaches, ModTools UI changes, and volunteer workflows.

For incoming email, the content analysis service is called after Rspamd:

```php
class ContentAnalysisService
{
    public function analyse(string $content, AnalysisContext $context): AnalysisResult
    {
        // 1. Check spam signatures (fuzzy match against known patterns)
        $spamMatch = $this->spamSignatureService->findMatch($content);

        // 2. Check regulatory keywords (UK legal requirement, ~170 keywords)
        $regulatory = $this->checkRegulatoryKeywords($content);

        // 3. LLM intent classification (money, selling, off-topic)
        $intents = $this->intentClassifier->classify($content);

        return new AnalysisResult(
            spamMatch: $spamMatch,
            regulatoryMatches: $regulatory,
            intents: $intents,
            shouldFlag: $this->shouldFlag($spamMatch, $regulatory, $intents),
            explanation: $this->buildExplanation($spamMatch, $regulatory, $intents),
        );
    }
}
```

This service is shared between:
- Incoming email processing (after Rspamd check)
- Web message posting
- Chat message sending

### Support Tools Content Moderation UI

A dedicated interface for managing content moderation rules with **impact preview** using archived emails.

#### Worry Words Help Modal

When support staff view a message flagged for content moderation, a help modal explains the system:

**Modal Title**: "Content Moderation - How It Works"

**Modal Content**:

> **What are Worry Words?**
>
> Worry words are terms that trigger review of a message before it's posted. They help ensure Freegle complies with UK regulations and safety guidelines.
>
> **Types of Worry Words**:
> - **Regulated**: UK regulated substances (controlled drugs, prescription medicines). Messages are blocked and flagged for moderator review.
> - **Reportable**: UK reportable chemicals (precursors for illegal substances). Messages are blocked and flagged for moderator review.
> - **Medicine**: Medicines and supplements that may be inappropriate to give away. Messages are flagged for review but may be approved.
> - **Review**: General terms that need a human to check (e.g., the £ symbol which may indicate selling). Messages are flagged for review.
>
> **Per-Group Custom Words**:
> Group moderators can add their own worry words via ModTools → Settings → Spammers. These are added to the global list and treated as "Review" type.
>
> **How Matching Works**:
> Words are matched exactly (case-insensitive). The system also allows whitelisted words that remove false positives (e.g., "codeine" triggers review, but "codeine-free" might be whitelisted).
>
> **Money Symbol (£)**:
> Any message containing the £ symbol is automatically flagged for review, as this often indicates attempted selling.

**Modal Actions**:
- "View Global Worry Words" → Link to Support Tools word list
- "View This Group's Custom Words" → Shows group-specific additions
- "Close"

**Key Features**:
1. **Rule Editor**: Add/edit/delete moderation rules with type classification
2. **Impact Preview**: Before saving changes, show how many archived emails would be affected
3. **Test Mode**: Run rules against Piler archive to see matches without affecting production
4. **Per-Group Override View**: See which groups have custom worry words

**Integration with Piler Email Archive**:

Using Postfix's `always_bcc` feature (documented in logging-and-email-tracking-research.md), we can archive all incoming emails to Piler. This enables:
- Testing content moderation changes against real historical data
- Seeing "If we add word X, it would have flagged Y messages in the last 7 days"
- Identifying false positive patterns before deploying changes

### Logging for Content Moderation

Rather than creating new database tables, use the existing Loki logging infrastructure:

```php
Log::channel('incoming_mail')->info('Content moderation match', [
    'message_id' => $messageId,
    'user_id' => $userId,
    'word' => $matchedWord,
    'type' => $ruleType,  // Regulated, Reportable, Medicine, Review
    'group_id' => $groupId,
    'is_group_rule' => $isGroupSpecificRule,
]);
```

Query in Grafana: `{app="iznik-batch", channel="incoming_mail"} |= "Content moderation match"`

---

## Bounce and FBL Processing

### Background: VERP vs No-Reply Approach

**VERP (Variable Envelope Return Path)** - **Current approach in IZNIK server**
- Embeds recipient information in the bounce address (e.g., `bounce-{userid}-{timestamp}@users.ilovefreegle.org`)
- Easy identification of which user's email bounced
- Requires handling many different bounce addresses

**No-Reply Approach** - **What we're moving to in IZNIK batch**
- Uses a single no-reply address for outgoing mail (`noreply@ilovefreegle.org`)
- When bounces occur, extract the original recipient from the DSN message itself
- Simpler routing but requires parsing DSN content

### Bounce Detection at No-Reply Addresses

Since no-reply addresses don't encode the recipient, we need to:

1. **Detect that a message is a bounce**:
   - Check for `Content-Type: multipart/report; report-type=delivery-status`
   - Check for `From: MAILER-DAEMON@` or similar
   - Check for `Auto-Submitted: auto-replied` header

2. **Extract the original recipient from DSN headers**:
   ```
   Final-Recipient: rfc822; original@example.com
   Original-Recipient: rfc822; original@example.com
   ```

3. **Extract bounce details**:
   ```
   Diagnostic-Code: smtp; 550 Requested action not taken: mailbox unavailable
   Status: 5.0.0
   Action: failed
   ```

### No-Reply Address

The specific address to monitor for bounces is: **`noreply@ilovefreegle.org`**

This is used for outgoing mail including chat notifications, welcome emails, and other system messages.

### DSN Format (RFC 3464)

A DSN message is `multipart/report` with three parts:

1. **Human-readable explanation** (text/plain)
2. **Delivery status report** (message/delivery-status)
   - `Reporting-MTA`: The server reporting the failure
   - `Final-Recipient`: The actual failing address
   - `Original-Recipient`: The original envelope recipient
   - `Action`: failed, delayed, delivered, relayed, expanded
   - `Status`: SMTP status code (5.x.x = permanent, 4.x.x = temporary)
   - `Diagnostic-Code`: Human-readable error
3. **Original message** (message/rfc822)

### Non-DSN Compliant Bounces

Not all mail servers follow RFC 3464. Many send simple text bounces that we need to detect heuristically:

**Common non-DSN bounce indicators**:
- Subject contains: "Undeliverable", "Delivery failed", "Mail delivery failed", "Returned mail", "Failure notice"
- From contains: "mailer-daemon", "postmaster", "mail delivery"
- Body contains: "550", "User unknown", "mailbox unavailable", "does not exist", "rejected"

**Extracting recipient from non-DSN bounces**:
- Look for patterns like `<email@example.com>` in the body text
- Look for "Original message" sections and parse the `To:` header
- Look for phrases like "The following address failed:" or "could not be delivered to:"

### Real-World Bounce Edge Cases

Based on research into production bounce handling:

| Edge Case | Challenge | Mitigation |
|-----------|-----------|------------|
| **Missing original message** | RFC doesn't require including original - some MTAs truncate or omit | Fall back to heuristic extraction from bounce body text |
| **Auto-replies vs bounces** | Vacation messages, challenge-response, out-of-office | Check for `Auto-Submitted: auto-replied` header; ignore if present |
| **Challenge-response systems** | EarthLink SpamBlocker, SpamArrest send verification requests | Detect by subject patterns; don't treat as bounces |
| **SMTP transport errors** | Connection refused before message delivered - no DSN generated | Log as transport error, not bounce (different issue) |
| **Partial DSN** | Some MTAs send malformed DSN missing required fields | Multiple extraction strategies with fallbacks |
| **Non-English bounces** | French "Trop de connexions", German "Postfach voll" | Pattern match common non-English phrases |
| **Soft bounce confusion** | "Mailbox full" could be temporary or permanent | Track repeat soft bounces; escalate after threshold |
| **Delayed DSN** | Bounce arrives days after original send | Match by `Original-Message-ID` if present |

### Bounce Storage: File-Based vs Database

**Option A: File-Based Storage (Recommended)**

Given that bounces are:
- Processed immediately upon receipt
- Only need short-term retention (7-30 days for debugging)
- Don't need complex queries

File-based storage is simpler:

```
/var/lib/freegle/bounces/
├── 2025-01-05/
│   ├── 143022_user12345_permanent.json
│   ├── 143156_user67890_temporary.json
│   └── ...
├── 2025-01-04/
│   └── ...
└── summary.json  (aggregate stats for Support Tools)
```

Each bounce file contains:
```json
{
  "timestamp": "2025-01-05T14:30:22Z",
  "envelope_to": "noreply@ilovefreegle.org",
  "original_recipient": "user@example.com",
  "user_id": 12345,
  "diagnostic_code": "550 5.1.1 User unknown",
  "permanent": true,
  "raw_message_path": "raw/2025-01-05/143022.eml"
}
```

**Cleanup via cron**:
```bash
find /var/lib/freegle/bounces -type d -mtime +30 -exec rm -rf {} +
```

**Option B: Database Storage**

Only needed if:
- Complex reporting queries required
- Need to correlate bounces across long time periods
- Integration with existing user management queries

### Bounce Suspension: File-Based Tracking

Track bounce counts per email in a simple JSON file:

```json
// /var/lib/freegle/bounce-counts.json
{
  "user@example.com": {
    "permanent": 2,
    "temporary": 5,
    "last_bounce": "2025-01-05T14:30:22Z",
    "suspended": false
  }
}
```

When permanent count reaches 3 or total reaches 50, mark user as bouncing in the database (single DB update, not per-bounce).

### Robust Bounce Extraction Strategy

Based on research into production bounce handling systems, use a **cascading extraction approach**:

1. **Standard DSN extraction** - `Final-Recipient` and `Original-Recipient` headers
2. **Non-standard DSN patterns** - Various header variations seen in the wild
3. **Body text extraction** - Regex patterns for common bounce message formats
4. **Original message headers** - Extract from embedded `message/rfc822` part
5. **Return-Path analysis** - If using VERP-style patterns (not our case)

**Non-standard header patterns to check**:
- `X-Failed-Recipients:` (common in many MTAs)
- `X-Original-To:` (Postfix)
- `Delivered-To:` (Google)
- `Envelope-To:` (Exim)

**Body text extraction patterns**:
```
/The following address(?:es)? (?:had permanent fatal errors|failed)/i
/Delivery to the following recipient failed permanently/i
/Your message to <([^>]+)> couldn't be delivered/i
/Recipient address rejected: ([^\s]+)/i
/<([^@]+@[^>]+)>\.\.\.(?:\s+)?(?:User|Mailbox) (?:unknown|not found)/i
```

### Collecting Non-Standard Bounce Samples

To ensure comprehensive coverage, we need to collect real-world bounce samples:

1. **Archive all bounces to disk** (even if we can't parse them)
2. **Log extraction failures** with enough context to diagnose
3. **Periodically review** unparseable bounces and add new patterns
4. **Create test fixtures** from real samples (anonymised)

Storage location: `/var/lib/freegle/bounces/unparseable/`

### Laravel BounceService

<details>
<summary>BounceService Implementation</summary>

```php
class BounceService
{
    private const BOUNCE_SUBJECTS = [
        'undeliverable',
        'delivery failed',
        'mail delivery failed',
        'returned mail',
        'failure notice',
        'undelivered mail',
        'delivery status notification',
    ];

    private const BOUNCE_SENDERS = [
        'mailer-daemon',
        'postmaster',
        'mail delivery',
    ];

    public function isBounce(ParsedEmail $email): bool
    {
        $contentType = $email->getHeader('content-type') ?? '';

        // Check for DSN format (RFC 3464 compliant)
        if (str_contains($contentType, 'multipart/report') &&
            str_contains($contentType, 'delivery-status')) {
            return true;
        }

        // Check for common bounce sender patterns (non-DSN)
        $from = strtolower($email->getFrom());
        foreach (self::BOUNCE_SENDERS as $sender) {
            if (str_contains($from, $sender)) {
                return true;
            }
        }

        // Check for common bounce subject patterns (non-DSN)
        $subject = strtolower($email->getSubject() ?? '');
        foreach (self::BOUNCE_SUBJECTS as $pattern) {
            if (str_contains($subject, $pattern)) {
                return true;
            }
        }

        return false;
    }

    public function isDsnCompliant(ParsedEmail $email): bool
    {
        $contentType = $email->getHeader('content-type') ?? '';
        return str_contains($contentType, 'multipart/report') &&
               str_contains($contentType, 'delivery-status');
    }

    public function processBounce(ParsedEmail $email): ?BounceResult
    {
        $rawMessage = $email->getRawMessage();

        // Try DSN-compliant extraction first
        $originalRecipient = $this->extractRecipientFromDsn($rawMessage);

        // Fall back to heuristic extraction for non-DSN bounces
        if (!$originalRecipient) {
            $originalRecipient = $this->extractRecipientHeuristically($rawMessage);
        }

        // Last resort: extract from original message's To: header
        if (!$originalRecipient) {
            $originalRecipient = $this->extractFromOriginalMessage($email);
        }

        if (!$originalRecipient) {
            Log::warning('Bounce received but could not extract recipient', [
                'subject' => $email->getSubject(),
                'from' => $email->getFrom(),
            ]);
            return null;
        }

        // Extract diagnostic code (works for both DSN and some non-DSN)
        $diagnosticCode = null;
        if (preg_match('/^Diagnostic-Code:\s*(.+)$/im', $rawMessage, $m)) {
            $diagnosticCode = trim($m[1]);
        } elseif (preg_match('/^(5\d{2}\s+.{0,100})$/im', $rawMessage, $m)) {
            // Try to extract SMTP error from non-DSN bounce
            $diagnosticCode = trim($m[1]);
        }

        // Extract status code
        $status = null;
        if (preg_match('/^Status:\s*(\d\.\d\.\d)$/im', $rawMessage, $m)) {
            $status = $m[1];
        }

        $isPermanent = $this->isPermanent($diagnosticCode, $status);
        $shouldIgnore = $this->shouldIgnore($diagnosticCode);

        return new BounceResult(
            originalRecipient: $originalRecipient,
            diagnosticCode: $diagnosticCode,
            status: $status,
            isPermanent: $isPermanent,
            shouldIgnore: $shouldIgnore,
        );
    }

    private function extractRecipientFromDsn(string $rawMessage): ?string
    {
        // RFC 3464 DSN headers
        if (preg_match('/^Final-Recipient:\s*rfc822;\s*(.+)$/im', $rawMessage, $m)) {
            return trim($m[1]);
        }
        if (preg_match('/^Original-Recipient:\s*rfc822;\s*(.+)$/im', $rawMessage, $m)) {
            return trim($m[1]);
        }
        return null;
    }

    private function extractRecipientHeuristically(string $rawMessage): ?string
    {
        // Common patterns in non-DSN bounces
        $patterns = [
            // "The following address failed: <user@example.com>"
            '/following address(?:es)? failed[:\s]*<?([^\s<>]+@[^\s<>]+)>?/i',
            // "could not be delivered to: user@example.com"
            '/could not be delivered to[:\s]*<?([^\s<>]+@[^\s<>]+)>?/i',
            // "Delivery to <user@example.com> failed"
            '/delivery to[:\s]*<?([^\s<>]+@[^\s<>]+)>?\s*failed/i',
            // "User unknown: user@example.com"
            '/user unknown[:\s]*<?([^\s<>]+@[^\s<>]+)>?/i',
            // "<user@example.com>: mailbox unavailable"
            '/<([^\s<>]+@[^\s<>]+)>[:\s]*(?:mailbox unavailable|does not exist|rejected)/i',
        ];

        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $rawMessage, $m)) {
                return trim($m[1]);
            }
        }

        return null;
    }

    private function isPermanent(?string $diagnosticCode, ?string $status): bool
    {
        // Status starting with 5 is permanent
        if ($status && str_starts_with($status, '5')) {
            return true;
        }

        // Known permanent error patterns
        $permanentPatterns = [
            '550 Requested action not taken: mailbox unavailable',
            'Invalid recipient',
            '550 5.1.1',
            '550-5.1.1',
            '550 No Such User Here',
            "dd This user doesn't have",
        ];

        foreach ($permanentPatterns as $pattern) {
            if ($diagnosticCode && stripos($diagnosticCode, $pattern) !== false) {
                return true;
            }
        }

        return false;
    }

    private function shouldIgnore(?string $diagnosticCode): bool
    {
        // Temporary issues that shouldn't mark user as bouncing
        $ignorePatterns = [
            'delivery temporarily suspended',
            'Trop de connexions',
            'found on industry URI blacklists',
            'This message has been blocked',
            'is listed',
        ];

        foreach ($ignorePatterns as $pattern) {
            if ($diagnosticCode && stripos($diagnosticCode, $pattern) !== false) {
                return true;
            }
        }

        return false;
    }
}
```

</details>

### Bounce Suspension Logic

<details>
<summary>BounceManager Implementation</summary>

```php
class BounceManager
{
    const PERM_THRESHOLD = 3;    // Permanent bounces before suspension
    const TOTAL_THRESHOLD = 50;  // Total bounces before suspension

    public function recordBounce(BounceResult $bounce): void
    {
        $user = User::findByEmail($bounce->originalRecipient);
        if (!$user) {
            Log::warning('Bounce for unknown email', [
                'email' => $bounce->originalRecipient
            ]);
            return;
        }

        $email = $user->emails()->where('email', $bounce->originalRecipient)->first();
        if (!$email) {
            return;
        }

        // Record bounce
        BounceEmail::create([
            'email_id' => $email->id,
            'reason' => $bounce->diagnosticCode,
            'permanent' => $bounce->isPermanent,
        ]);

        $email->update(['bounced' => now()]);

        // Log for Support Tools visibility
        Log::channel('incoming_mail')->info('Bounce recorded', [
            'user_id' => $user->id,
            'email' => $bounce->originalRecipient,
            'permanent' => $bounce->isPermanent,
            'reason' => $bounce->diagnosticCode,
        ]);
    }

    public function suspendBouncingUsers(): void
    {
        // Suspend users with 3+ permanent bounces on preferred email
        $users = User::whereHas('preferredEmail', function ($q) {
            $q->whereHas('bounces', function ($bq) {
                $bq->where('permanent', true)->where('reset', false);
            }, '>=', self::PERM_THRESHOLD);
        })->where('bouncing', false)->get();

        foreach ($users as $user) {
            $this->suspendUser($user);
        }

        // Suspend users with 50+ total bounces on preferred email
        $users = User::whereHas('preferredEmail', function ($q) {
            $q->whereHas('bounces', function ($bq) {
                $bq->where('reset', false);
            }, '>=', self::TOTAL_THRESHOLD);
        })->where('bouncing', false)->get();

        foreach ($users as $user) {
            $this->suspendUser($user);
        }
    }
}
```

</details>

### FBL (Feedback Loop) Processing

When users mark our email as spam in their email client (Yahoo, Outlook, etc.), we receive an FBL report via the Abuse Reporting Format (ARF).

**Current FBL Address**: `fbl@users.ilovefreegle.org`

**Status**: FBL processing is already working and registrations with major providers are up to date. This functionality needs to be preserved and migrated to IZNIK batch.

**FBL Format** (RFC 5965 - ARF):
```
Content-Type: multipart/report; report-type=feedback-report

Part 1: text/plain - Human readable description
Part 2: message/feedback-report
  - Feedback-Type: abuse
  - Original-Mail-From: <sender@example.com>
  - Original-Rcpt-To: complainant@yahoo.com
  - Received-Date: ...
Part 3: message/rfc822 - Original message (may be truncated)
```

### Laravel FBLService

<details>
<summary>FBLService Implementation</summary>

```php
class FBLService
{
    public function isFBL(ParsedEmail $email): bool
    {
        $contentType = $email->getHeader('content-type');
        return str_contains($contentType, 'feedback-report');
    }

    public function processFBL(ParsedEmail $email): ?FBLResult
    {
        $rawMessage = $email->getRawMessage();

        // Extract recipient who complained
        $complainant = null;
        if (preg_match('/^Original-Rcpt-To:\s*(.+)$/im', $rawMessage, $m)) {
            $complainant = trim($m[1]);
        } elseif (preg_match('/^X-Original-To:\s*(.+);/im', $rawMessage, $m)) {
            $complainant = trim($m[1]);
        }

        if (!$complainant) {
            return null;
        }

        // Find user and stop all mail to them
        $user = User::findByEmail($complainant);
        if ($user) {
            $user->update([
                'simple_mail' => User::SIMPLE_MAIL_NONE,
            ]);
            $user->recordFBL();

            Log::channel('incoming_mail')->info('FBL processed', [
                'user_id' => $user->id,
                'email' => $complainant,
            ]);
        }

        return new FBLResult(
            complainantEmail: $complainant,
            userId: $user?->id,
            processed: $user !== null,
        );
    }
}
```

</details>

### Routing Integration

<details>
<summary>Routing Code for Bounces and FBLs</summary>

```php
// In MailRouterService
public function route(ParsedEmail $email): RoutingResult
{
    $to = $email->getEnvelopeTo();

    // Check for FBL reports first (fbl@users.ilovefreegle.org)
    if (str_starts_with($to, 'fbl@')) {
        return $this->fblService->processFBL($email)
            ? RoutingResult::toSystem('FBL processed')
            : RoutingResult::toSystem('FBL not processed');
    }

    // Check for bounces at no-reply address (noreply@ilovefreegle.org)
    if ($to === 'noreply@ilovefreegle.org') {
        if ($this->bounceService->isBounce($email)) {
            $result = $this->bounceService->processBounce($email);
            if ($result && !$result->shouldIgnore) {
                $this->bounceManager->recordBounce($result);
            }
            return RoutingResult::toSystem('Bounce processed');
        }
        // Non-bounce mail to noreply - just drop it
        return RoutingResult::dropped('Mail to noreply ignored');
    }

    // Continue with normal routing...
}
```

</details>

### Database Tables

**Existing tables to migrate**:
- `bounces` - Raw bounce messages (temporary storage)
- `bounces_emails` - Processed bounce records

**Laravel migrations**:

<details>
<summary>Bounce Table Migrations</summary>

```php
Schema::create('bounces', function (Blueprint $table) {
    $table->id();
    $table->string('envelope_to');
    $table->longText('raw_message');
    $table->timestamps();
    $table->index('created_at');
});

Schema::create('bounce_records', function (Blueprint $table) {
    $table->id();
    $table->foreignId('email_id')->constrained('user_emails');
    $table->text('reason')->nullable();
    $table->boolean('permanent')->default(false);
    $table->boolean('reset')->default(false);
    $table->timestamps();
    $table->index(['email_id', 'permanent', 'reset']);
});
```

</details>

---

## Migration Phases

### Phase 0: Email Template Cleanup (Before Migration)
- [ ] Audit all MGML email templates for legacy mailto: unsubscribe links
- [ ] Remove digestoff, eventsoff, newslettersoff, relevantoff, volunteeringoff, notificationmailsoff mailto: links
- [ ] Replace with links to website settings page (e.g., `https://www.ilovefreegle.org/settings`)
- [ ] Ensure List-Unsubscribe headers use RFC 8058 one-click unsubscribe format

### Phase 1: Foundation (Week 1-2)
- [ ] Create Laravel command structure
- [ ] Implement MailParserService
- [ ] Port database models (Message, Group, User relationships)
- [ ] Set up logs_incoming_mail table
- [ ] Create Support Tools log viewer UI

### Phase 2: Routing Logic (Week 3-4)
- [ ] Implement MailRouterService with all routing outcomes
- [ ] Port email command handling
- [ ] Implement chat routing logic
- [ ] Port group message routing

### Phase 3: Spam & Content Moderation (Week 5-6)
- [ ] Integrate Rspamd via HTTP API
- [ ] Port custom spam checks (IP, subject, country)
- [ ] Implement ContentModerationService (unified worry words + spam keywords)
- [ ] Port all spam detection tests

### Phase 4: Trash Nothing & Attachments (Week 7)
- [ ] Implement TrashNothingService
- [ ] Port TN header handling
- [ ] Implement AttachmentService with TUSD integration
- [ ] Port attachment tests

### Phase 4b: Bounce & FBL Processing (Week 7-8)
- [ ] Implement BounceService with DSN parsing
- [ ] Implement FBLService for feedback loop reports
- [ ] Implement BounceManager with suspension logic
- [ ] Route no-reply bounces through BounceService
- [ ] Migrate existing bounces/bounces_emails tables
- [ ] Port bounce and FBL tests

### Phase 5: Testing & Parallelisation (Week 8-9)
- [ ] Convert all 77 test message files to templates
- [ ] Implement UniqueMailTestData trait
- [ ] Port all 56+ test cases
- [ ] Verify parallel test execution
- [ ] Performance benchmarking

### Phase 6: Integration & Cutover (Week 10)
- [ ] Configure Postfix in Docker
- [ ] Set up dual-running (both systems process, compare results)
- [ ] Gradual traffic migration
- [ ] Full cutover
- [ ] Retire Exim 4 configuration

### Phase 7: Production Deployment
- [ ] Install Rspamd on live server (not containerised in production)
- [ ] Configure Rspamd with same settings as Docker environment
- [ ] Set up Rspamd controller worker for HTTP API access
- [ ] Configure firewall rules for Rspamd ports (11333 for HTTP API, 11334 for controller)
- [ ] Install and configure Postfix on live server
- [ ] Set up Postfix transport maps for Freegle domains
- [ ] Configure Postfix pipe to Laravel artisan command
- [ ] Test end-to-end mail flow on live server
- [ ] DNS updates if required (MX records)

---

## Risk Mitigation

### Phased Migration via Exim Routing

Instead of a hard cutover, use Exim to gradually route different email types to Postfix. Exim remains the front-line MTA, forwarding specific patterns to Postfix running on the same server.

**Exim router configuration** (in `/etc/exim4/conf.d/router/`):

```
# Route bounces to Postfix (Phase 1)
bounce_to_postfix:
  driver = manualroute
  domains = ilovefreegle.org
  local_parts = noreply
  transport = postfix_smtp
  route_list = * 127.0.0.1::2525

# Route FBL reports to Postfix (Phase 2)
fbl_to_postfix:
  driver = manualroute
  domains = users.ilovefreegle.org
  local_parts = fbl
  transport = postfix_smtp
  route_list = * 127.0.0.1::2525

# Route TN chat replies to Postfix (Phase 3)
tn_to_postfix:
  driver = manualroute
  domains = user.trashnothing.com
  transport = postfix_smtp
  route_list = * 127.0.0.1::2525

# Route group messages to Postfix (Phase 4)
groups_to_postfix:
  driver = manualroute
  domains = groups.ilovefreegle.org
  transport = postfix_smtp
  route_list = * 127.0.0.1::2525
```

**Postfix listens on port 2525** (non-standard to avoid conflict with Exim on 25):
```
# In Postfix main.cf
inet_interfaces = 127.0.0.1
smtp_bind_address = 127.0.0.1
# In master.cf
127.0.0.1:2525 inet n - n - - smtpd
```

**Phase sequence**:
| Phase | Email Type | Volume | Risk |
|-------|------------|--------|------|
| 1 | Bounces (noreply@) | ~50/day | Low - no user impact if issues |
| 2 | FBL reports (fbl@) | ~10/day | Low - no user impact |
| 3 | TN chat replies | ~2,650/day | Medium - but TN validates, bypasses spam |
| 4 | Native chat replies | ~400/day | Medium - needs full spam/content analysis |
| 5 | Group messages | ~300/day | Higher - public posts, needs careful testing |

**Benefits of phased approach**:
- Test each category in production before moving to the next
- Easy rollback per category (just disable the Exim router)
- Build confidence incrementally
- Catch edge cases with low-risk traffic first

### Data Integrity
- Compare routing decisions between old and new during parallel phases
- Log all discrepancies for investigation

### Performance
- Benchmark processing time per message
- Target: <500ms per message (current ~200-400ms)
- Monitor queue depth if using queued processing

### Rollback Plan
- Keep Exim 4 configuration available
- Simple transport_maps change to switch back
- No destructive changes to existing PHP code until stable

---

## Configuration

### Environment Variables

```env
# Incoming mail processing
MAIL_INCOMING_ENABLED=true
MAIL_INCOMING_LOG_RAW=true
MAIL_INCOMING_LOG_RETENTION_DAYS=30

# Rspamd (Docker: rspamd container, Production: localhost)
RSPAMD_HOST=rspamd
RSPAMD_PORT=11333
RSPAMD_SPAM_THRESHOLD=8.0

# TUSD
TUS_UPLOADER_URL=http://tusd:1080/files

# Trash Nothing
TN_SECRET=your-secret-here

# Domains
USER_DOMAIN=ilovefreegle.org
GROUP_DOMAIN=groups.ilovefreegle.org
```

---

## Open Questions

### Resolved

1. ~~**Queue vs Sync Processing**~~: Sync processing with Postfix concurrency control (`freegle_destination_concurrency_limit = 4`) provides parallelism with natural flow control.

2. ~~**Postfix Container**~~: Adding Postfix to docker-compose. Configuration documented above.

3. ~~**Legacy Compatibility**~~: Direct cutover approach - no dual-running. Rollback via transport_maps change to Exim.

4. ~~**Spam Keywords**~~: For the initial migration, port current spam detection unchanged (see "Current Spam Detection Module" section). Future improvements are a separate project.

5. ~~**Mail Volume**~~: Measured at ~4,000 messages/day total. ~66% from Trash Nothing (bypasses spam checks), ~34% (~1,350/day) needs full analysis. Peak ~5/minute. Plenty of headroom for 1-2s LLM processing.

### Still Open

1. **Worry Words Consolidation**: Should worry words and spam keywords be merged into a single table with more granular types? (Lower priority now that spam keywords are being removed.)

2. **Non-DSN Bounce Collection**: Need to collect real-world samples of non-DSN compliant bounces during initial deployment. Archive unparseable bounces to `/var/lib/freegle/bounces/unparseable/` and review periodically to add new extraction patterns.

3. **MIME Parser Library**: Current `php-mime-mail-parser` is still maintained (Oct 2025). Consider `zbateson/mail-mime-parser` as alternative without mailparse extension dependency - evaluate during implementation.

---

## References

### Related Planning Documents

- [Spam and Content Moderation Rethink](../future/spam-and-content-moderation-rethink.md) - Unified approach to spam detection, content moderation, ModTools UI, and volunteer workflows

### Rspamd Production Installation

Rspamd runs in Docker for development but needs manual installation on the live server.

<details>
<summary>Rspamd Installation & Configuration</summary>

```bash
# Ubuntu/Debian installation
apt-get install -y lsb-release wget gpg
wget -O- https://rspamd.com/apt-stable/gpg.key | gpg --dearmor > /etc/apt/keyrings/rspamd.gpg
echo "deb [signed-by=/etc/apt/keyrings/rspamd.gpg] https://rspamd.com/apt-stable/ $(lsb_release -cs) main" > /etc/apt/sources.list.d/rspamd.list
apt-get update
apt-get install rspamd
```

**Configuration files to copy from Docker**:
- `conf/rspamd/local.d/worker-controller.inc` → `/etc/rspamd/local.d/`
- Any custom rules or settings

**Key settings** (`/etc/rspamd/local.d/worker-controller.inc`):
```
bind_socket = "*:11334";
password = "your-secure-password";
enable_password = "your-secure-password";
secure_ip = "127.0.0.1";  # Production: restrict to localhost
```

**Service management**:
```bash
systemctl enable rspamd
systemctl start rspamd
systemctl status rspamd
```

</details>

### External Documentation
- [Rspamd Protocol Documentation](https://docs.rspamd.com/developers/protocol/)
- [Laravel Mailbox Package](https://github.com/beyondcode/laravel-mailbox)
- [Postfix Pipe Configuration](https://thecodingmachine.io/triggering-a-php-script-when-your-postfix-server-receives-a-mail)
- [php-mime-mail-parser](https://github.com/php-mime-mail-parser/php-mime-mail-parser)
- [zbateson/mail-mime-parser](https://github.com/zbateson/mail-mime-parser) - Alternative MIME parser without mailparse extension
- [RFC 3464](https://datatracker.ietf.org/doc/html/rfc3464) - Delivery Status Notifications (DSN)
- [RFC 5965](https://datatracker.ietf.org/doc/html/rfc5965) - Abuse Reporting Format (ARF/FBL)
- [RFC 8058](https://datatracker.ietf.org/doc/html/rfc8058) - One-Click Unsubscribe

### Research Sources
- Bounce detection edge cases: Real-world experience with malformed DSNs, auto-replies, challenge-response systems
- Keyword spam detection effectiveness: Modern ML-based filters (Rspamd, SpamAssassin Bayesian) outperform simple keywords
- UK regulated substances: Misuse of Drugs Act 1971, Psychoactive Substances Act 2016, Poisons Act 1972
- Postfix concurrency: `*_destination_concurrency_limit` controls parallel pipe delivery

### Internal Files
- Current MailRouter: `iznik-server/include/mail/MailRouter.php`
- Current Tests: `iznik-server/test/ut/php/include/MailRouterTest.php`
- Test Messages: `iznik-server/test/ut/php/msgs/`
- Rspamd Config: `conf/rspamd/local.d/`
