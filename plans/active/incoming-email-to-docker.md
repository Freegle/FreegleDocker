# Incoming Email Migration to Docker (Consolidated Plan)

**Status**: LIVE - Receiving production email via Laravel/iznik-batch
**Merged**: 2026-02-03 (PR #41 bounce-processing merged to master)

## Current Progress (2026-02-03)

### ✅ Complete - Now Live
1. **Postfix container configuration** - `conf/postfix/` with Dockerfile, main.cf, master.cf, transport maps
2. **Laravel HTTP endpoint** - `IncomingMailController` receives mail via HTTP from Postfix
3. **IncomingMailService** - Full routing logic with all 10 outcomes
4. **BounceService** - DSN parsing, bounce classification, inline user suspension
5. **SpamCheckService** - All 18 legacy spam detection features ported
6. **Mail parsing** - `MailParserService` and `ParsedEmail` for MIME handling
7. **Shadow mode validation** - Replay command for testing against archived emails
8. **Production deployment** - Now receiving live email traffic

### 🔄 Remaining Work
- **ModTools UI** - Email statistics dashboard and spam queue UI (Phase F)
- **iznik-server retirement** - Remove legacy incoming.php code (Phase H)

## Executive Summary

This plan consolidates the incoming email migration strategy, covering:
1. **Mail reception** - Postfix container in Docker receiving via MX records
2. **Processing** - Laravel commands in iznik-batch handling routing, spam, bounces
3. **Monitoring** - ModTools UI for email statistics and queue status
4. **Archiving** - MailPit-style mail viewer for production debugging
5. **Switchover** - Phased migration from Exim/iznik-server to Postfix/iznik-batch
6. **Retirement** - Removing code from iznik-server after stable operation

---

## Part 1: Architecture - Incoming Postfix Only

### Current State (Bulk4)
- **MX records** point to bulk4.ilovefreegle.org
- **Exim 4** receives mail on port 25
- **Pipe transport** calls `incoming.php` for each message
- **Processing** via MailRouter in iznik-server

### Current Outgoing Mail
- **Dev/test**: MailPit container captures all mail
- **Production**: External smarthost via `${MAIL_HOST_IP}` (no change planned)

### Architecture Decision: Add Postfix-Incoming Only

We add a dedicated `postfix-incoming` container for receiving mail. Outgoing mail continues via external smarthost.

| Direction | Method | Notes |
|-----------|--------|-------|
| **Incoming** | New `postfix-incoming` container | Port 25, pipes to Laravel |
| **Outgoing** | External smarthost (unchanged) | Already working, no need to change |

**Rationale:**
- Outgoing via smarthost already works reliably
- Only incoming needs to move into Docker
- Simpler - one new container instead of two
- Clear separation of concerns

<details>
<summary><strong>Docker Compose Configuration</strong></summary>

```yaml
postfix-incoming:
  build:
    context: ./conf/postfix-incoming
    dockerfile: Dockerfile
  container_name: freegle-postfix-incoming
  hostname: mail.ilovefreegle.org
  ports:
    - "25:25"
  volumes:
    - postfix-incoming-spool:/var/spool/postfix
    - ./conf/postfix-incoming/main.cf:/etc/postfix/main.cf:ro
    - ./conf/postfix-incoming/master.cf:/etc/postfix/master.cf:ro
    - ./conf/postfix-incoming/transport:/etc/postfix/transport:ro
  environment:
    - BATCH_CONTAINER=batch
  networks:
    - default
  restart: unless-stopped
  depends_on:
    - batch
  profiles:
    - production
```

</details>

<details>
<summary><strong>Postfix Configuration Files</strong></summary>

**main.cf** (core settings):
```
mydestination =
relay_domains = ilovefreegle.org, groups.ilovefreegle.org, users.ilovefreegle.org, user.trashnothing.com
transport_maps = hash:/etc/postfix/transport

# Concurrency limits - max 4 parallel deliveries to Laravel
freegle_destination_concurrency_limit = 4
default_process_limit = 50

# Rate limiting - prevent accepting work faster than we can process
smtpd_client_connection_rate_limit = 50
smtpd_client_message_rate_limit = 100
smtpd_client_connection_count_limit = 10
anvil_rate_time_unit = 60s

# TLS for incoming SMTP (STARTTLS) - Let's Encrypt certs mounted from host
smtpd_tls_cert_file = /etc/letsencrypt/live/mail.ilovefreegle.org/fullchain.pem
smtpd_tls_key_file = /etc/letsencrypt/live/mail.ilovefreegle.org/privkey.pem
smtpd_tls_security_level = may
smtpd_tls_loglevel = 1
```

**TLS Setup**: Use Let's Encrypt with certbot on the Docker host. Mount certs read-only into container:
```yaml
volumes:
  - /etc/letsencrypt/live/mail.ilovefreegle.org:/etc/letsencrypt/live/mail.ilovefreegle.org:ro
  - /etc/letsencrypt/archive/mail.ilovefreegle.org:/etc/letsencrypt/archive/mail.ilovefreegle.org:ro
```

**Why certbot on host (not in container)?**
- **Security**: Running certbot in a container requires elevated permissions (port 80 for HTTP-01 challenge or DNS API keys for DNS-01)
- **Simplicity**: The host likely already has certbot for other services; one renewal mechanism is easier to maintain
- **Best practice**: Certs mounted read-only into containers follows principle of least privilege
- **No container restarts needed**: Postfix picks up new certs on `postfix reload` (SIGHUP)

**Certificate renewal**:
- Certbot auto-renews via systemd timer on the host
- Add post-renewal hook to reload Postfix: `/etc/letsencrypt/renewal-hooks/post/reload-postfix.sh`
  ```bash
  #!/bin/bash
  docker exec freegle-postfix-incoming postfix reload 2>/dev/null || true
  ```

**Domain for certificate**:
- Only `mail.ilovefreegle.org` needs a certificate (the SMTP hostname)
- The other domains (`users.ilovefreegle.org`, `groups.ilovefreegle.org`, `user.trashnothing.com`) are envelope routing domains, not TLS identities
- Current Exim uses a self-signed cert which is fine for opportunistic TLS; Let's Encrypt improves deliverability with stricter senders

**master.cf** (transport definition):
```
freegle unix - n n - 4 pipe
  flags=F user=nobody argv=/usr/local/bin/mail-to-webhook.sh ${sender} ${recipient}
```

**mail-to-webhook.sh** (inside postfix container):
```bash
#!/bin/bash
# Pipe email to Laravel via HTTP webhook
# Exit codes: 0=delivered, 75=defer (Postfix retries)
curl --fail --silent --max-time 30 --data-binary @- \
  -H "Content-Type: message/rfc822" \
  -H "X-Envelope-From: $1" \
  -H "X-Envelope-To: $2" \
  "http://batch/api/mail/incoming"
exit $?
```

**Why HTTP webhook?** The pipe transport runs inside the Postfix container, which can't directly execute `artisan` in the batch container. HTTP webhook allows:
- Clean container separation
- Postfix queues mail if batch is down (curl fails → exit 75 → defer)
- Standard retry/backoff behavior
- Easy monitoring via HTTP response codes

**Email domains** (from iznik-server constants):
| Domain | Defined As | Purpose |
|--------|------------|---------|
| `groups.ilovefreegle.org` | `GROUP_DOMAIN` | Group reply addresses (e.g., `12345-reply@groups.ilovefreegle.org`) |
| `users.ilovefreegle.org` | `USER_DOMAIN` | User notification addresses, email commands |
| `user.trashnothing.com` | Hardcoded | Trash Nothing user addresses (TN integration) |
| `ilovefreegle.org` | Base domain | Catch-all for legacy or admin addresses |

**transport** (domain routing):
```
groups.ilovefreegle.org    freegle:
users.ilovefreegle.org     freegle:
user.trashnothing.com      freegle:
ilovefreegle.org           freegle:
```

</details>

---

## Part 2: Laravel Command Structure

### Entry Point: `mail:incoming`

<details>
<summary><strong>IncomingMailCommand.php</strong></summary>

```php
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

</details>

### Service Structure

```
app/Services/Mail/Incoming/
├── IncomingMailService.php      # Main orchestrator
├── MailParserService.php        # MIME parsing (php-mime-mail-parser)
├── MailRouterService.php        # Routing logic (10 outcomes)
├── BounceService.php            # DSN parsing and processing
├── FBLService.php               # Feedback loop processing
├── SpamCheckService.php         # Orchestrates all spam detection
├── ContentModerationService.php # Worry words + spam keywords
├── TrashNothingService.php      # TN header validation
├── EmailCommandService.php      # Subscribe/unsubscribe commands
└── AttachmentService.php        # TUSD upload handling
```

### Routing Outcomes (from MailRouter)

| Outcome | Description | Action |
|---------|-------------|--------|
| FAILURE | Could not process | Log error, return failure |
| INCOMING_SPAM | Detected as spam | Store in spam queue for review |
| APPROVED | Auto-approved for posting | Create message, notify |
| PENDING | Needs moderation | Create message, notify mods |
| TO_USER | Chat message | Route to chat system |
| TO_SYSTEM | System command | Process command |
| RECEIPT | Read receipt | Update chat message status |
| TRYST | Calendar response | Process meeting response |
| DROPPED | Silently dropped | Log only |
| TO_VOLUNTEERS | To moderators | Route to mod mail |

---

## Part 3: Hybrid Spam Detection

### Three-Layer Approach

Incoming mail passes through multiple spam detection layers:

```
Incoming Email
      │
      ▼
┌─────────────────┐
│ 1. Rspamd       │ ◄── Modern spam filter (phishing, malware, reputation)
└────────┬────────┘     HTTP API on port 11334
         │
         ▼
┌─────────────────┐
│ 2. SpamAssassin │ ◄── Legacy content filter (Bayesian, rules)
└────────┬────────┘     Socket on port 783
         │
         ▼
┌─────────────────┐
│ 3. Freegle      │ ◄── Custom checks (IP, subject dupe, greeting spam)
│    Custom       │     From Spam.php - Freegle-specific patterns
└────────┬────────┘
         │
         ▼
    Route/Flag
```

### Complementary Detection (Not Duplication)

The three layers detect **different types of spam** - they complement each other rather than duplicate:

| Detection Layer | Focus | Examples |
|-----------------|-------|----------|
| **Rspamd** | Content & reputation | Phishing links, malware, sender reputation, DKIM/SPF failures |
| **SpamAssassin** | Content analysis | Bayesian filtering, rule-based patterns, Nigerian prince scams |
| **Freegle Custom** | Network behavior | Same IP posting as 17+ users, cross-posting to 30+ groups, greeting spam targeting Freegle |

**Why Freegle checks are necessary:**
- External filters don't know our network topology (same IP = different users is suspicious for us)
- Cross-posting detection requires knowing our group structure
- Greeting spam with URLs is a Freegle-specific attack pattern
- References to known spammers (our blacklist, not global)
- Domain spoofing using our domain names

### Existing Infrastructure

Both spam services are already running in Docker:
- **SpamAssassin**: `spamassassin-app` container on port 783
- **Rspamd**: `rspamd` container on port 11334

The existing `SpamCheckService` in iznik-batch (`app/Services/SpamCheck/SpamCheckService.php`) already supports both:

<details>
<summary><strong>Existing SpamCheckService Usage</strong></summary>

```php
// Check with both services
$service = new SpamCheckService();
$results = $service->checkAll($rawEmail);

// Individual checks
$rspamdResult = $service->checkRspamd($rawEmail);
$saResult = $service->checkSpamAssassin($rawEmail);
```

Configuration in `config/freegle.php`:
```php
'spam_check' => [
    'enabled' => env('SPAM_CHECK_ENABLED', false),
    'spamassassin_host' => env('SPAMASSASSIN_HOST', 'spamassassin-app'),
    'spamassassin_port' => env('SPAMASSASSIN_PORT', 783),
    'rspamd_host' => env('RSPAMD_HOST', 'rspamd'),
    'rspamd_port' => env('RSPAMD_PORT', 11334),
    'fail_threshold' => env('SPAM_FAIL_THRESHOLD', 5.0),
],
```

</details>

### Custom Freegle Spam Checks

Port from `iznik-server/include/spam/Spam.php`:

| Check | Description | Threshold |
|-------|-------------|-----------|
| IP reputation | Multiple users/groups from same IP | 17 users or 30 groups |
| Subject duplication | Same subject across groups | 30 groups |
| Country blocking | Configurable country blocks | Per-group setting |
| Greeting spam | "hello", "hey" generic patterns | Pattern match |
| DBL URL check | URLs on spam blacklists | Any match |
| Spam keywords | Known spam terms | 311 keywords |
| Worry words | Regulatory compliance | 272 words |
| Domain spoofing | Using our domain in From | Any match |
| Same image | Perceptual hash of attachments | 3+ in 24h |

<details>
<summary><strong>FreegleSpamService Implementation</strong></summary>

```php
class FreegleSpamService
{
    public function checkMessage(ParsedEmail $email): SpamResult
    {
        // Run all custom checks in order
        $checks = [
            $this->checkIpReputation($email->getSenderIp()),
            $this->checkSubjectDuplication($email->getSubject()),
            $this->checkCountry($email->getSenderIp()),
            $this->checkGreetingSpam($email->getTextBody()),
            $this->checkDbl($email->extractUrls()),
            $this->checkSpamKeywords($email->getFullText()),
            $this->checkWorryWords($email->getFullText()),
            $this->checkDomainSpoofing($email->getFromAddress()),
            $this->checkSameImage($email->getAttachments()),
        ];

        foreach ($checks as $result) {
            if ($result->isSpam()) {
                return $result;
            }
        }

        return SpamResult::notSpam();
    }
}
```

</details>

### Combined Spam Check Flow

<details>
<summary><strong>IncomingSpamCheckService</strong></summary>

```php
class IncomingSpamCheckService
{
    private const RSPAMD_THRESHOLD = 8.0;
    private const SA_THRESHOLD = 8.0;

    public function __construct(
        private SpamCheckService $externalChecks,  // Existing service
        private FreegleSpamService $freegleChecks  // New custom checks
    ) {}

    public function check(ParsedEmail $email, bool $bypassExternal = false): SpamCheckResult
    {
        // 1. Freegle-specific checks first (fast, no network)
        $freegleResult = $this->freegleChecks->checkMessage($email);
        if ($freegleResult->isSpam()) {
            return SpamCheckResult::spam($freegleResult->getReason(), $freegleResult->getDetails());
        }

        // 2. Skip external checks for trusted sources (Trash Nothing)
        if ($bypassExternal) {
            return SpamCheckResult::clean();
        }

        // 3. Rspamd check (modern, HTTP-based)
        $rspamdResult = $this->externalChecks->checkRspamd($email->getRawMessage());
        if ($rspamdResult->score >= self::RSPAMD_THRESHOLD) {
            return SpamCheckResult::spam('Rspamd', [
                'score' => $rspamdResult->score,
                'symbols' => $rspamdResult->symbols,
            ]);
        }

        // 4. SpamAssassin check (legacy rules, Bayesian)
        $saResult = $this->externalChecks->checkSpamAssassin($email->getRawMessage());
        if ($saResult->score >= self::SA_THRESHOLD) {
            return SpamCheckResult::spam('SpamAssassin', [
                'score' => $saResult->score,
                'rules' => $saResult->matchedRules,
            ]);
        }

        return SpamCheckResult::clean();
    }
}
```

</details>

### Future Improvements

See `plans/future/spam-and-content-moderation-rethink.md` for planned enhancements:
- Spam signatures with fuzzy hashing
- LLM-based intent detection
- Unified content moderation workflow

---

## Part 3A: Flood Protection

Flood protection is handled at the Postfix level via rate limiting configured in `main.cf`:

```
smtpd_client_connection_rate_limit = 50      # Connections per minute per client
smtpd_client_message_rate_limit = 100        # Messages per minute per client
smtpd_client_connection_count_limit = 10     # Concurrent connections per client
anvil_rate_time_unit = 60s
```

This prevents Postfix from accepting work faster than we can process it. If Laravel processing is slow or down, Postfix queues mail internally and retries with backoff.

### Queue Monitoring

Monitor Postfix queue depth via the `mail:monitor-queue` scheduled command. Alert thresholds:
- **Warning**: Queue > 100 messages
- **Critical**: Queue > 500 messages → Sentry alert

### Cross-Post Flooding

The existing Freegle spam check for "same subject to 30+ groups" (Part 3) handles coordinated cross-post attacks at the application level.

---

## Part 4: Bounce and FBL Processing

### Bounce Detection Strategy

Bounces arrive at `noreply@ilovefreegle.org`. We detect them using:
1. **DSN format** (RFC 3464) - `multipart/report; report-type=delivery-status`
2. **Sender patterns** - mailer-daemon, postmaster
3. **Subject patterns** - "Undeliverable", "Delivery failed", etc.

<details>
<summary><strong>BounceService Implementation</strong></summary>

```php
class BounceService
{
    public function isBounce(ParsedEmail $email): bool
    {
        // Check for DSN format
        $contentType = $email->getHeader('content-type') ?? '';
        if (str_contains($contentType, 'multipart/report') &&
            str_contains($contentType, 'delivery-status')) {
            return true;
        }

        // Check sender patterns
        $from = strtolower($email->getFrom());
        if (str_contains($from, 'mailer-daemon') || str_contains($from, 'postmaster')) {
            return true;
        }

        // Check subject patterns
        $subject = strtolower($email->getSubject() ?? '');
        $patterns = ['undeliverable', 'delivery failed', 'mail delivery failed', 'returned mail'];
        foreach ($patterns as $pattern) {
            if (str_contains($subject, $pattern)) {
                return true;
            }
        }

        return false;
    }

    public function extractRecipient(string $rawMessage): ?string
    {
        // Try DSN-compliant extraction first
        if (preg_match('/^Final-Recipient:\s*rfc822;\s*(.+)$/im', $rawMessage, $m)) {
            return trim($m[1]);
        }
        if (preg_match('/^Original-Recipient:\s*rfc822;\s*(.+)$/im', $rawMessage, $m)) {
            return trim($m[1]);
        }

        // Heuristic extraction for non-DSN bounces
        $patterns = [
            '/following address(?:es)? failed[:\s]*<?([^\s<>]+@[^\s<>]+)>?/i',
            '/could not be delivered to[:\s]*<?([^\s<>]+@[^\s<>]+)>?/i',
            '/<([^\s<>]+@[^\s<>]+)>[:\s]*(?:mailbox unavailable|does not exist)/i',
        ];
        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $rawMessage, $m)) {
                return trim($m[1]);
            }
        }

        return null;
    }

    public function isPermanent(?string $diagnosticCode): bool
    {
        if (!$diagnosticCode) return false;

        $permanent = ['550 ', '5.1.1', 'User unknown', 'mailbox unavailable'];
        foreach ($permanent as $pattern) {
            if (stripos($diagnosticCode, $pattern) !== false) {
                return true;
            }
        }
        return false;
    }
}
```

</details>

### Bounce Suspension Logic

**Important**: Temporary bounces DO count towards suspension thresholds. The current code in `iznik-server/include/mail/Bounce.php` uses two thresholds:

| Threshold | Value | Bounce Type | Effect |
|-----------|-------|-------------|--------|
| `permthreshold` | 3 | Permanent only | Suspend after 3 permanent bounces |
| `allthreshold` | 50 | All (permanent + temporary) | Suspend after 50 total bounces |

**Three categories of bounce:**
1. **Ignored** (`ignore()` returns true): Not recorded at all
   - "delivery temporarily suspended", "Trop de connexions", "found on industry URI blacklists", etc.
2. **Permanent** (`isPermanent()` returns true): Recorded with `permanent=1`
   - "550 Requested action not taken", "550 5.1.1", "User unknown", etc.
3. **Temporary** (not ignored, not permanent): Recorded with `permanent=0`
   - All other bounces (e.g., "mailbox full")

**Suspension only applies to preferred email**: Both queries in `suspendMail()` check that the bouncing email matches `$u->getEmailPreferred()`.

### FBL Processing

FBL reports (when users mark our mail as spam) arrive at `fbl@users.ilovefreegle.org`.

<details>
<summary><strong>FBLService Implementation</strong></summary>

```php
class FBLService
{
    public function isFBL(ParsedEmail $email): bool
    {
        $contentType = $email->getHeader('content-type');
        return str_contains($contentType ?? '', 'feedback-report');
    }

