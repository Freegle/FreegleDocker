# Bounce Processing System Design

## Overview

This document consolidates the bounce processing design for migrating from iznik-server to iznik-batch (Laravel).

**Key Design Decisions:**
1. **Inline processing** - Bounces processed immediately when received via IncomingMailService
2. **Inline suspension** - User suspension checked immediately after recording a bounce
3. **No separate cron jobs** - If suspension fails, next bounce will trigger another check
4. **Error tracking** - Unparseable bounces saved for debugging, shown as "Error" in UI
5. **Existing schema** - Uses existing database tables, no new tables needed

## Current iznik-server Implementation

### 1. Bounce Reception (handled by Exim)
- Exim receives bounce messages at VERP addresses like `bounce-{userid}-{timestamp}@users.ilovefreegle.org`
- Stores raw message in `bounces` table

### 2. bounce.php cron (Bounce::process)
Runs every minute to process raw bounces:
```php
// Extract user ID from VERP address
preg_match('/^bounce-(.*)-/', $bounce['to'], $matches);
$uid = $matches[1];

// Extract diagnostic code
preg_match('/^Diagnostic-Code:(.*)$/im', $bounce['msg'], $matches);
$code = trim($matches[1]);

// Check if should ignore (temporary issues)
if (!$this->ignore($code)) {
    // Extract recipient email
    preg_match('/^Original-Recipient:.*;(.*)$/im', $bounce['msg'], $matches);
    $email = trim($matches[1]);

    // Record bounce
    INSERT INTO bounces_emails (emailid, reason, permanent) VALUES (?, ?, ?)
    UPDATE users_emails SET bounced = NOW() WHERE email LIKE ?
}

// Delete processed bounce
DELETE FROM bounces WHERE id = ?
```

### 3. bounce_users.php cron (Bounce::suspendMail)
Runs hourly to suspend bouncing users:
```php
// Threshold 1: 3+ permanent bounces on preferred email
SELECT COUNT(*) AS count, userid, email FROM bounces_emails
INNER JOIN users_emails ...
WHERE permanent = 1 AND reset = 0
GROUP BY userid

foreach ($users as $user) {
    if ($user['count'] >= 3 && $user['email'] == preferredEmail) {
        UPDATE users SET bouncing = 1 WHERE id = ?
    }
}

// Threshold 2: 50+ total bounces on preferred email
SELECT COUNT(*) AS count, userid, email FROM bounces_emails ...
WHERE reset = 0
GROUP BY userid

foreach ($users as $user) {
    if ($user['count'] >= 50 && $user['email'] == preferredEmail) {
        UPDATE users SET bouncing = 1 WHERE id = ?
    }
}
```

### Key Ignore Patterns (temporary issues - don't count)
- "delivery temporarily suspended"
- "Trop de connexions" (French: too many connections)
- "found on industry URI blacklists"
- "This message has been blocked"
- "is listed"

### Permanent Bounce Patterns
- "550 Requested action not taken: mailbox unavailable"
- "Invalid recipient"
- "550 5.1.1"
- "550-5.1.1"
- "550 No Such User Here"
- "dd This user doesn't have"

### Reset Mechanism
The `reset` column in `bounces_emails` allows clearing bounce history. This is triggered by:
1. **User reactivation** - When a user reactivates their account after being suspended
2. **Moderator action** - When a moderator manually unsuspends a user

When reset, the bounce records are marked `reset=1` and no longer count towards suspension thresholds.

## Laravel Implementation Design

### Architecture

```
┌─────────────────────────────────────────────────────────────────┐
│                     Inline Bounce Processing                    │
├─────────────────────────────────────────────────────────────────┤
│  Postfix → IncomingMailService.routeBounce()                    │
│         → BounceService.parseBounce()                           │
│         → BounceService.recordBounce()                          │
│         → BounceService.checkAndSuspendUser() ← INLINE          │
│                                                                 │
│  On parse failure → Save to /var/lib/freegle/bounces/error/     │
│                  → Return ERROR outcome for UI visibility       │
└─────────────────────────────────────────────────────────────────┘

No separate cron jobs needed:
- Bounce processing happens inline when mail arrives
- Suspension check happens immediately after recording
- If suspension fails, next bounce triggers another check (natural retry)
```

### Routing Outcomes

