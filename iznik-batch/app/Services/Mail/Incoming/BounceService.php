<?php

namespace App\Services\Mail\Incoming;

use App\Models\User;
use App\Models\UserEmail;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Sentry\Laravel\Facade as Sentry;

/**
 * Service for processing email bounce messages.
 *
 * Handles DSN parsing, bounce classification, recording, and user suspension.
 * Designed for inline processing during email routing (no separate cron job).
 *
 * Suspension thresholds (matching legacy iznik-server Bounce.php):
 * - 3+ permanent bounces on preferred email
 * - 50+ total bounces on preferred email
 *
 * TODO: The legacy `bounces` table is obsolete - remove it in a future migration.
 */
class BounceService
{
    // Suspension thresholds (from legacy Bounce.php)
    private const PERMANENT_THRESHOLD = 3;

    private const TOTAL_THRESHOLD = 50;

    // Error directory for unparseable bounces
    private const ERROR_DIRECTORY = '/var/lib/freegle/bounces/error';

    // Patterns that indicate a permanent bounce
    private const PERMANENT_PATTERNS = [
        '550 Requested action not taken: mailbox unavailable',
        'Invalid recipient',
        '550 5.1.1',
        '550-5.1.1',
        '550 No Such User Here',
        "dd This user doesn't have",
    ];

    // Patterns that indicate we should ignore this bounce (temporary/infrastructure issues)
    private const IGNORE_PATTERNS = [
        'delivery temporarily suspended',
        'Trop de connexions',
        'found on industry URI blacklists',
        'This message has been blocked',
        'is listed',
    ];

    /**
     * Process a bounce message from a parsed email.
     *
     * This is the main entry point for bounce processing. It:
     * 1. Parses the DSN to extract diagnostic code and recipient
     * 2. Looks up the user by VERP address or recipient email
     * 3. Records the bounce in bounces_emails
     * 4. Performs inline suspension check
     *
     * @param  ParsedEmail  $email  The parsed bounce message
     * @return array{success: bool, error?: string, user_id?: int, suspended?: bool}
     */
    public function processBounce(ParsedEmail $email): array
    {
        Log::debug('Processing bounce', [
            'envelope_to' => $email->envelopeTo,
        ]);

        // Parse the DSN
        $dsn = $this->parseDsn($email->rawMessage);

        if ($dsn === null) {
            Log::warning('Failed to parse bounce DSN', [
                'envelope_to' => $email->envelopeTo,
            ]);

            $this->saveUnparseableBounce($email);
            $this->reportUnparseableBounce($email);

            return ['success' => false, 'error' => 'unparseable'];
        }

        $diagnosticCode = $dsn['diagnostic_code'];
        $recipientEmail = $dsn['original_recipient'];

        // Check if this should be ignored (temporary/infrastructure issues)
        if ($this->shouldIgnoreBounce($diagnosticCode)) {
            Log::debug('Ignoring temporary bounce', [
                'diagnostic_code' => $diagnosticCode,
            ]);

            return ['success' => true, 'ignored' => true];
        }

        // Try to find the user - first from VERP address, then from recipient email
        $userId = $this->extractUserIdFromVerpAddress($email->envelopeTo);

        // Find the email record
        $userEmail = null;
        if ($userId !== null && $recipientEmail !== null) {
            // VERP address - look up email for this user
            $userEmail = UserEmail::where('userid', $userId)
                ->where('email', $recipientEmail)
                ->first();
        }

        if ($userEmail === null && $recipientEmail !== null) {
            // Fall back to direct email lookup
            $userEmail = UserEmail::where('email', $recipientEmail)->first();
            if ($userEmail !== null) {
                $userId = $userEmail->userid;
            }
        }

        if ($userEmail === null) {
            Log::info('Bounce recipient not found', [
                'recipient' => $recipientEmail,
                'user_id' => $userId,
            ]);

            return ['success' => false, 'error' => 'unknown_recipient'];
        }

        // Classify and record the bounce
        // Primary indicator: DSN Status code (5.x.x = permanent, 4.x.x = temporary)
        // Fallback: diagnostic pattern matching for edge cases
        $isPermanent = $email->isPermanentBounce() || $this->isPermanentBounce($diagnosticCode);
        $this->recordBounce($userEmail->id, $diagnosticCode, $isPermanent);

        // Inline suspension check
        $suspended = $this->checkAndSuspendUser($userEmail->userid);

        Log::info('Processed bounce', [
            'user_id' => $userEmail->userid,
            'email' => $recipientEmail,
            'permanent' => $isPermanent,
            'suspended' => $suspended,
        ]);

        return [
            'success' => true,
            'user_id' => $userEmail->userid,
            'suspended' => $suspended,
        ];
    }