    public function processFBL(ParsedEmail $email): ?FBLResult
    {
        $rawMessage = $email->getRawMessage();

        // Extract complainant
        if (preg_match('/^Original-Rcpt-To:\s*(.+)$/im', $rawMessage, $m)) {
            $complainant = trim($m[1]);
        } else {
            return null;
        }

        // Stop all mail to this user
        $user = User::findByEmail($complainant);
        if ($user) {
            $user->update(['simple_mail' => User::SIMPLE_MAIL_NONE]);
            return new FBLResult($complainant, $user->id, true);
        }

        return new FBLResult($complainant, null, false);
    }
}
```

</details>

---

## Part 5: Spam Review UI in ModTools

### Purpose

Store suspected spam for volunteer review instead of silently discarding. This:
- Recovers false positives
- Shows volunteers the value of spam filtering
- Provides transparency into what's being blocked

### Database Approach: Use Existing Messages Table

**No new table required.** The existing `messages` table already has:
- `spamtype` - enum for spam reason (CountryBlocked, IPUsedForDifferentUsers, etc.)
- `spamreason` - varchar for detailed explanation
- `message` - the raw email content

Spam messages are stored in `messages` with routing outcome `INCOMING_SPAM` and can be queried via:

```sql
SELECT * FROM messages
WHERE spamtype IS NOT NULL
AND lastroute = 'INCOMING_SPAM'
AND arrival > DATE_SUB(NOW(), INTERVAL 7 DAY)
ORDER BY arrival DESC;
```

This follows the same pattern as chat message spam (which uses `chat_messages.reviewrejected`).

### Spam Reason Display

Volunteers frequently ask "Why was this marked as spam?" The display must be human-readable:

| Internal Reason | Display Text | Additional Info |
|-----------------|--------------|-----------------|
| `Rspamd` | "Rspamd spam filter" | "Score: X (threshold: 8)" |
| `SpamAssassin` | "SpamAssassin content filter" | "Score: X, rules: Y" |
| `CountryBlocked` | "Sent from blocked country" | "Country: {country}" |
| `IPUsedForDifferentUsers` | "Suspicious: Same IP, multiple users" | "IP used by X users" |
| `SubjectUsedForDifferentGroups` | "Cross-posted to many groups" | "Subject on X groups" |
| `GreetingSpam` | "Common greeting spam pattern" | "Detected 'hello/hey' pattern" |
| `DBL` | "URL on spam blacklist" | "Blacklisted URL: {url}" |
| `KnownKeyword` | "Known spam keyword detected" | "Keyword: '{keyword}'" |
| `WorryWord` | "Flagged phrase detected" | "Phrase: '{phrase}'" |

<details>
<summary><strong>ModSpamQueue.vue Component</strong></summary>

```vue
<template>
  <div class="spam-queue">
    <h4>Incoming Spam</h4>
    <p class="text-muted">
      Messages blocked by spam filters. Auto-deleted after 7 days.
    </p>

    <b-card v-for="msg in messages" :key="msg.id" class="mb-3">
      <!-- WHY SPAM - Most prominent element -->
      <div class="why-spam-box alert alert-warning mb-3">
        <strong>Why marked as spam?</strong>
        <p class="mb-1 mt-1">{{ getReadableReason(msg.spamtype) }}</p>
        <small v-if="msg.spamreason" class="text-muted d-block">
          {{ msg.spamreason }}
        </small>
      </div>

      <div class="spam-header d-flex justify-content-between">
        <span><strong>From:</strong> {{ msg.fromaddr }}</span>
        <span class="text-muted">{{ formatTime(msg.arrival) }}</span>
      </div>
      <div class="mt-1"><strong>Subject:</strong> {{ msg.subject }}</div>

      <div class="spam-actions mt-3">
        <b-button variant="success" size="sm" @click="approve(msg.id)">
          Not Spam - Deliver
        </b-button>
        <b-button variant="outline-danger" size="sm" @click="reject(msg.id)">
          Confirm Spam
        </b-button>
      </div>
    </b-card>
  </div>