| Outcome | Description | UI Category |
|---------|-------------|-------------|
| `TO_SYSTEM` | Successfully processed bounce | Delivered |
| `DROPPED` | Ignored bounce (temporary issue) | Not Delivered |
| `ERROR` | Failed to parse or process | Error |

### Service Classes

#### BounceService (app/Services/Mail/BounceService.php)

Single service responsible for parsing, recording, and suspension checking - all inline:

```php
<?php

namespace App\Services\Mail;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class BounceService
{
    public const PERMANENT_THRESHOLD = 3;  // Permanent bounces before suspension
    public const TOTAL_THRESHOLD = 50;     // Total bounces before suspension

    private const ERROR_STORAGE_PATH = '/var/lib/freegle/bounces/error';

    // Patterns to ignore (temporary issues - don't count towards suspension)
    private const IGNORE_PATTERNS = [
        'delivery temporarily suspended',
        'Trop de connexions',
        'found on industry URI blacklists',
        'This message has been blocked',
        'is listed',
    ];

    // Permanent bounce patterns
    private const PERMANENT_PATTERNS = [
        '550 Requested action not taken: mailbox unavailable',
        'Invalid recipient',
        '550 5.1.1',
        '550-5.1.1',
        '550 No Such User Here',
        "dd This user doesn't have",
        'User unknown',
        'mailbox not found',
        'no such user',
        'invalid address',
        'address rejected',
    ];

    // Non-DSN bounce senders
    private const BOUNCE_SENDERS = [
        'mailer-daemon',
        'postmaster',
        'mail delivery',
    ];

    // Non-DSN bounce subjects
    private const BOUNCE_SUBJECTS = [
        'undeliverable',
        'delivery failed',
        'mail delivery failed',
        'returned mail',
        'failure notice',
        'undelivered mail',
    ];

    /**
     * Process a bounce message inline.
     * Returns routing result with outcome and reason.
     *
     * Called from IncomingMailService when mail arrives at noreply@ or bounce-*@
     */
    public function processBounce(string $to, string $rawMessage): array
    {
        // Check if it's actually a bounce
        if (!$this->isBounce($rawMessage)) {
            return ['outcome' => 'DROPPED', 'reason' => 'Not a bounce message'];
        }

        // Try to parse the bounce
        $diagnosticCode = $this->extractDiagnosticCode($rawMessage);
        if (!$diagnosticCode) {
            $this->saveUnparseableBounce($to, $rawMessage, 'No diagnostic code');
            return ['outcome' => 'ERROR', 'reason' => 'Bounce parse failed: no diagnostic code'];
        }

        // Check if this is a temporary issue we should ignore
        if ($this->shouldIgnore($diagnosticCode)) {
            return ['outcome' => 'DROPPED', 'reason' => 'Temporary bounce ignored: ' . substr($diagnosticCode, 0, 50)];
        }

        // Extract recipient email
        $originalRecipient = $this->extractOriginalRecipient($rawMessage);
        if (!$originalRecipient) {
            $this->saveUnparseableBounce($to, $rawMessage, 'No recipient extractable');
            return ['outcome' => 'ERROR', 'reason' => 'Bounce parse failed: no recipient'];
        }

        // Find user email record
        $userEmail = DB::table('users_emails')
            ->where('email', $originalRecipient)
            ->first();

        if (!$userEmail) {
            return ['outcome' => 'DROPPED', 'reason' => 'Bounce for unknown email: ' . $originalRecipient];
        }

        // Optional: Verify VERP user ID matches (if using VERP addresses)
        $verpUserId = $this->extractUserIdFromVerp($to);
        if ($verpUserId && $userEmail->userid !== $verpUserId) {
            Log::warning('Bounce VERP user ID mismatch', [
                'verp_user_id' => $verpUserId,
                'email_user_id' => $userEmail->userid,
            ]);
            // Continue processing anyway - email match is more reliable
        }

        $isPermanent = $this->isPermanent($diagnosticCode);

        // Record the bounce in bounces_emails
        DB::table('bounces_emails')->insert([
            'emailid' => $userEmail->id,
            'reason' => $diagnosticCode,
            'permanent' => $isPermanent,
            'date' => now(),
        ]);

        // Update users_emails.bounced timestamp
        DB::table('users_emails')
            ->where('id', $userEmail->id)
            ->update(['bounced' => now()]);

        Log::channel('incoming_mail')->info('Bounce recorded', [
            'user_id' => $userEmail->userid,
            'email' => $originalRecipient,
            'permanent' => $isPermanent,
            'reason' => $diagnosticCode,
        ]);

        // INLINE: Check if user should be suspended
        $this->checkAndSuspendUser($userEmail->userid);

        $type = $isPermanent ? 'permanent' : 'temporary';
        return ['outcome' => 'TO_SYSTEM', 'reason' => "Bounce processed ({$type})"];
    }

    /**
     * Check if user should be suspended and suspend if needed.
     * Called inline after recording each bounce.
     */
    public function checkAndSuspendUser(int $userId): bool
    {
        // Get user's current status
        $user = DB::table('users')->where('id', $userId)->first();
        if (!$user || $user->bouncing) {
            return false; // Already suspended or doesn't exist
        }

        // Get preferred email
        $preferredEmail = $this->getPreferredEmail($userId);
        if (!$preferredEmail) {
            return false;
        }

        // Get email ID for preferred email
        $emailId = DB::table('users_emails')
            ->where('userid', $userId)
            ->where('email', $preferredEmail)
            ->value('id');

        if (!$emailId) {
            return false;
        }

        // Count permanent bounces on preferred email (not reset)
        $permanentCount = DB::table('bounces_emails')
            ->where('emailid', $emailId)
            ->where('permanent', 1)
            ->where('reset', 0)
            ->count();

        if ($permanentCount >= self::PERMANENT_THRESHOLD) {
            $this->suspendUser($userId, "3+ permanent bounces ({$permanentCount})");
            return true;
        }

        // Count total bounces on preferred email (not reset)
        $totalCount = DB::table('bounces_emails')
            ->where('emailid', $emailId)
            ->where('reset', 0)
            ->count();

        if ($totalCount >= self::TOTAL_THRESHOLD) {
            $this->suspendUser($userId, "50+ total bounces ({$totalCount})");
            return true;
        }

        return false;
    }

    private function suspendUser(int $userId, string $reason): void
    {
        DB::table('users')->where('id', $userId)->update(['bouncing' => 1]);

        // Log for audit trail
        DB::table('logs')->insert([
            'timestamp' => now(),
            'type' => 'User',
            'subtype' => 'SuspendMail',
            'user' => $userId,
            'text' => $reason,
        ]);

        Log::info('Suspended mail for bouncing user', [
            'user_id' => $userId,
            'reason' => $reason,
        ]);
    }

    private function getPreferredEmail(int $userId): ?string
    {
        // First try to find email marked as preferred
        $preferred = DB::table('users_emails')
            ->where('userid', $userId)
            ->where('preferred', 1)
            ->value('email');

        if ($preferred) {
            return $preferred;
        }

        // Fall back to most recently validated email
        return DB::table('users_emails')
            ->where('userid', $userId)
            ->whereNotNull('validated')
            ->orderByDesc('validated')
            ->value('email');
    }

    /**
     * Detect if a message is a bounce.
     */
    public function isBounce(string $rawMessage): bool
    {
        // Check for DSN content type
        if (preg_match('/Content-Type:\s*multipart\/report.*delivery-status/i', $rawMessage)) {
            return true;
        }

        // Check From header for bounce senders
        if (preg_match('/^From:\s*(.+)$/im', $rawMessage, $m)) {
            $from = strtolower($m[1]);
            foreach (self::BOUNCE_SENDERS as $sender) {
                if (str_contains($from, $sender)) {
                    return true;
                }
            }
        }

        // Check Subject for bounce patterns
        if (preg_match('/^Subject:\s*(.+)$/im', $rawMessage, $m)) {
            $subject = strtolower($m[1]);
            foreach (self::BOUNCE_SUBJECTS as $pattern) {
                if (str_contains($subject, $pattern)) {
                    return true;
                }
            }
        }

        return false;
    }

    private function shouldIgnore(string $diagnosticCode): bool
    {
        $code = strtolower($diagnosticCode);
        foreach (self::IGNORE_PATTERNS as $pattern) {
            if (str_contains($code, strtolower($pattern))) {
                return true;
            }
        }
        return false;
    }

    private function isPermanent(string $diagnosticCode): bool
    {
        $code = strtolower($diagnosticCode);
        foreach (self::PERMANENT_PATTERNS as $pattern) {
            if (str_contains($code, strtolower($pattern))) {
                return true;
            }
        }
        return false;
    }

    private function extractUserIdFromVerp(string $to): ?int
    {
        if (preg_match('/^bounce-(\d+)-/', $to, $matches)) {
            return (int) $matches[1];
        }
        return null;
    }

    private function extractOriginalRecipient(string $rawMessage): ?string
    {
        // Strategy 1: Standard DSN Original-Recipient header
        if (preg_match('/^Original-Recipient:\s*rfc822;\s*(.+)$/im', $rawMessage, $m)) {
            return trim($m[1]);
        }

        // Strategy 2: Final-Recipient header
        if (preg_match('/^Final-Recipient:\s*rfc822;\s*(.+)$/im', $rawMessage, $m)) {
            return trim($m[1]);
        }

        // Strategy 3: X-Failed-Recipients header (common non-standard)
        if (preg_match('/^X-Failed-Recipients:\s*(.+)$/im', $rawMessage, $m)) {
            return trim($m[1]);
        }

        // Strategy 4: Heuristic extraction from body
        $heuristicPatterns = [
            '/following address(?:es)? failed[:\s]*<?([^\s<>]+@[^\s<>]+)>?/i',
            '/could not be delivered to[:\s]*<?([^\s<>]+@[^\s<>]+)>?/i',
            '/<([^\s<>]+@[^\s<>]+)>[:\s]*(?:mailbox unavailable|does not exist)/i',
            '/delivery to the following recipient(?:s)? failed[:\s]*<?([^\s<>]+@[^\s<>]+)>?/i',
        ];

        foreach ($heuristicPatterns as $pattern) {
            if (preg_match($pattern, $rawMessage, $m)) {
                return trim($m[1]);
            }
        }

        return null;
    }

    private function extractDiagnosticCode(string $rawMessage): ?string
    {
        // Standard DSN Diagnostic-Code
        if (preg_match('/^Diagnostic-Code:\s*(.+)$/im', $rawMessage, $m)) {
            return trim($m[1]);
        }

        // Try to extract SMTP error from body
        if (preg_match('/(5\d{2}\s+.{0,100})/i', $rawMessage, $m)) {
            return trim($m[1]);
        }

        return null;
    }

    /**
     * Save unparseable bounce for later analysis.
     */
    private function saveUnparseableBounce(string $to, string $rawMessage, string $reason): void
    {
        $dir = self::ERROR_STORAGE_PATH . '/' . date('Y-m-d');
        if (!is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }

        $filename = date('His') . '_' . substr(md5($rawMessage), 0, 8) . '.json';
        $data = [
            'timestamp' => now()->toIso8601String(),
            'to' => $to,
            'error' => $reason,
            'raw_message' => base64_encode($rawMessage),
        ];

        @file_put_contents($dir . '/' . $filename, json_encode($data, JSON_PRETTY_PRINT));

        Log::warning('Unparseable bounce saved', [
            'to' => $to,
            'reason' => $reason,
            'file' => $dir . '/' . $filename,
        ]);
    }
}

### Integration with IncomingMailService

Bounces are processed inline in `IncomingMailService::route()`:

```php
// In IncomingMailService::route()