    /**
     * Parse a DSN (Delivery Status Notification) message.
     *
     * Uses cascading extraction strategies:
     * 1. Standard DSN headers (Diagnostic-Code, Original-Recipient)
     * 2. Non-standard headers (X-Failed-Recipients, Final-Recipient)
     * 3. Heuristic body extraction for non-compliant bounces
     *
     * @return array{diagnostic_code: string, original_recipient: ?string}|null
     */
    public function parseDsn(string $rawMessage): ?array
    {
        $diagnosticCode = null;
        $originalRecipient = null;

        // Strategy 1: Standard Diagnostic-Code header
        if (preg_match('/^Diagnostic-Code:\s*(.+)$/im', $rawMessage, $matches)) {
            $diagnosticCode = trim($matches[1]);
        }

        // Strategy 2: Extract from body for non-standard DSNs
        if ($diagnosticCode === null) {
            // Look for 5xx error codes in body
            if (preg_match('/\b(5\d\d[\s\-][\d\.]+\s+[^\r\n]+)/i', $rawMessage, $matches)) {
                $diagnosticCode = trim($matches[1]);
            } elseif (preg_match('/\b(550[^\r\n]*)/i', $rawMessage, $matches)) {
                $diagnosticCode = trim($matches[1]);
            }
        }

        // Strategy 1: Original-Recipient header
        if (preg_match('/^Original-Recipient:\s*(?:rfc822;)?\s*(.+)$/im', $rawMessage, $matches)) {
            $originalRecipient = trim($matches[1]);
        }

        // Strategy 2: Final-Recipient header
        if ($originalRecipient === null) {
            if (preg_match('/^Final-Recipient:\s*(?:rfc822;)?\s*(.+)$/im', $rawMessage, $matches)) {
                $originalRecipient = trim($matches[1]);
            }
        }

        // Strategy 3: X-Failed-Recipients header
        if ($originalRecipient === null) {
            if (preg_match('/^X-Failed-Recipients:\s*(.+)$/im', $rawMessage, $matches)) {
                $originalRecipient = trim($matches[1]);
            }
        }

        // Strategy 4: Extract email from body text
        if ($originalRecipient === null) {
            // Look for "Delivery to the following recipient failed" pattern
            if (preg_match('/recipient.*failed.*?\n\s*([a-zA-Z0-9._%+-]+@[a-zA-Z0-9.-]+\.[a-zA-Z]{2,})/is', $rawMessage, $matches)) {
                $originalRecipient = trim($matches[1]);
            } elseif (preg_match('/\b([a-zA-Z0-9._%+-]+@[a-zA-Z0-9.-]+\.[a-zA-Z]{2,})\b/', $rawMessage, $matches)) {
                // Last resort: any email address in the body
                $originalRecipient = trim($matches[1]);
            }
        }

        // Must have at least a diagnostic code to be considered valid
        if ($diagnosticCode === null) {
            return null;
        }

        return [
            'diagnostic_code' => $diagnosticCode,
            'original_recipient' => $originalRecipient,
        ];
    }

    /**
     * Check if a bounce should be ignored (temporary/infrastructure issues).
     *
     * These patterns indicate the issue is with mail infrastructure, not the
     * recipient's mailbox. We don't want to penalize users for these.
     */
    public function shouldIgnoreBounce(string $diagnosticCode): bool
    {
        $codeLower = strtolower($diagnosticCode);

        foreach (self::IGNORE_PATTERNS as $pattern) {
            if (stripos($codeLower, strtolower($pattern)) !== false) {
                return true;
            }
        }

        return false;
    }

    /**
     * Check if a bounce is permanent (hard bounce).
     *
     * Permanent bounces indicate the mailbox doesn't exist or is permanently
     * unavailable. These count more heavily toward suspension.
     */
    public function isPermanentBounce(string $diagnosticCode): bool
    {
        $codeLower = strtolower($diagnosticCode);

        foreach (self::PERMANENT_PATTERNS as $pattern) {
            if (stripos($codeLower, strtolower($pattern)) !== false) {
                return true;
            }
        }

        return false;
    }