</template>

<script setup>
// Maps spamtype enum values to human-readable explanations
const reasonMap = {
  Rspamd: 'Rspamd spam filter flagged this message',
  SpamAssassin: 'SpamAssassin content filter flagged this message',
  CountryBlocked: 'Message sent from a country we block',
  IPUsedForDifferentUsers: 'Sender IP used by many different users',
  IPUsedForDifferentGroups: 'Sender IP used for many different groups',
  SubjectUsedForDifferentGroups: 'This subject was posted to many groups',
  WorryWord: 'Message contains a phrase requiring review',
}

const getReadableReason = (spamtype) => reasonMap[spamtype] || spamtype
</script>
```

</details>

### Two-Tier Moderation (Chat Review vs Incoming Spam)

| Queue | Source | Confidence | Action Required |
|-------|--------|------------|-----------------|
| **Chat Review** | Website/API messages | Medium suspicion | Mod reviews, can release |
| **Incoming Spam** | All incoming email | High confidence | Optional review, auto-purge |

**Key behavior**: Both chat spam and email spam are **silently black-holed** - spammers receive no indication their message was blocked. This prevents them from learning what triggers detection.

### UI Placement

Add to ModTools left sidebar under Messages:
- **Menu item**: "Incoming Spam" with badge showing pending count
- **Access**: All moderators can view, approve, or reject (spam is often group-related)
- **Retention**: 7 days, then auto-purged

---

## Part 6: ModTools Email Statistics

### API Endpoint

`GET /api/v2/mail/stats` (Go API for speed)

<details>
<summary><strong>Response Format</strong></summary>

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

</details>

### Queue Size Monitoring

The `postfix-incoming` container exposes queue size via a healthcheck script that writes to a shared volume:

```yaml
# docker-compose.yml
postfix-incoming:
  ...
  volumes:
    - postfix-metrics:/var/metrics
  healthcheck:
    test: ["CMD", "/usr/local/bin/check-queue.sh"]
    interval: 60s