// Check for bounces at noreply@ or bounce-*@ addresses
if ($this->isBounceAddress($to)) {
    $result = $this->bounceService->processBounce($to, $rawMessage);
    return $this->createRoutingResult($result['outcome'], $result['reason']);
}

private function isBounceAddress(string $to): bool
{
    $localPart = explode('@', $to)[0] ?? '';
    return $localPart === 'noreply' || str_starts_with($localPart, 'bounce-');
}
```

### No Separate Cron Jobs Needed

**Why no cron?**
- Bounces are processed immediately when mail arrives
- Suspension check happens inline after recording each bounce
- If suspension fails for any reason, the next bounce will trigger another check
- This provides natural retry without additional complexity

**Legacy `bounces` table:**
The legacy `bounces` table was used by iznik-server to queue raw bounce messages for
later processing by the `bounce.php` cron. With inline processing, this table is obsolete.

We do NOT need to process it in parallel or migrate existing entries. Once we cut over
to Laravel, any unprocessed bounces in the old table can be ignored - subsequent bounces
will be processed correctly and trigger suspension checks.

**TODO in code:** Add a reminder to remove the `bounces` table after the migration is
stable (suggest 3 months post-cutover).

## Migration Strategy

### Phase 1: Implement (Week 1)
1. Create BounceService with inline processing and suspension
2. Integrate into IncomingMailService
3. Test with fixtures

### Phase 2: Deploy & Validate (Week 2)
1. Deploy to production
2. Bounces arriving at Laravel are processed inline
3. Monitor error files for unparseable bounces
4. Compare suspension rates with legacy system

### Phase 3: Cutover (Week 3)
1. Route all bounce traffic to Laravel (Postfix/IncomingMailService)
2. Disable iznik-server bounce.php cron
3. Disable iznik-server bounce_users.php cron
4. Ignore any remaining items in legacy `bounces` table (subsequent bounces will work correctly)

### Phase 4: Cleanup (Week 4+)
1. Archive iznik-server bounce code
2. Update documentation
3. Add TODO reminder to drop `bounces` table after 3 months stable operation

## Database Tables (Existing - No New Tables)

All required tables and columns already exist:

### bounces (OBSOLETE - TODO: remove after migration stable)
Legacy table used by iznik-server to queue raw bounce messages.
With inline processing, this table is not used. Any unprocessed entries
can be ignored - subsequent bounces will trigger correct processing.

**TODO:** Drop this table 3 months after migration is stable.

### bounces_emails (permanent bounce records)
```sql
-- Existing table, no changes needed
CREATE TABLE bounces_emails (
    id INT AUTO_INCREMENT PRIMARY KEY,
    emailid INT,              -- FK to users_emails.id
    reason TEXT,              -- Diagnostic code from DSN
    permanent TINYINT(1),     -- 1 = permanent, 0 = temporary
    reset TINYINT(1),         -- 1 = cleared by user/moderator reactivation
    date TIMESTAMP,
    FOREIGN KEY (emailid) REFERENCES users_emails(id)
);
```

### users_emails.bounced (existing column)
Timestamp of most recent bounce. Already exists.

### users.bouncing (existing column)
Flag indicating user is suspended due to bounces. Already exists.

## Testing Strategy

### Unit Tests
1. BounceService::isBounce - Test DSN and non-DSN detection
2. BounceService::shouldIgnore - Test ignore patterns
3. BounceService::isPermanent - Test permanent patterns
4. BounceService::extractOriginalRecipient - Test all extraction strategies
5. BounceManager::suspendBouncingUsers - Test threshold logic

### Integration Tests
1. End-to-end bounce processing
2. User suspension with real bounce counts
3. Preferred email detection

### Test Fixtures
Port the existing test message from `iznik-server/test/ut/php/msgs/bounce` and create additional fixtures for edge cases:
- Standard DSN bounce
- Non-DSN bounce (text-only)
- Bounce with non-English diagnostics
- Bounce missing Original-Recipient (test heuristics)

## Monitoring

### Loki Queries

```logql
# All bounce processing
{app="iznik-batch", channel="incoming_mail"} | json | routing_outcome="Bounce"

# Bounce parsing failures
{app="iznik-batch"} | json | msg=~"Failed to parse bounce.*"

# User suspensions
{app="iznik-batch"} | json | msg="Suspended mail for bouncing user"
```

### Grafana Alerts
1. High bounce parsing failure rate (>10%)
2. Unusual user suspension rate
3. bounces table growing (processing backlog)

## Resolved Questions

1. **VERP vs noreply**: The design handles both. When using VERP addresses (`bounce-{userid}-{timestamp}@`), the user ID is extracted as a hint but we still verify via email lookup. When using noreply addresses, we rely entirely on DSN parsing.

2. **Non-parseable bounces**: Saved to `/var/lib/freegle/bounces/error/YYYY-MM-DD/` for debugging. These appear as "Error" outcome in the UI.

3. **Reset mechanism**: Confirmed - the `reset` column in bounces_emails is set to 1 when:
   - User reactivates their account after being suspended
   - Moderator manually unsuspends a user
   This clears the bounce history so it no longer counts towards thresholds.
