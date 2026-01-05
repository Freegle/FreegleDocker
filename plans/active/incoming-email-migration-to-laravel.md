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
| Pattern | Action |
|---------|--------|
| `digestoff-{uid}-{gid}@` | Turns off digest |
| `readreceipt-{chatid}-{uid}-{msgid}@` | Chat read receipt |
| `handover-{trystid}-{uid}@` | Calendar event response |
| `eventsoff-{uid}-{gid}@` | Turns off events |
| `newslettersoff-{uid}@` | Turns off newsletters |
| `relevantoff-{uid}@` | Turns off "interested in" |
| `volunteeringoff-{uid}-{gid}@` | Turns off volunteering |
| `notificationmailsoff-{uid}@` | Turns off notifications |
| `{group}-volunteers@` | Mail to moderators |
| `{group}-auto@` | Auto address |
| `{group}-subscribe@` | Subscribe to group |
| `{group}-unsubscribe@` | Unsubscribe from group |
| `unsubscribe-{uid}-{key}-{type}@` | One-click unsubscribe |

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

### Postfix Configuration

**`/etc/postfix/master.cf`**:
```
freegle unix - n n - - pipe
  flags=F user=www-data argv=/usr/bin/php /path/to/iznik-batch/artisan mail:incoming ${sender} ${recipient}
```

**`/etc/postfix/main.cf`**:
```
transport_maps = hash:/etc/postfix/transport
```

**`/etc/postfix/transport`**:
```
groups.ilovefreegle.org    freegle:
user.trashnothing.com      freegle:
@ilovefreegle.org          freegle:
```

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

## Spam Detection Modernisation

### Current State
- **SpamAssassin**: Running on port 783 (traditional)
- **Rspamd**: Already configured in Docker on port 11334 (modern)

### Proposed: Use Rspamd as Primary

**Why Rspamd**:
- Modern, faster, lower resource usage
- Better machine learning integration
- JSON API (easier to integrate)
- Already running in our Docker environment

**Rspamd HTTP API Integration**:
```php
class RspamdService
{
    public function checkMessage(string $rawEmail): RspamdResult
    {
        $response = Http::withBody($rawEmail, 'message/rfc822')
            ->post('http://rspamd:11333/checkv2');

        return new RspamdResult(
            action: $response['action'],  // reject, greylist, add header, no action
            score: $response['score'],
            symbols: $response['symbols'],
        );
    }
}
```

### Worry Words vs Spam Keywords Clarification

After analysing the code:

| Aspect | Worry Words | Spam Keywords |
|--------|-------------|---------------|
| **Purpose** | Legal/safety compliance | Spam/scam detection |
| **Types** | Regulated, Reportable, Medicine, Review, Allowed | Review, Spam, Whitelist |
| **Matching** | Fuzzy (Levenshtein) | Literal or Regex |
| **Outcome** | Flag for review | Block or review |
| **Examples** | "codeine", "tramadol", "£" symbol | "viagra", "weight loss" |

**Recommendation**: Consolidate into a unified `ContentModerationService`:

```php
class ContentModerationService
{
    // Single entry point for all content moderation
    public function checkMessage(Message $message): ContentModerationResult
    {
        $results = collect();

        // 1. Rspamd check (replaces SpamAssassin)
        $results->push($this->rspamd->check($message->getRawEmail()));

        // 2. Regulated content check (worry words)
        $results->push($this->checkRegulatedContent($message));

        // 3. Spam keywords check
        $results->push($this->checkSpamKeywords($message));

        // 4. Custom pattern checks (IP, subject, country)
        $results->push($this->checkPatterns($message));

        return new ContentModerationResult($results);
    }
}
```

---

## Logging Architecture

### Support Tools Logs Tab

A new tab in Support Tools showing:

1. **Incoming Email Log**
   - Timestamp
   - From/To addresses
   - Subject
   - Routing outcome
   - Spam checks triggered
   - Processing time

2. **Filter by**:
   - Email type (group message, chat reply, command, etc.)
   - Routing outcome (approved, pending, spam, dropped)
   - Time range
   - User/Group

### Database Table