```

```bash
# check-queue.sh (inside postfix-incoming)
#!/bin/bash
QSIZE=$(mailq 2>/dev/null | grep -c "^[A-F0-9]" || echo 0)
echo $QSIZE > /var/metrics/queue_size
[ $QSIZE -lt 500 ]  # Exit non-zero if critical
```

The batch container reads `/var/metrics/queue_size` and alerts to Sentry if thresholds exceeded.

---

## Part 7: Mail Archiving

**Deferred to future issue.** For MVP, incoming mail is stored in the `messages` table with 7-day retention for spam queue.

Future options to explore separately:
- [Piler](https://www.mailpiler.org/) for long-term archiving with search
- MailPit for debugging (already used in dev)
- Postfix `always_bcc` to archive all incoming mail

---

## Part 8: Logging Architecture

Following the patterns in `plans/active/logging-tree-architecture.md`, incoming mail logs use consistent structure.

### Log Entry Structure

Server logs without browser context use `[source] job` format:

```json
{
  "timestamp": "2026-01-26T11:30:17.000Z",
  "source": "batch",
  "job": "mail:incoming",
  "channel": "incoming_mail",
  "envelope_from": "sender@example.com",
  "envelope_to": "group-name@groups.ilovefreegle.org",
  "from_address": "sender@example.com",
  "subject": "OFFERED: Sofa (London)",
  "message_id": "<abc123@example.com>",
  "mail_source": "email",
  "routing_result": "APPROVED",
  "spam_checks": {
    "rspamd_score": 2.1,
    "sa_score": 1.5,
    "freegle_result": "clean"
  },
  "processing_time_ms": 156,
  "user_id": 12345,
  "group_id": 67890,
  "message_id_created": 98765
}
```

### Display Format

In ModTools System Logs, incoming mail appears as:

```
11:30:17  [batch] mail:incoming
          APPROVED: "OFFERED: Sofa" from sender@example.com → #98765