    /**
     * Record a bounce in the bounces_emails table.
     *
     * @param  int  $emailId  The users_emails.id
     * @param  string  $reason  The diagnostic code/reason
     * @param  bool  $isPermanent  Whether this is a permanent bounce
     */
    public function recordBounce(int $emailId, string $reason, bool $isPermanent): void
    {
        DB::table('bounces_emails')->insert([
            'emailid' => $emailId,
            'reason' => $reason,
            'permanent' => $isPermanent ? 1 : 0,
            'reset' => 0,
            'date' => now(),
        ]);

        // Only update users_emails.bounced timestamp for permanent bounces.
        // This matches prior Laravel behavior and is used for display purposes.
        // Temporary bounces are tracked in bounces_emails but don't mark the
        // email as "bounced" since they may be transient issues.
        if ($isPermanent) {
            DB::table('users_emails')
                ->where('id', $emailId)
                ->update(['bounced' => now()]);
        }

        Log::debug('Recorded bounce', [
            'email_id' => $emailId,
            'permanent' => $isPermanent,
        ]);
    }

    /**
     * Check if a user should be suspended due to bounces and suspend if so.
     *
     * Suspension thresholds (only for preferred email):
     * - 3+ permanent bounces
     * - 50+ total bounces
     *
     * @return bool True if user was suspended by this call
     */
    public function checkAndSuspendUser(int $userId): bool
    {
        // Only check users who aren't already suspended
        $user = User::where('id', $userId)
            ->where('bouncing', 0)
            ->first();

        if ($user === null) {
            return false;
        }

        // Get the user's preferred email
        $preferredEmail = UserEmail::where('userid', $userId)
            ->where('preferred', 1)
            ->first();

        if ($preferredEmail === null) {
            // No preferred email - can't check bounces meaningfully
            return false;
        }

        // Count permanent bounces on preferred email (not reset)
        $permanentCount = DB::table('bounces_emails')
            ->where('emailid', $preferredEmail->id)
            ->where('permanent', 1)
            ->where('reset', 0)
            ->count();

        if ($permanentCount >= self::PERMANENT_THRESHOLD) {
            $this->suspendUser($userId);

            return true;
        }

        // Count all bounces on preferred email (not reset)
        $totalCount = DB::table('bounces_emails')
            ->where('emailid', $preferredEmail->id)
            ->where('reset', 0)
            ->count();

        if ($totalCount >= self::TOTAL_THRESHOLD) {
            $this->suspendUser($userId);

            return true;
        }

        return false;
    }

    /**
     * Suspend a user's email delivery.
     */
    private function suspendUser(int $userId): void
    {
        DB::table('users')
            ->where('id', $userId)
            ->update(['bouncing' => 1]);

        Log::info('Suspended user mail due to bounces', [
            'user_id' => $userId,
        ]);
    }

    /**
     * Extract user ID from a VERP bounce address.
     *
     * VERP format: bounce-{userid}-{timestamp}@users.ilovefreegle.org
     *
     * @return int|null The user ID, or null if not a valid VERP address
     */
    public function extractUserIdFromVerpAddress(string $address): ?int
    {
        if (preg_match('/^bounce-(\d+)-/', $address, $matches)) {
            return (int) $matches[1];
        }

        return null;
    }

    /**
     * Save an unparseable bounce to the error directory for later analysis.
     */
    private function saveUnparseableBounce(ParsedEmail $email): void
    {
        if (! is_dir(self::ERROR_DIRECTORY)) {
            @mkdir(self::ERROR_DIRECTORY, 0755, true);
        }

        if (is_dir(self::ERROR_DIRECTORY)) {
            $filename = self::ERROR_DIRECTORY.'/'.date('Y-m-d_His').'_'.uniqid().'.eml';
            @file_put_contents($filename, $email->rawMessage);

            Log::debug('Saved unparseable bounce', [
                'filename' => $filename,
            ]);
        }
    }

    /**
     * Report an unparseable bounce to Sentry for monitoring.
     */
    private function reportUnparseableBounce(ParsedEmail $email): void
    {
        try {
            \Sentry\withScope(function (\Sentry\State\Scope $scope) use ($email): void {
                $scope->setLevel(\Sentry\Severity::warning());
                $scope->setContext('bounce', [
                    'envelope_to' => $email->envelopeTo,
                    'raw_length' => strlen($email->rawMessage),
                    // Include first 500 chars for debugging
                    'raw_preview' => substr($email->rawMessage, 0, 500),
                ]);
                \Sentry\captureMessage('Unparseable bounce DSN');
            });
        } catch (\Throwable $e) {
            // Don't let Sentry failures break bounce processing
            Log::warning('Failed to report unparseable bounce to Sentry', [
                'error' => $e->getMessage(),
            ]);
        }
    }
}