```sql
CREATE TABLE logs_incoming_mail (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    received_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    envelope_from VARCHAR(255),
    envelope_to VARCHAR(255),
    from_address VARCHAR(255),
    from_name VARCHAR(255),
    subject VARCHAR(500),
    message_id VARCHAR(255),
    raw_size INT UNSIGNED,

    -- Source tracking
    source ENUM('Email', 'Platform', 'TrashNothing', 'Yahoo') DEFAULT 'Email',
    tn_user_id BIGINT UNSIGNED NULL,
    tn_post_id BIGINT UNSIGNED NULL,

    -- Routing outcome
    routing_result ENUM('Approved', 'Pending', 'Spam', 'Dropped', 'ToUser', 'ToSystem', 'ToVolunteers', 'Receipt', 'Tryst', 'Failure'),

    -- Spam analysis
    rspamd_score DECIMAL(5,2) NULL,
    rspamd_action VARCHAR(50) NULL,
    rspamd_symbols JSON NULL,
    custom_spam_reason VARCHAR(255) NULL,

    -- Content moderation
    worry_words_matched JSON NULL,
    spam_keywords_matched JSON NULL,

    -- Result references
    message_id_created BIGINT UNSIGNED NULL,
    chat_id_created BIGINT UNSIGNED NULL,
    user_id_affected BIGINT UNSIGNED NULL,
    group_id BIGINT UNSIGNED NULL,

    -- Performance
    processing_time_ms INT UNSIGNED,

    INDEX idx_received_at (received_at),
    INDEX idx_routing_result (routing_result),
    INDEX idx_envelope_to (envelope_to),
    INDEX idx_user_id_affected (user_id_affected)
);
```

### Loki Integration

```php
// In IncomingMailService
$this->lokiService->logIncomingMail([
    'envelope_from' => $envelope['from'],
    'envelope_to' => $envelope['to'],
    'routing_result' => $result->getOutcome(),
    'spam_score' => $result->getSpamScore(),
    'processing_time_ms' => $duration,
]);
```

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

## Bounce and FBL Processing

### Background: VERP vs No-Reply Approach

**VERP (Variable Envelope Return Path)** embeds recipient information in the bounce address itself (e.g., `bounce-{userid}-{timestamp}@users.ilovefreegle.org`). This allows easy identification of which user's email bounced.

**No-Reply Approach** (currently in use) uses a single no-reply address for outgoing mail (like chat notifications and welcome emails). When bounces occur, the original recipient must be extracted from the DSN (Delivery Status Notification) message itself, not from the bounce address.

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

### Laravel BounceService

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

### Bounce Suspension Logic

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

### Routing Integration

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

### Database Tables

**Existing tables to migrate**:
- `bounces` - Raw bounce messages (temporary storage)
- `bounces_emails` - Processed bounce records

**Laravel migrations**:

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

---

## Migration Phases

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

---

## Risk Mitigation

### Data Integrity
- Run both systems in parallel initially
- Compare routing decisions between old and new
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

# Rspamd
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

1. **Queue vs Sync Processing**: Should incoming mail be queued or processed synchronously? Recommendation: Sync for now (simplicity), queue later if needed.

2. **Postfix Container**: Add Postfix to docker-compose or use existing mail infrastructure?

3. **Legacy Compatibility**: How long to maintain dual-running capability?

4. **Worry Words Consolidation**: Should worry words and spam keywords be merged into a single table with more granular types?

5. **Non-DSN Bounce Testing**: Need to collect samples of real non-DSN compliant bounces to ensure heuristic extraction patterns cover common cases. Consider logging unprocessable bounces for pattern analysis.

6. **Bounce Fallback Extraction**: If DSN headers (`Final-Recipient`, `Original-Recipient`) are missing, how reliably can we extract the recipient from the embedded original message's `To:` header? May need to handle edge cases where original message is truncated.

---

## References

### External Documentation
- [Rspamd Protocol Documentation](https://docs.rspamd.com/developers/protocol/)
- [Laravel Mailbox Package](https://github.com/beyondcode/laravel-mailbox)
- [Postfix Pipe Configuration](https://thecodingmachine.io/triggering-a-php-script-when-your-postfix-server-receives-a-mail)
- [php-mime-mail-parser](https://github.com/php-mime-mail-parser/php-mime-mail-parser)

### Internal Files
- Current MailRouter: `iznik-server/include/mail/MailRouter.php`
- Current Tests: `iznik-server/test/ut/php/include/MailRouterTest.php`
- Test Messages: `iznik-server/test/ut/php/msgs/`
- Rspamd Config: `conf/rspamd/local.d/`