```

### All Logs to Loki (No Database Tables)

<details>
<summary><strong>Laravel Logging Implementation</strong></summary>

```php
Log::channel('incoming_mail')->info('Mail processed', [
    // Source identification
    'source' => 'batch',
    'job' => 'mail:incoming',

    // Envelope info
    'envelope_from' => $envelope['from'],
    'envelope_to' => $envelope['to'],

    // Parsed headers
    'from_address' => $parsed->getFromAddress(),
    'subject' => $parsed->getSubject(),
    'message_id' => $parsed->getMessageId(),

    // Mail source (email, platform, trashnothing)
    'mail_source' => $source,

    // Routing result (APPROVED, PENDING, INCOMING_SPAM, etc.)
    'routing_result' => $result->getOutcome(),

    // Spam check details (for debugging)
    'spam_checks' => [
        'rspamd_score' => $rspamdResult?->score,
        'rspamd_action' => $rspamdResult?->action,
        'sa_score' => $saResult?->score,
        'freegle_result' => $freegleResult?->getReason() ?? 'clean',
    ],

    // Performance
    'processing_time_ms' => $duration,

    // References (for correlation)
    'user_id' => $user?->id,
    'group_id' => $group?->id,
    'message_id_created' => $createdMessage?->id,
    'chat_id_created' => $createdChat?->id,
]);
```

</details>

### Loki Queries for Support

```logql
# All mail in last hour
{app="iznik-batch", channel="incoming_mail"} | json

# Filter by outcome
{app="iznik-batch", channel="incoming_mail"} | json | routing_result="INCOMING_SPAM"

# Filter by user
{app="iznik-batch", channel="incoming_mail"} | json | user_id="12345"

# Find high spam scores
{app="iznik-batch", channel="incoming_mail"} | json | spam_checks_rspamd_score > 5

# Bounces
{app="iznik-batch", channel="incoming_mail"} | json | job="mail:process-bounces"
```

---

## Part 9: Switchover Process

### Phase 0: Preparation ✅ COMPLETE
- [x] Email template cleanup (remove legacy mailto: unsubscribe links)
- [x] Deploy Postfix container
- [x] Set up Loki logging channel

### Phase 1-5: Phased Migration ✅ COMPLETE
All email traffic now flows through Laravel/iznik-batch:
- [x] Bounces and FBL reports
- [x] Trash Nothing chat replies
- [x] Native chat replies
- [x] Group messages

### Phase 6: Full Cutover ✅ COMPLETE (2026-02-03)
- [x] All incoming email now processed by Laravel
- [x] Exim forwarding to Postfix active

### Phase 7: Retire iznik-server Code 🔄 PENDING
- [ ] Remove `incoming.php` from Exim pipe configuration
- [ ] Remove from crontab: `bounce.php`, `bounce_users.php`
- [ ] Archive code (don't delete immediately)
- [ ] Update documentation

**Note**: Legacy code should remain in place for 2-4 weeks after stable operation to enable quick rollback if issues arise.

---

## Part 10: Implementation Phases

### Phase A: Foundation ✅ COMPLETE
- [x] Create Postfix container configuration
- [x] Implement `mail:incoming` command (`IncomingMailCommand.php`)
- [x] Implement `MailParserService` and `ParsedEmail`
- [x] Set up Loki logging channel
- [ ] Update architecture documentation (see Documentation Updates below)

### Phase B: Bounce Processing ✅ COMPLETE
- [x] Port `Bounce.php` to Laravel `BounceService`
- [x] Implement DSN parsing and heuristic extraction
- [x] Port bounce tests (74 unit tests)
- [x] Inline bounce processing in `IncomingMailService` (no separate scheduled command needed)
- [x] Link bounce tracking to `email_tracking` via `X-Freegle-Trace-Id`

### Phase C: FBL Processing ✅ COMPLETE
- [x] Implement FBL processing in `IncomingMailService`
- [x] Port FBL tests

### Phase D: Routing Logic ✅ COMPLETE
- [x] Implement routing in `IncomingMailService` with all 10 outcomes
- [x] Port email command handling (subscribe, unsubscribe, read receipts)
- [x] Implement chat and group message routing
- [x] Port routing tests

### Phase E: Spam & Content Moderation ✅ COMPLETE
- [x] Implement `SpamCheckService` with all 18 legacy spam detection features
- [x] IP country blocking, IP reputation, subject reuse detection
- [x] Keyword matching, greeting spam, spammer references
- [x] Spamhaus DBL, SpamAssassin integration, image spam
- [x] 51 unit tests for SpamCheckService

### Phase F: ModTools UI 🔄 NOT STARTED
- [ ] Implement Go API endpoint for mail stats
- [ ] Create `ModEmailStats.vue` component
- [ ] Implement spam queue API endpoints (using existing messages table)
- [ ] Create `ModSpamQueue.vue` component
- [ ] Add Piler deep links for email archive access

### Phase G: Production Deployment ✅ COMPLETE
- [x] Deploy Postfix container
- [x] Phased migration complete - now receiving all email traffic
- [x] Monitoring via existing Loki logging

### Phase H: Retirement 🔄 NOT STARTED
- [ ] Remove iznik-server `incoming.php` from Exim pipe
- [ ] Remove `bounce.php`, `bounce_users.php` from crontab
- [ ] Archive code, update documentation

---

## Part 11: Risk Mitigation

### Rollback Strategy

Each phase can be rolled back independently:
1. **Disable Exim router rule** for that traffic type
2. Traffic returns to iznik-server processing
3. No data loss - mail queued in Postfix if needed

### Monitoring

- **Queue depth**: Alert if > 100 messages
- **Processing errors**: Sentry alerts
- **Routing outcome comparison**: Log discrepancies during parallel operation
- **User complaints**: Support ticket monitoring

---

## Part 12: Configuration

### Environment Variables

```env
# Incoming mail processing
MAIL_INCOMING_ENABLED=true
MAIL_INCOMING_LOG_RAW=true

# Spam checking (uses existing SpamCheckService config)
SPAM_CHECK_ENABLED=true
SPAMASSASSIN_HOST=spamassassin-app
SPAMASSASSIN_PORT=783
RSPAMD_HOST=rspamd
RSPAMD_PORT=11334
SPAM_FAIL_THRESHOLD=8.0

# TUSD (attachments)
TUS_UPLOADER=http://freegle-tusd:8080/tus

# Trash Nothing
TN_SECRET=your-secret-here

# Domains
FREEGLE_USER_DOMAIN=users.ilovefreegle.org
GROUP_DOMAIN=groups.ilovefreegle.org

# Mail archiving
MAILPIT_ARCHIVE_ENABLED=true
```

---

## Part 13: Documentation Updates

The following documentation must be updated as part of this migration:

### Architecture Documentation
- [ ] **`ARCHITECTURE.md`** - Add `postfix-incoming` container to mermaid diagram
  - Add to "Infrastructure" subgraph
  - Show data flow: `postfix-incoming → batch → percona`
  - Add to Container Groups table
- [ ] **`README.md`** - Add postfix-incoming to services list, update mailpit description

### Configuration Documentation
- [ ] **`docker-compose.yml`** - Inline comments explaining postfix-incoming configuration
- [ ] **`.env.example`** - Add new environment variables (MAIL_INCOMING_*, domains)

### Migration Documentation
- [ ] **`iznik-batch/EMAIL-MIGRATION-GUIDE.md`** - Document incoming mail migration lessons
- [ ] **`iznik-server/MIGRATIONS.md`** - Note code retirement timeline

### Operational Documentation
- [ ] **`CLAUDE.md`** - Update session log format, add incoming mail debugging tips
- [ ] **Yesterday/README.md** - Note incoming mail handling differences

---

## References

### Internal Documents
- `plans/future/spam-and-content-moderation-rethink.md` - Future spam detection improvements
- `plans/reference/logging-and-email-tracking-research.md` - Loki research
- `iznik-batch/EMAIL-MIGRATION-GUIDE.md` - Migration lessons learned

### External
- [Postfix Pipe Configuration](https://thecodingmachine.io/triggering-a-php-script-when-your-postfix-server-receives-a-mail)
- [php-mime-mail-parser](https://github.com/php-mime-mail-parser/php-mime-mail-parser)
- [RFC 3464](https://datatracker.ietf.org/doc/html/rfc3464) - DSN format
- [RFC 5965](https://datatracker.ietf.org/doc/html/rfc5965) - FBL/ARF format
- [MailPit](https://github.com/axllent/mailpit) - Mail testing/archiving (dev only)
- [Piler Email Archiver](https://www.mailpiler.org/) - Production email archiving
- [Piler REST API](https://docs.mailpiler.com/piler-ee/restapi/) - Enterprise API documentation
- [Rspamd vs SpamAssassin](https://docs.rspamd.com/about/comparison/) - Feature comparison
- [Email Bomb Prevention](https://guardiandigital.com/resources/blog/distributed-spam-attacks-and-email-bombers) - Flood defense strategies

### Source Code
- `iznik-server/include/mail/MailRouter.php` - Current routing logic
- `iznik-server/include/mail/Bounce.php` - Current bounce processing
- `iznik-server/include/spam/Spam.php` - Custom spam checks to port
- `iznik-batch/app/Services/SpamCheck/SpamCheckService.php` - Existing spam service
- `iznik-server/test/ut/php/include/MailRouterTest.php` - Test cases to port
