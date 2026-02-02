<?php

namespace App\Services\Mail\Incoming;

use App\Models\ChatMessage;
use App\Models\ChatRoom;
use App\Models\Group;
use App\Models\Membership;
use App\Models\Message;
use App\Models\User;
use App\Models\UserEmail;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail as MailFacade;

/**
 * Service for routing incoming email messages.
 *
 * This is the main entry point for processing incoming emails.
 * It determines the appropriate handler based on the envelope address
 * and message content.
 *
 * Routing order (matching iznik-server MailRouter.php):
 * 1. System addresses (digestoff, readreceipt, subscribe, etc.)
 * 2. Spam detection
 * 3. Destination-based routing (group, user, volunteers)
 */
class IncomingMailService
{
    // Maximum age for chat replies (84 days = 12 weeks)
    private const STALE_CHAT_DAYS = 84;

    // Maximum age for message replies (42 days = 6 weeks)
    private const EXPIRED_MESSAGE_DAYS = 42;

    // No constructor dependencies - parsing is done by the command before routing

    private SpamCheckService $spamCheck;

    private StripQuotedService $stripQuoted;

    /**
     * Context from the last routing decision (group name, user id, etc.).
     * Set during route() and read by controllers for logging.
     */
    private array $lastRoutingContext = [];

    public function __construct(?SpamCheckService $spamCheck = null, ?StripQuotedService $stripQuoted = null)
    {
        $this->spamCheck = $spamCheck ?? app(SpamCheckService::class);
        $this->stripQuoted = $stripQuoted ?? new StripQuotedService;
    }

    /**
     * Get context from the last routing decision.
     */
    public function getLastRoutingContext(): array
    {
        return $this->lastRoutingContext;
    }

    /**
     * Route a parsed email in dry-run mode (no database changes).
     *
     * This method wraps the normal routing logic in a transaction that
     * always rolls back. All routing decisions are made (including DB reads)
     * but no changes persist. Used for shadow testing the new code against
     * legacy archives.
     *
     * @param  ParsedEmail  $email  The parsed email to route
     * @return RoutingResult The routing outcome (what WOULD have happened)
     */
    public function routeDryRun(ParsedEmail $email): RoutingResult
    {
        $result = null;

        try {
            DB::beginTransaction();

            // Run full routing logic
            $result = $this->route($email);

            // Always rollback - we don't want to persist any changes
            DB::rollBack();
        } catch (\Throwable $e) {
            DB::rollBack();
            throw $e;
        }

        return $result;
    }

    /**
     * Route a parsed email to the appropriate handler.
     *
     * @param  ParsedEmail  $email  The parsed email to route
     * @return RoutingResult The routing outcome
     */
    public function route(ParsedEmail $email): RoutingResult
    {
        $this->lastRoutingContext = [];

        Log::debug('Routing incoming email', [
            'envelope_from' => $email->envelopeFrom,
            'envelope_to' => $email->envelopeTo,
            'subject' => $email->subject,
        ]);

        // Phase 1: Check for system addresses
        $systemResult = $this->routeSystemAddress($email);
        if ($systemResult !== null) {
            return $systemResult;
        }

        // Phase 2: Check for chat/message replies BEFORE global bounce/auto-reply filters.
        // Legacy code (MailRouter.php:267-276) routes notify- and replyto- addresses first,
        // with each handler applying its own nuanced bounce/auto-reply logic internally.
        // The global filters must not intercept these or we'll drop legitimate chat replies
        // from TN autoreplies, Nextdoor bounces, etc.
        if ($email->isChatNotificationReply()) {
            return $this->handleChatNotificationReply($email);
        }

        $replyToResult = $this->handleReplyToAddress($email);
        if ($replyToResult !== null) {
            return $replyToResult;
        }

        // Phase 3: Check for bounces
        if ($email->isBounce()) {
            return $this->handleBounce($email);
        }

        // Phase 3b: Human reply to bounce return-path address (issue #40).
        // Non-DSN emails to bounce-{userid}-{timestamp}@ addresses are human replies
        // that replied to Return-Path instead of Reply-To. Send helpful auto-reply.
        $localPart = explode('@', $email->envelopeTo)[0] ?? '';
        if (str_starts_with($localPart, 'bounce-')) {
            return $this->handleHumanReplyToBounceAddress($email);
        }

        // Check for known dropped senders (Twitter, etc.)
        if ($this->shouldDropSender($email)) {
            return RoutingResult::DROPPED;
        }

        // Check for auto-replies (OOO, vacation, etc.)
        if ($email->isAutoReply()) {
            Log::debug('Dropping auto-reply message');

            return RoutingResult::DROPPED;
        }

        // Check for self-sent messages
        if ($this->isSelfSent($email)) {
            Log::debug('Dropping self-sent message');

            return RoutingResult::DROPPED;
        }

        // Check if sender is a known spammer (skip for volunteers - they go to review instead)
        if (! $email->isToVolunteers && ! $email->isToAuto && $this->isKnownSpammer($email)) {
            Log::debug('Dropping message from known spammer');

            return RoutingResult::DROPPED;
        }

        // Phase 4: Check for volunteer/auto addresses
        if ($email->isToVolunteers || $email->isToAuto) {
            return $this->handleVolunteersMessage($email);
        }

        // Phase 5: Check for group posts
        if ($email->targetGroupName !== null) {
            return $this->handleGroupPost($email);
        }

        // Phase 6: Direct user mail
        return $this->handleDirectMail($email);
    }

    /**
     * Route system addresses (digestoff, readreceipt, subscribe, etc.)
     */
    private function routeSystemAddress(ParsedEmail $email): ?RoutingResult
    {
        $localPart = explode('@', $email->envelopeTo)[0] ?? '';

        // FBL (Feedback Loop) reports
        if ($localPart === 'fbl') {
            return $this->handleFbl($email);
        }

        // Read receipt
        if (str_starts_with($localPart, 'readreceipt-')) {
            return $this->handleReadReceipt($email);
        }

        // Tryst/handover response
        if (str_starts_with($localPart, 'handover-')) {
            return $this->handleTrystResponse($email);
        }

        // Digest off
        if ($email->isDigestOffCommand()) {
            return $this->handleDigestOff($email);
        }

        // Events off
        if (str_starts_with($localPart, 'eventsoff-')) {
            return $this->handleEventsOff($email);
        }

        // Newsletters off
        if (str_starts_with($localPart, 'newslettersoff-')) {
            return $this->handleNewslettersOff($email);
        }

        // Relevant off
        if (str_starts_with($localPart, 'relevantoff-')) {
            return $this->handleRelevantOff($email);
        }

        // Volunteering off
        if (str_starts_with($localPart, 'volunteeringoff-')) {
            return $this->handleVolunteeringOff($email);
        }

        // Notification mails off
        if (str_starts_with($localPart, 'notificationmailsoff-')) {
            return $this->handleNotificationMailsOff($email);
        }

        // One-click unsubscribe
        if (str_starts_with($localPart, 'unsubscribe-')) {
            return $this->handleOneClickUnsubscribe($email);
        }

        // Subscribe command
        if ($email->isSubscribeCommand()) {
            return $this->handleSubscribe($email);
        }

        // Unsubscribe command
        if ($email->isUnsubscribeCommand()) {
            return $this->handleUnsubscribe($email);
        }

        return null;
    }

    /**
     * Check if sender should be dropped (Twitter, etc.)
     */
    private function shouldDropSender(ParsedEmail $email): bool
    {
        $fromAddress = strtolower($email->fromAddress ?? '');

        // Drop Twitter notifications
        if ($fromAddress === 'info@twitter.com') {
            return true;
        }

        return false;
    }

    /**
     * Check if this is a self-sent message (envelope-from == envelope-to).
     *
     * Sending to yourself isn't a valid path, and is used by spammers.
     * This compares envelope addresses, not header addresses.
     */
    private function isSelfSent(ParsedEmail $email): bool
    {
        $envelopeFrom = strtolower($email->envelopeFrom);
        $envelopeTo = strtolower($email->envelopeTo);

        return $envelopeFrom === $envelopeTo && ! empty($envelopeFrom);
    }

    /**
     * Check if sender is a known spammer.
     */
    private function isKnownSpammer(ParsedEmail $email): bool
    {
        $fromAddress = $email->fromAddress;
        if (empty($fromAddress)) {
            return false;
        }

        // Find user by email
        $userEmail = UserEmail::where('email', $fromAddress)->first();
        if ($userEmail === null) {
            return false;
        }

        // Check spam_users table
        return DB::table('spam_users')
            ->where('userid', $userEmail->userid)
            ->where('collection', 'Spammer')
            ->exists();
    }

    /**
     * Handle FBL (Feedback Loop) reports.
     *
     * FBL reports come from email providers when a user marks an email as spam.
     * We extract the original recipient email address and turn off their emails.
     */
    private function handleFbl(ParsedEmail $email): RoutingResult
    {
        Log::info('Processing FBL report');

        $rawMessage = $email->rawMessage;

        // Extract original recipient from various FBL headers
        $recipientEmail = null;
        if (preg_match('/Original-Rcpt-To:\s*(.+)/i', $rawMessage, $matches)) {
            $recipientEmail = trim($matches[1]);
        } elseif (preg_match('/X-Original-To:\s*([^;]+)/i', $rawMessage, $matches)) {
            $recipientEmail = trim($matches[1]);
        } elseif (preg_match('/X-HmXmrOriginalRecipient:\s*(.+)/i', $rawMessage, $matches)) {
            $recipientEmail = trim($matches[1]);
        }

        if ($recipientEmail) {
            Log::info('FBL report for email', ['email' => $recipientEmail]);

            // Find the user
            $userEmail = UserEmail::where('email', $recipientEmail)->first();
            if ($userEmail) {
                $user = User::find($userEmail->userid);
                if ($user) {
                    // Log old values for reversibility.
                    $oldFrequencies = DB::table('memberships')
                        ->where('userid', $user->id)
                        ->pluck('emailfrequency', 'id')
                        ->toArray();

                    // Turn off email digests for all their memberships
                    DB::table('memberships')
                        ->where('userid', $user->id)
                        ->update(['emailfrequency' => 0]);

                    Log::info('Turned off emails due to FBL', [
                        'user_id' => $user->id,
                        'email' => $recipientEmail,
                        'old_frequencies' => $oldFrequencies,
                    ]);
                }
            }
        } else {
            Log::warning('FBL report could not extract recipient email');
        }

        return RoutingResult::TO_SYSTEM;
    }

    /**
     * Handle read receipts.
     *
     * Updates the chat roster to indicate the user has seen messages.
     * Format: readreceipt-{chatid}-{userid}-{msgid}@users.ilovefreegle.org
     */
    private function handleReadReceipt(ParsedEmail $email): RoutingResult
    {
        $localPart = explode('@', $email->envelopeTo)[0] ?? '';

        // Parse readreceipt-{chatid}-{userid}-{msgid}
        if (! preg_match('/^readreceipt-(\d+)-(\d+)-(\d+)$/', $localPart, $matches)) {
            Log::warning('Invalid read receipt address format', [
                'envelope_to' => $email->envelopeTo,
            ]);

            return RoutingResult::DROPPED;
        }

        $chatId = (int) $matches[1];
        $userId = (int) $matches[2];
        $msgId = (int) $matches[3];

        Log::info('Processing read receipt', [
            'chat_id' => $chatId,
            'user_id' => $userId,
            'message_id' => $msgId,
        ]);

        // Update user's last access
        DB::table('users')
            ->where('id', $userId)
            ->update(['lastaccess' => now()]);

        // Check if chat exists
        $chat = ChatRoom::find($chatId);
        if ($chat === null) {
            Log::warning('Read receipt for non-existent chat', [
                'chat_id' => $chatId,
            ]);

            return RoutingResult::DROPPED;
        }

        // Check if user can see the chat
        if (! $this->isUserInChat($userId, $chat)) {
            Log::warning('Read receipt from user not in chat', [
                'chat_id' => $chatId,
                'user_id' => $userId,
            ]);

            return RoutingResult::DROPPED;
        }

        // Update the chat roster to mark messages as seen
        DB::table('chat_roster')
            ->updateOrInsert(
                ['chatid' => $chatId, 'userid' => $userId],
                ['lastmsgseen' => $msgId, 'date' => now()]
            );

        // For user-to-user chats, mark message as seen by all
        if ($chat->chattype === 'User2User') {
            DB::table('chat_messages')
                ->where('id', $msgId)
                ->where('chatid', $chatId)
                ->update(['seenbyall' => 1]);
        }

        Log::info('Processed read receipt', [
            'chat_id' => $chatId,
            'user_id' => $userId,
            'message_id' => $msgId,
        ]);

        return RoutingResult::RECEIPT;
    }

    /**
     * Handle tryst/handover calendar responses.
     *
     * Parses VCALENDAR attachments or subject line to determine accept/decline status.
     * Format: handover-{trystid}-{userid}@users.ilovefreegle.org
     */
    private function handleTrystResponse(ParsedEmail $email): RoutingResult
    {
        $localPart = explode('@', $email->envelopeTo)[0] ?? '';

        // Parse handover-{trystid}-{userid}
        if (! preg_match('/^handover-(\d+)-(\d+)$/', $localPart, $matches)) {
            Log::warning('Invalid handover address format', [
                'envelope_to' => $email->envelopeTo,
            ]);

            return RoutingResult::DROPPED;
        }

        $trystId = (int) $matches[1];
        $userId = (int) $matches[2];

        Log::info('Processing tryst response', [
            'tryst_id' => $trystId,
            'user_id' => $userId,
        ]);

        // Update user's last access
        DB::table('users')
            ->where('id', $userId)
            ->update(['lastaccess' => now()]);

        // Check if tryst exists
        $tryst = DB::table('trysts')->find($trystId);
        if ($tryst === null) {
            Log::warning('Tryst response for non-existent tryst', [
                'tryst_id' => $trystId,
            ]);

            return RoutingResult::DROPPED;
        }

        // Determine response from email content
        $response = $this->parseTrystResponse($email);

        // Update the tryst response in the correct column based on which user is responding
        $updateColumn = null;
        if ($tryst->user1 == $userId) {
            $updateColumn = 'user1response';
        } elseif ($tryst->user2 == $userId) {
            $updateColumn = 'user2response';
        }

        if ($updateColumn !== null) {
            $oldValue = $tryst->$updateColumn;
            DB::table('trysts')
                ->where('id', $trystId)
                ->update([$updateColumn => $response]);

            Log::info('Updated tryst response', [
                'tryst_id' => $trystId,
                'user_id' => $userId,
                'column' => $updateColumn,
                'old_value' => $oldValue,
                'new_value' => $response,
            ]);
        } else {
            Log::warning('Tryst response from user not in tryst', [
                'tryst_id' => $trystId,
                'user_id' => $userId,
                'user1' => $tryst->user1,
                'user2' => $tryst->user2,
            ]);
        }

        Log::info('Processed tryst response', [
            'tryst_id' => $trystId,
            'user_id' => $userId,
            'response' => $response,
        ]);

        return RoutingResult::TRYST;
    }

    /**
     * Parse tryst response from email content.
     *
     * Checks VCALENDAR content in body or subject line for accepted/declined.
     */
    private function parseTrystResponse(ParsedEmail $email): string
    {
        // Check body for VCALENDAR status
        $body = strtolower($email->textBody ?? '');
        if (str_contains($body, 'status:confirmed') || str_contains($body, 'status:tentative')) {
            return 'Accepted';
        }
        if (str_contains($body, 'status:cancelled')) {
            return 'Declined';
        }

        // Check subject line as fallback
        $subject = strtolower($email->subject ?? '');
        if (str_contains($subject, 'accepted')) {
            return 'Accepted';
        }
        if (str_contains($subject, 'declined')) {
            return 'Declined';
        }

        // Default to Other
        return 'Other';
    }

    /**
     * Handle digest off command.
     *
     * Updates the user's membership to turn off email digests (emailfrequency = 0).
     * Format: digestoff-{userid}-{groupid}@users.ilovefreegle.org
     */
    private function handleDigestOff(ParsedEmail $email): RoutingResult
    {
        $userId = $email->commandUserId;
        $groupId = $email->commandGroupId;

        Log::info('Processing digest off command', [
            'user_id' => $userId,
            'group_id' => $groupId,
        ]);

        if (! $userId || ! $groupId) {
            Log::warning('Invalid digest off command - missing user or group ID');

            return RoutingResult::DROPPED;
        }

        // Update user's last access
        DB::table('users')
            ->where('id', $userId)
            ->update(['lastaccess' => now()]);

        // Check if user is an approved member of the group
        $membership = Membership::where('userid', $userId)
            ->where('groupid', $groupId)
            ->where('collection', 'Approved')
            ->first();

        if ($membership === null) {
            Log::warning('User is not an approved member of group', [
                'user_id' => $userId,
                'group_id' => $groupId,
            ]);

            return RoutingResult::DROPPED;
        }

        // Set email frequency to 0 (NEVER)
        $oldFrequency = $membership->emailfrequency;
        $membership->emailfrequency = 0;
        $membership->save();

        Log::info('Turned off digest for user', [
            'user_id' => $userId,
            'group_id' => $groupId,
            'membership_id' => $membership->id,
            'old_emailfrequency' => $oldFrequency,
        ]);

        return RoutingResult::TO_SYSTEM;
    }

    /**
     * Handle events off command.
     *
     * Updates the user's membership to turn off event emails (eventsallowed = 0).
     * Format: eventsoff-{userid}-{groupid}@users.ilovefreegle.org
     */
    private function handleEventsOff(ParsedEmail $email): RoutingResult
    {
        $localPart = explode('@', $email->envelopeTo)[0] ?? '';

        // Parse eventsoff-{userid}-{groupid}
        if (! preg_match('/^eventsoff-(\d+)-(\d+)$/', $localPart, $matches)) {
            Log::warning('Invalid events off address format', [
                'envelope_to' => $email->envelopeTo,
            ]);

            return RoutingResult::DROPPED;
        }

        $userId = (int) $matches[1];
        $groupId = (int) $matches[2];

        Log::info('Processing events off command', [
            'user_id' => $userId,
            'group_id' => $groupId,
        ]);

        // Update user's last access
        DB::table('users')
            ->where('id', $userId)
            ->update(['lastaccess' => now()]);

        // Check if user is an approved member of the group
        $membership = Membership::where('userid', $userId)
            ->where('groupid', $groupId)
            ->where('collection', 'Approved')
            ->first();

        if ($membership === null) {
            Log::warning('User is not an approved member of group', [
                'user_id' => $userId,
                'group_id' => $groupId,
            ]);

            return RoutingResult::DROPPED;
        }

        // Set eventsallowed to 0
        $oldEventsAllowed = $membership->eventsallowed;
        $membership->eventsallowed = 0;
        $membership->save();

        Log::info('Turned off events for user', [
            'user_id' => $userId,
            'group_id' => $groupId,
            'membership_id' => $membership->id,
            'old_eventsallowed' => $oldEventsAllowed,
        ]);

        return RoutingResult::TO_SYSTEM;
    }

    /**
     * Handle newsletters off command.
     *
     * Updates the user's settings to turn off newsletters (newslettersallowed = 0).
     * Format: newslettersoff-{userid}@users.ilovefreegle.org
     */
    private function handleNewslettersOff(ParsedEmail $email): RoutingResult
    {
        $localPart = explode('@', $email->envelopeTo)[0] ?? '';

        // Parse newslettersoff-{userid}
        if (! preg_match('/^newslettersoff-(\d+)$/', $localPart, $matches)) {
            Log::warning('Invalid newsletters off address format', [
                'envelope_to' => $email->envelopeTo,
            ]);

            return RoutingResult::DROPPED;
        }

        $userId = (int) $matches[1];

        Log::info('Processing newsletters off command', [
            'user_id' => $userId,
        ]);

        // Update user's last access
        DB::table('users')
            ->where('id', $userId)
            ->update(['lastaccess' => now()]);

        // Check if user exists
        $user = User::find($userId);
        if ($user === null) {
            Log::warning('User not found for newsletters off', [
                'user_id' => $userId,
            ]);

            return RoutingResult::DROPPED;
        }

        // Set newslettersallowed to 0
        $oldNewslettersAllowed = $user->newslettersallowed;
        $user->newslettersallowed = 0;
        $user->save();

        Log::info('Turned off newsletters for user', [
            'user_id' => $userId,
            'old_newslettersallowed' => $oldNewslettersAllowed,
        ]);

        return RoutingResult::TO_SYSTEM;
    }

    /**
     * Handle relevant off command.
     *
     * Updates the user's settings to turn off "interested in" emails (relevantallowed = 0).
     * Format: relevantoff-{userid}@users.ilovefreegle.org
     */
    private function handleRelevantOff(ParsedEmail $email): RoutingResult
    {
        $localPart = explode('@', $email->envelopeTo)[0] ?? '';

        // Parse relevantoff-{userid}
        if (! preg_match('/^relevantoff-(\d+)$/', $localPart, $matches)) {
            Log::warning('Invalid relevant off address format', [
                'envelope_to' => $email->envelopeTo,
            ]);

            return RoutingResult::DROPPED;
        }

        $userId = (int) $matches[1];

        Log::info('Processing relevant off command', [
            'user_id' => $userId,
        ]);

        // Update user's last access
        DB::table('users')
            ->where('id', $userId)
            ->update(['lastaccess' => now()]);

        // Check if user exists
        $user = User::find($userId);
        if ($user === null) {
            Log::warning('User not found for relevant off', [
                'user_id' => $userId,
            ]);

            return RoutingResult::DROPPED;
        }

        // Set relevantallowed to 0
        $oldRelevantAllowed = $user->relevantallowed;
        $user->relevantallowed = 0;
        $user->save();

        Log::info('Turned off relevant for user', [
            'user_id' => $userId,
            'old_relevantallowed' => $oldRelevantAllowed,
        ]);

        return RoutingResult::TO_SYSTEM;
    }

    /**
     * Handle volunteering off command.
     *
     * Updates the user's membership to turn off volunteering emails (volunteeringallowed = 0).
     * Format: volunteeringoff-{userid}-{groupid}@users.ilovefreegle.org
     */
    private function handleVolunteeringOff(ParsedEmail $email): RoutingResult
    {
        $localPart = explode('@', $email->envelopeTo)[0] ?? '';

        // Parse volunteeringoff-{userid}-{groupid}
        if (! preg_match('/^volunteeringoff-(\d+)-(\d+)$/', $localPart, $matches)) {
            Log::warning('Invalid volunteering off address format', [
                'envelope_to' => $email->envelopeTo,
            ]);

            return RoutingResult::DROPPED;
        }

        $userId = (int) $matches[1];
        $groupId = (int) $matches[2];

        Log::info('Processing volunteering off command', [
            'user_id' => $userId,
            'group_id' => $groupId,
        ]);

        // Update user's last access
        DB::table('users')
            ->where('id', $userId)
            ->update(['lastaccess' => now()]);

        // Check if user is an approved member of the group
        $membership = Membership::where('userid', $userId)
            ->where('groupid', $groupId)
            ->where('collection', 'Approved')
            ->first();

        if ($membership === null) {
            Log::warning('User is not an approved member of group', [
                'user_id' => $userId,
                'group_id' => $groupId,
            ]);

            return RoutingResult::DROPPED;
        }

        // Set volunteeringallowed to 0
        $oldVolunteeringAllowed = $membership->volunteeringallowed;
        $membership->volunteeringallowed = 0;
        $membership->save();

        Log::info('Turned off volunteering for user', [
            'user_id' => $userId,
            'membership_id' => $membership->id,
            'old_volunteeringallowed' => $oldVolunteeringAllowed,
            'group_id' => $groupId,
        ]);

        return RoutingResult::TO_SYSTEM;
    }

    /**
     * Handle notification mails off command.
     *
     * Updates the user's settings JSON to turn off notification mails.
     * Format: notificationmailsoff-{userid}@users.ilovefreegle.org
     */
    private function handleNotificationMailsOff(ParsedEmail $email): RoutingResult
    {
        $localPart = explode('@', $email->envelopeTo)[0] ?? '';

        // Parse notificationmailsoff-{userid}
        if (! preg_match('/^notificationmailsoff-(\d+)$/', $localPart, $matches)) {
            Log::warning('Invalid notification mails off address format', [
                'envelope_to' => $email->envelopeTo,
            ]);

            return RoutingResult::DROPPED;
        }

        $userId = (int) $matches[1];

        Log::info('Processing notification mails off command', [
            'user_id' => $userId,
        ]);

        // Update user's last access
        DB::table('users')
            ->where('id', $userId)
            ->update(['lastaccess' => now()]);

        // Check if user exists
        $user = User::find($userId);
        if ($user === null) {
            Log::warning('User not found for notification mails off', [
                'user_id' => $userId,
            ]);

            return RoutingResult::DROPPED;
        }

        // Get current settings JSON
        $settings = json_decode($user->settings ?? '{}', true) ?: [];

        // Only update if not already off
        if (($settings['notificationmails'] ?? true) === true) {
            $settings['notificationmails'] = false;
            $user->settings = json_encode($settings);
            $user->save();

            Log::info('Turned off notification mails for user', [
                'user_id' => $userId,
                'old_notificationmails' => true,
            ]);
        }

        return RoutingResult::TO_SYSTEM;
    }

    /**
     * Handle one-click unsubscribe (RFC 8058).
     *
     * Puts the user into "limbo" (soft delete) which allows them to recover their account.
     * Format: unsubscribe-{userid}-{key}-{type}@users.ilovefreegle.org
     */
    private function handleOneClickUnsubscribe(ParsedEmail $email): RoutingResult
    {
        $localPart = explode('@', $email->envelopeTo)[0] ?? '';

        // Parse unsubscribe-{userid}-{key}-{type}
        if (! preg_match('/^unsubscribe-(\d+)-([^-]+)-(.+)$/', $localPart, $matches)) {
            Log::warning('Invalid one-click unsubscribe address format', [
                'envelope_to' => $email->envelopeTo,
            ]);

            return RoutingResult::DROPPED;
        }

        $userId = (int) $matches[1];
        $key = $matches[2];
        $type = $matches[3];

        Log::info('Processing one-click unsubscribe', [
            'user_id' => $userId,
            'type' => $type,
        ]);

        // Check if user exists
        $user = User::find($userId);
        if ($user === null) {
            Log::warning('User not found for one-click unsubscribe', [
                'user_id' => $userId,
            ]);

            return RoutingResult::DROPPED;
        }

        // Prevent accidental unsubscription by moderators
        if ($this->isUserModerator($userId)) {
            Log::info('Ignoring one-click unsubscribe for moderator', [
                'user_id' => $userId,
            ]);

            return RoutingResult::DROPPED;
        }

        // Validate the key to prevent spoof unsubscribes
        $storedKey = $this->getUserKey($userId);
        if (! $storedKey || strcasecmp($storedKey, $key) !== 0) {
            Log::warning('Invalid key for one-click unsubscribe', [
                'user_id' => $userId,
            ]);

            return RoutingResult::DROPPED;
        }

        // Log old value for reversibility.
        $oldDeleted = DB::table('users')->where('id', $userId)->value('deleted');

        // Put user into limbo (soft delete)
        DB::table('users')
            ->where('id', $userId)
            ->update(['deleted' => now()]);

        Log::info('Put user into limbo via one-click unsubscribe', [
            'user_id' => $userId,
            'type' => $type,
            'old_deleted' => $oldDeleted,
        ]);

        return RoutingResult::TO_SYSTEM;
    }

    /**
     * Check if user is a moderator on any group.
     */
    private function isUserModerator(int $userId): bool
    {
        return DB::table('memberships')
            ->where('userid', $userId)
            ->whereIn('role', ['Moderator', 'Owner'])
            ->exists();
    }

    /**
     * Get the user's stored key for one-click unsubscribe validation.
     */
    private function getUserKey(int $userId): ?string
    {
        $login = DB::table('users_logins')
            ->where('userid', $userId)
            ->where('type', 'Link')
            ->first();

        return $login?->credentials;
    }

    /**
     * Handle subscribe command.
     *
     * Adds the user to the group. If the user doesn't exist, creates them.
     * Format: {groupname}-subscribe@groups.ilovefreegle.org
     */
    private function handleSubscribe(ParsedEmail $email): RoutingResult
    {
        $localPart = explode('@', $email->envelopeTo)[0] ?? '';

        // Parse {groupname}-subscribe
        if (! preg_match('/^(.+)-subscribe$/', $localPart, $matches)) {
            Log::warning('Invalid subscribe address format', [
                'envelope_to' => $email->envelopeTo,
            ]);

            return RoutingResult::DROPPED;
        }

        $groupName = $matches[1];

        Log::info('Processing subscribe command', [
            'group' => $groupName,
        ]);

        // Find the group
        $group = Group::where('nameshort', $groupName)->first();
        if ($group === null) {
            Log::warning('Subscribe to unknown group', [
                'group' => $groupName,
            ]);

            return RoutingResult::DROPPED;
        }

        // Find or create the user
        $envFrom = $email->envelopeFrom;
        $userEmail = UserEmail::where('email', $envFrom)->first();

        if ($userEmail === null) {
            // Create a new user
            $user = User::create([
                'fullname' => $email->fromName,
                'systemrole' => 'User',
                'added' => now(),
                'lastaccess' => now(),
            ]);

            // Add their email
            UserEmail::create([
                'userid' => $user->id,
                'email' => $envFrom,
                'preferred' => 1,
                'added' => now(),
            ]);

            Log::info('Created new user for subscribe', [
                'user_id' => $user->id,
                'email' => $envFrom,
                'created_new' => true,
            ]);
        } else {
            $user = User::find($userEmail->userid);
            if ($user === null) {
                Log::warning('User email exists but user not found', [
                    'email' => $envFrom,
                ]);

                return RoutingResult::DROPPED;
            }

            // Update last access
            $user->lastaccess = now();
            $user->save();
        }

        // Check if already a member
        $existingMembership = Membership::where('userid', $user->id)
            ->where('groupid', $group->id)
            ->first();

        if ($existingMembership !== null) {
            Log::info('User is already a member', [
                'user_id' => $user->id,
                'group_id' => $group->id,
            ]);

            return RoutingResult::TO_SYSTEM;
        }

        // Add membership
        $membership = Membership::create([
            'userid' => $user->id,
            'groupid' => $group->id,
            'role' => 'Member',
            'collection' => 'Approved',
            'added' => now(),
            'emailfrequency' => 24, // Daily digest by default
        ]);

        Log::info('Added user to group', [
            'user_id' => $user->id,
            'group_id' => $group->id,
            'group_name' => $groupName,
            'membership_id' => $membership->id,
            'created_new' => true,
        ]);

        return RoutingResult::TO_SYSTEM;
    }

    /**
     * Handle unsubscribe command.
     *
     * Removes the user from the group. Moderators/owners are protected from
     * accidental unsubscription.
     * Format: {groupname}-unsubscribe@groups.ilovefreegle.org
     */
    private function handleUnsubscribe(ParsedEmail $email): RoutingResult
    {
        $localPart = explode('@', $email->envelopeTo)[0] ?? '';

        // Parse {groupname}-unsubscribe
        if (! preg_match('/^(.+)-unsubscribe$/', $localPart, $matches)) {
            Log::warning('Invalid unsubscribe address format', [
                'envelope_to' => $email->envelopeTo,
            ]);

            return RoutingResult::DROPPED;
        }

        $groupName = $matches[1];

        Log::info('Processing unsubscribe command', [
            'group' => $groupName,
        ]);

        // Find the group
        $group = Group::where('nameshort', $groupName)->first();
        if ($group === null) {
            Log::warning('Unsubscribe from unknown group', [
                'group' => $groupName,
            ]);

            return RoutingResult::DROPPED;
        }

        // Find the user by envelope from
        $envFrom = $email->envelopeFrom;
        $userEmail = UserEmail::where('email', $envFrom)->first();

        if ($userEmail === null) {
            Log::warning('Unsubscribe from unknown user', [
                'email' => $envFrom,
            ]);

            return RoutingResult::DROPPED;
        }

        $user = User::find($userEmail->userid);
        if ($user === null) {
            Log::warning('User email exists but user not found', [
                'email' => $envFrom,
            ]);

            return RoutingResult::DROPPED;
        }

        // Update last access
        $user->lastaccess = now();
        $user->save();

        // Check if user is a mod or owner of this group - protect them
        $membership = Membership::where('userid', $user->id)
            ->where('groupid', $group->id)
            ->first();

        if ($membership === null) {
            Log::info('User is not a member of group', [
                'user_id' => $user->id,
                'group_id' => $group->id,
            ]);

            return RoutingResult::DROPPED;
        }

        if (in_array($membership->role, ['Moderator', 'Owner'])) {
            Log::info('Ignoring unsubscribe for moderator/owner', [
                'user_id' => $user->id,
                'group_id' => $group->id,
                'role' => $membership->role,
            ]);

            return RoutingResult::DROPPED;
        }

        // Log full membership for reversibility before deletion.
        Log::info('Removing membership (saving state for rollback)', [
            'membership_id' => $membership->id,
            'user_id' => $user->id,
            'group_id' => $group->id,
            'role' => $membership->role,
            'collection' => $membership->collection,
            'emailfrequency' => $membership->emailfrequency,
            'eventsallowed' => $membership->eventsallowed,
            'volunteeringallowed' => $membership->volunteeringallowed,
            'ourPostingStatus' => $membership->ourPostingStatus,
        ]);

        // Remove membership
        $membership->delete();

        Log::info('Removed user from group', [
            'user_id' => $user->id,
            'group_id' => $group->id,
            'group_name' => $groupName,
        ]);

        return RoutingResult::TO_SYSTEM;
    }

    /**
     * Handle bounce messages.
     */
    private function handleBounce(ParsedEmail $email): RoutingResult
    {
        Log::info('Processing bounce', [
            'recipient' => $email->bounceRecipient,
            'status' => $email->bounceStatus,
            'permanent' => $email->isPermanentBounce(),
        ]);

        if ($email->isPermanentBounce() && $email->bounceRecipient) {
            // Extract user ID from bounce address if present
            $localPart = explode('@', $email->envelopeTo)[0] ?? '';
            if (preg_match('/^bounce-(\d+)-/', $localPart, $matches)) {
                $this->recordBounce((int) $matches[1], $email->bounceRecipient);
            } else {
                // Bounce delivered to a group/other address — look up recipient directly.
                // This handles cases where the original email was sent from a group address
                // rather than a bounce- address (issue #39).
                $userEmail = DB::table('users_emails')
                    ->where('email', $email->bounceRecipient)
                    ->first();

                if ($userEmail) {
                    $this->recordBounce($userEmail->userid, $email->bounceRecipient);
                } else {
                    Log::info('Bounce recipient not found in users_emails', [
                        'recipient' => $email->bounceRecipient,
                    ]);
                }
            }
        }

        return RoutingResult::TO_SYSTEM;
    }

    /**
     * Record a bounce against a user's email.
     *
     * The bounced column stores a timestamp (when the bounce was recorded),
     * not a count. Setting it marks the email as having bounced.
     */
    private function recordBounce(int $userId, string $emailAddress): void
    {
        // Log old value for reversibility.
        $existing = DB::table('users_emails')
            ->where('userid', $userId)
            ->where('email', $emailAddress)
            ->first();

        $oldBounced = $existing?->bounced;

        DB::table('users_emails')
            ->where('userid', $userId)
            ->where('email', $emailAddress)
            ->update([
                'bounced' => now(),
            ]);

        Log::info('Recorded bounce for user', [
            'user_id' => $userId,
            'email' => $emailAddress,
            'old_bounced' => $oldBounced,
        ]);
    }

    /**
     * Handle human replies to bounce return-path addresses (issue #40).
     *
     * Some email clients reply to Return-Path instead of Reply-To. When this
     * happens, the reply goes to bounce-{userid}-{timestamp}@users... which is
     * only meant for DSN processing. We send a helpful auto-reply explaining
     * how to reach Freegle properly.
     */
    private function handleHumanReplyToBounceAddress(ParsedEmail $email): RoutingResult
    {
        $senderAddress = strtolower($email->fromAddress ?? $email->envelopeFrom ?? '');

        Log::info('Human reply to bounce address', [
            'from' => $senderAddress,
            'envelope_to' => $email->envelopeTo,
            'subject' => $email->subject,
        ]);

        // Loop prevention: never auto-reply to these senders
        $suppressPatterns = ['mailer-daemon', 'postmaster', 'noreply', 'no-reply', 'bounce-'];
        foreach ($suppressPatterns as $pattern) {
            if (str_contains($senderAddress, $pattern)) {
                Log::debug('Suppressing auto-reply to system address', ['from' => $senderAddress]);

                return RoutingResult::TO_SYSTEM;
            }
        }

        // Loop prevention: never auto-reply to auto-submitted messages
        if ($email->isAutoReply()) {
            Log::debug('Suppressing auto-reply to auto-submitted message');

            return RoutingResult::TO_SYSTEM;
        }

        // Rate limit: max 1 auto-reply per 24h per sender
        $cacheKey = 'bounce_autoreply:'.md5($senderAddress);
        if (Cache::has($cacheKey)) {
            Log::debug('Rate limiting auto-reply', ['from' => $senderAddress]);

            return RoutingResult::TO_SYSTEM;
        }

        // Send auto-reply
        try {
            MailFacade::to($senderAddress)->send(new \App\Mail\BounceAddressAutoReply($senderAddress));
            Cache::put($cacheKey, true, now()->addHours(24));
            Log::info('Sent bounce address auto-reply', ['to' => $senderAddress]);
        } catch (\Throwable $e) {
            Log::warning('Failed to send bounce address auto-reply', [
                'to' => $senderAddress,
                'error' => $e->getMessage(),
            ]);
        }

        return RoutingResult::TO_SYSTEM;
    }

    /**
     * Handle reply-to addresses (replyto-{msgid}-{fromid}).
     */
    private function handleReplyToAddress(ParsedEmail $email): ?RoutingResult
    {
        $localPart = explode('@', $email->envelopeTo)[0] ?? '';

        if (! str_starts_with($localPart, 'replyto-')) {
            return null;
        }

        // Parse replyto-{msgid}-{fromid}
        $parts = explode('-', $localPart);
        if (count($parts) < 3) {
            Log::warning('Invalid replyto address format', [
                'envelope_to' => $email->envelopeTo,
            ]);

            return RoutingResult::DROPPED;
        }

        $messageId = (int) $parts[1];

        // Check if message exists and is not expired
        $message = Message::find($messageId);
        if ($message === null) {
            Log::warning('Reply to non-existent message', [
                'message_id' => $messageId,
            ]);

            return RoutingResult::DROPPED;
        }

        // Check if message is expired (>42 days old)
        $arrival = $message->arrival ?? $message->date;
        if ($arrival && $arrival->diffInDays(now()) > self::EXPIRED_MESSAGE_DAYS) {
            Log::info('Dropping reply to expired message', [
                'message_id' => $messageId,
                'age_days' => $arrival->diffInDays(now()),
            ]);

            return RoutingResult::DROPPED;
        }

        // Check if message is on a closed group (legacy: replyToSingleMessage checks group closed setting)
        $messageGroups = DB::table('messages_groups')
            ->where('msgid', $messageId)
            ->pluck('groupid');

        foreach ($messageGroups as $groupId) {
            $group = Group::find($groupId);
            if ($group !== null) {
                $settings = json_decode($group->settings ?? '{}', true) ?: [];
                if (! empty($settings['closed'])) {
                    Log::info('Reply to message on closed group', [
                        'message_id' => $messageId,
                        'group_id' => $groupId,
                    ]);

                    return RoutingResult::TO_SYSTEM;
                }
            }
        }

        // Find the sender user
        $fromUser = $this->findUserByEmail($email->fromAddress);
        if ($fromUser === null) {
            Log::warning('Reply from unknown user', [
                'from' => $email->fromAddress,
            ]);

            return RoutingResult::DROPPED;
        }

        // Get the message owner
        $messageOwner = $message->fromuser;
        if ($messageOwner === null) {
            Log::warning('Message has no owner', [
                'message_id' => $messageId,
            ]);

            return RoutingResult::DROPPED;
        }

        // Get or create User2User chat between the sender and message owner
        $chat = $this->getOrCreateUserChat($fromUser->id, $messageOwner);
        if ($chat === null) {
            Log::warning('Could not create chat for reply', [
                'from_user' => $fromUser->id,
                'to_user' => $messageOwner,
            ]);

            return RoutingResult::DROPPED;
        }

        // Create the chat message
        $this->createChatMessageFromEmail($chat, $fromUser->id, $email);

        $this->lastRoutingContext = [
            'user_id' => $fromUser->id,
            'to_user_id' => $messageOwner,
            'chat_id' => $chat->id,
            'message_id' => $messageId,
        ];

        Log::info('Created chat message from reply-to email', [
            'message_id' => $messageId,
            'chat_id' => $chat->id,
            'from_user' => $fromUser->id,
        ]);

        return RoutingResult::TO_USER;
    }

    /**
     * Handle chat notification replies (notify-{chatid}-{userid}[-{msgid}]).
     */
    private function handleChatNotificationReply(ParsedEmail $email): RoutingResult
    {
        $chatId = $email->chatId;
        $userId = $email->chatUserId;

        Log::info('Processing chat notification reply', [
            'chat_id' => $chatId,
            'user_id' => $userId,
            'message_id' => $email->chatMessageId,
        ]);

        // Drop misdirected read receipts (legacy: isReceipt check in replyToChatNotification)
        if ($this->isReadReceipt($email)) {
            Log::debug('Dropping misdirected read receipt in chat reply');

            return RoutingResult::DROPPED;
        }

        // Validate chat exists
        $chat = ChatRoom::find($chatId);
        if ($chat === null) {
            Log::warning('Reply to non-existent chat', [
                'chat_id' => $chatId,
            ]);

            return RoutingResult::DROPPED;
        }

        // Check if chat is stale and sender email is unfamiliar
        if ($this->isStaleChatWithUnfamiliarSender($chat, $email)) {
            Log::info('Dropping reply to stale chat from unfamiliar sender', [
                'chat_id' => $chatId,
                'age_days' => $chat->latestmessage?->diffInDays(now()),
            ]);

            return RoutingResult::DROPPED;
        }

        // Validate user is part of chat
        if (! $this->isUserInChat($userId, $chat)) {
            Log::warning('User not part of chat', [
                'chat_id' => $chatId,
                'user_id' => $userId,
            ]);

            return RoutingResult::DROPPED;
        }

        // Create chat message
        $this->createChatMessageFromEmail($chat, $userId, $email);

        $this->lastRoutingContext = [
            'user_id' => $userId,
            'chat_id' => $chatId,
        ];

        return RoutingResult::TO_USER;
    }

    /**
     * Check if chat is stale with unfamiliar sender.
     */
    private function isStaleChatWithUnfamiliarSender(ChatRoom $chat, ParsedEmail $email): bool
    {
        // Check if chat is stale (>84 days old)
        $latestMessage = $chat->latestmessage;
        if ($latestMessage === null || $latestMessage->diffInDays(now()) <= self::STALE_CHAT_DAYS) {
            return false;
        }

        // Check if sender email is known
        $fromAddress = $email->fromAddress;
        if (empty($fromAddress)) {
            return true;
        }

        // Check if email belongs to a user in the chat
        $emailUser = UserEmail::where('email', $fromAddress)->first();
        if ($emailUser === null) {
            return true;
        }

        // Check if email user is part of the chat
        return ! $this->isUserInChat($emailUser->userid, $chat);
    }

    /**
     * Check if user is part of a chat.
     */
    private function isUserInChat(int $userId, ChatRoom $chat): bool
    {
        if ($chat->chattype === 'User2User') {
            return $chat->user1 === $userId || $chat->user2 === $userId;
        }

        // For group chats, check roster
        return DB::table('chat_roster')
            ->where('chatid', $chat->id)
            ->where('userid', $userId)
            ->exists();
    }

    /**
     * Create a chat message from an incoming email.
     *
     * Matches legacy MailRouter behaviour:
     * - Runs spam checks; if spam found, sets reviewrequired=1 (not rejected)
     * - Runs checkReview for review-level content checks
     * - After creation, checks image attachments for repeated hash spam
     */
    private function createChatMessageFromEmail(
        ChatRoom $chat,
        int $userId,
        ParsedEmail $email,
        bool $forceReview = false,
        ?string $forceReviewReason = null
    ): void {
        $body = $email->textBody ?? $email->htmlBody ?? '';

        // Strip quoted reply text and signatures before storing.
        $body = $this->stripQuoted->strip($body);

        // Determine if this chat message needs review (matching legacy flow).
        // In the legacy code, MailRouter::checkSpam() is called first. If spam is
        // found and the email is destined for chat (@users domain), the message is
        // NOT rejected - instead $spamfound is passed to ChatMessage::create() as
        // $forcereview, setting reviewrequired=1.
        $reviewRequired = $forceReview;
        $reportReason = $forceReviewReason;

        // Check for spam-level issues (unless already flagged by caller)
        if (! $reviewRequired) {
            $spamResult = $this->spamCheck->checkMessage($email);
            if ($spamResult !== null) {
                [, $reason, $detail] = $spamResult;
                $reviewRequired = true;
                $reportReason = $reason;
                Log::info('Chat message flagged for review (spam detected)', [
                    'reason' => $reason,
                    'detail' => $detail,
                ]);
            }
        }

        // Check for review-level issues (scripts, money, links, language, etc.)
        // Matches legacy ChatMessage::process() calling Spam::checkReview()
        if (! $reviewRequired && strlen($body) > 0) {
            $reviewReason = $this->spamCheck->checkReview($body, true);
            if ($reviewReason !== null) {
                $reviewRequired = true;
                $reportReason = $reviewReason;
                Log::info('Chat message flagged for review', [
                    'reason' => $reviewReason,
                ]);
            }
        }

        // Map reportreason to valid enum values for the chat_messages table.
        // The DB column is enum('Spam','Other','Last','Force','Fully','TooMany','User','UnknownMessage','SameImage','DodgyImage').
        // Our spam check reasons are more detailed, so map them to the enum.
        $dbReportReason = $this->mapReportReason($reportReason);

        $chatMsg = ChatMessage::create([
            'chatid' => $chat->id,
            'userid' => $userId,
            'message' => $body,
            'type' => ChatMessage::TYPE_DEFAULT,
            'date' => now(),
            'platform' => 0, // Email source
            'reviewrequired' => $reviewRequired ? 1 : 0,
            'reportreason' => $dbReportReason,
        ]);

        Log::info('Created chat message from email', [
            'chat_id' => $chat->id,
            'chat_message_id' => $chatMsg->id,
            'user_id' => $userId,
            'review_required' => $reviewRequired,
        ]);

        // Check image attachments for repeated hash spam (matching legacy addPhotosToChat).
        // If the same image hash has been used too many times recently, flag for review.
        $this->checkChatImageSpam($chatMsg);
    }

    /**
     * Check chat message image attachments for repeated hash spam.
     *
     * Matches legacy MailRouter::addPhotosToChat() which checks each image
     * hash against recent usage and flags the message for review if the
     * same image has been used more than IMAGE_THRESHOLD times in 24 hours.
     */
    private function checkChatImageSpam(ChatMessage $chatMsg): void
    {
        if ($chatMsg->imageid === null) {
            return;
        }

        $image = DB::table('chat_images')->where('id', $chatMsg->imageid)->first();
        if ($image === null || empty($image->hash)) {
            return;
        }

        if ($this->spamCheck->checkImageSpam($image->hash)) {
            DB::table('chat_messages')
                ->where('id', $chatMsg->id)
                ->update([
                    'reviewrequired' => 1,
                    'reportreason' => SpamCheckService::REASON_IMAGE_SENT_MANY_TIMES,
                ]);

            Log::info('Chat image flagged for review (repeated hash)', [
                'chat_message_id' => $chatMsg->id,
                'hash' => $image->hash,
            ]);
        }
    }

    /**
     * Map a spam check reason to a valid chat_messages.reportreason enum value.
     *
     * The DB column is enum('Spam','Other','Last','Force','Fully','TooMany','User','UnknownMessage','SameImage','DodgyImage').
     * SpamCheckService returns detailed reason strings. Map them to the enum.
     */
    private function mapReportReason(?string $reason): ?string
    {
        if ($reason === null) {
            return null;
        }

        // These are already valid enum values
        $validEnumValues = ['Spam', 'Other', 'Last', 'Force', 'Fully', 'TooMany', 'User', 'UnknownMessage', 'SameImage', 'DodgyImage'];
        if (in_array($reason, $validEnumValues, true)) {
            return $reason;
        }

        // Map detailed reasons to generic 'Spam' enum value
        return 'Spam';
    }

    /**
     * Handle messages to group volunteers.
     *
     * Matches legacy toVolunteers() flow:
     * 1. Run SpamAssassin check first
     * 2. Run checkMessage() for our own spam checks
     * 3. Check if sender is a known spammer
     * 4. Filter auto-replies (for -auto@ addresses, not -volunteers@)
     * 5. Create User2Mod chat message
     */
    private function handleVolunteersMessage(ParsedEmail $email): RoutingResult
    {
        Log::info('Processing volunteers message', [
            'group' => $email->targetGroupName,
            'is_volunteers' => $email->isToVolunteers,
            'is_auto' => $email->isToAuto,
        ]);

        // Find the group
        $group = $this->findGroup($email->targetGroupName);
        if ($group === null) {
            Log::warning('Volunteers message to unknown group', [
                'group' => $email->targetGroupName,
            ]);

            return RoutingResult::DROPPED;
        }

        // Find sender user
        $user = $this->findUserByEmail($email->fromAddress);
        if ($user === null) {
            Log::warning('Volunteers message from unknown user', [
                'from' => $email->fromAddress,
            ]);

            return RoutingResult::DROPPED;
        }

        // Filter auto-replies for -auto@ addresses (legacy: !isAutoreply() check for non-volunteers)
        if (! $email->isToVolunteers && $email->isAutoReply()) {
            Log::debug('Dropping auto-reply to auto address');

            return RoutingResult::DROPPED;
        }

        // Spam checks for volunteers messages: flag for review, never reject.
        // Users may be reporting spam to volunteers, so we don't want to block
        // the message. Instead, flag it so volunteers can see it was detected.
        $spamDetected = false;
        $spamReason = null;

        // SpamAssassin check
        [$spamScore, $isSpamAssassin] = $this->spamCheck->checkSpamAssassin(
            $email->rawMessage,
            $email->subject ?? ''
        );

        if ($isSpamAssassin) {
            $spamDetected = true;
            $spamReason = 'SpamAssassin score '.$spamScore;
            Log::info('Volunteers message flagged by SpamAssassin (for review)', [
                'score' => $spamScore,
            ]);
        }

        // Our own spam checks
        if (! $spamDetected) {
            $spamResult = $this->spamCheck->checkMessage($email);
            if ($spamResult !== null) {
                [, $reason, $detail] = $spamResult;
                $spamDetected = true;
                $spamReason = $reason;
                Log::info('Volunteers message flagged as spam (for review)', [
                    'reason' => $reason,
                    'detail' => $detail,
                ]);
            }
        }

        // Known spammer check
        if (! $spamDetected) {
            $isSpammer = DB::table('spam_users')
                ->where('userid', $user->id)
                ->where('collection', 'Spammer')
                ->exists();

            if ($isSpammer) {
                $spamDetected = true;
                $spamReason = 'Known spammer';
                Log::info('Volunteers message from known spammer (for review)', [
                    'user_id' => $user->id,
                ]);
            }
        }

        // Get or create User2Mod chat between user and group moderators
        $chat = $this->getOrCreateUser2ModChat($user->id, $group->id);
        if ($chat === null) {
            Log::warning('Could not create User2Mod chat', [
                'user_id' => $user->id,
                'group_id' => $group->id,
            ]);

            return RoutingResult::DROPPED;
        }

        // Create the chat message, flagging for review if spam was detected
        $this->createChatMessageFromEmail($chat, $user->id, $email, $spamDetected, $spamReason);

        $this->lastRoutingContext = [
            'group_id' => $group->id,
            'group_name' => $group->nameshort ?? $group->namefull ?? '',
            'user_id' => $user->id,
            'chat_id' => $chat->id,
        ];

        Log::info('Created volunteers message', [
            'chat_id' => $chat->id,
            'user_id' => $user->id,
            'group_id' => $group->id,
            'spam_flagged' => $spamDetected,
        ]);

        return RoutingResult::TO_VOLUNTEERS;
    }

    /**
     * Handle group posts.
     */
    private function handleGroupPost(ParsedEmail $email): RoutingResult
    {
        Log::info('Processing group post', [
            'group' => $email->targetGroupName,
            'subject' => $email->subject,
        ]);

        // Find the group
        $group = $this->findGroup($email->targetGroupName);
        if ($group === null) {
            Log::warning('Post to unknown group', [
                'group' => $email->targetGroupName,
            ]);

            return RoutingResult::DROPPED;
        }

        // Find sender user
        $user = $this->findUserByEmail($email->fromAddress);
        if ($user === null) {
            Log::info('Post from unknown user - dropping', [
                'from' => $email->fromAddress,
            ]);

            return RoutingResult::DROPPED;
        }

        // Check membership
        $membership = Membership::where('userid', $user->id)
            ->where('groupid', $group->id)
            ->where('collection', 'Approved')
            ->first();

        if ($membership === null) {
            Log::info('Post from non-member - dropping', [
                'user_id' => $user->id,
                'group_id' => $group->id,
            ]);

            return RoutingResult::DROPPED;
        }

        // Check for TAKEN/RECEIVED subjects - swallow silently (legacy toGroup: paired TAKEN/RECEIVED → TO_SYSTEM)
        if ($this->isTakenOrReceivedSubject($email->subject)) {
            Log::info('TAKEN/RECEIVED post swallowed', [
                'subject' => $email->subject,
            ]);

            return RoutingResult::TO_SYSTEM;
        }

        // Check if Trash Nothing post with valid secret (skip spam check)
        $skipSpamCheck = $this->shouldSkipSpamCheck($email);

        // Set context early - we know the group and user at this point
        $this->lastRoutingContext = [
            'group_id' => $group->id,
            'group_name' => $group->nameshort ?? $group->namefull ?? '',
            'user_id' => $user->id,
        ];

        // Check for spam if not TN
        if (! $skipSpamCheck && $this->isSpam($email)) {
            return RoutingResult::INCOMING_SPAM;
        }

        // Check posting status (column is camelCase: ourPostingStatus)
        // Legacy defaults NULL to MODERATED (→ PENDING). Only explicit 'DEFAULT' or
        // 'UNMODERATED' posting status means approved.
        $postingStatus = $membership->ourPostingStatus;

        // Check if user is unmapped (no location)
        if ($user->lastlocation === null) {
            Log::info('Post from unmapped user - pending', [
                'user_id' => $user->id,
            ]);

            return RoutingResult::PENDING;
        }

        // Check for worry words
        if ($this->containsWorryWords($email)) {
            Log::info('Post contains worry words - pending', [
                'subject' => $email->subject,
            ]);

            return RoutingResult::PENDING;
        }

        // Route based on posting status
        // API rejects PROHIBITED with "Not allowed to post on this group" (message.php:625)
        // so email should match: drop the post.
        return match ($postingStatus) {
            'DEFAULT', 'UNMODERATED' => RoutingResult::APPROVED,
            'PROHIBITED' => RoutingResult::DROPPED,
            default => RoutingResult::PENDING,  // NULL, MODERATED, or any other value
        };
    }

    /**
     * Check if spam check should be skipped (e.g., for TN with valid secret).
     */
    private function shouldSkipSpamCheck(ParsedEmail $email): bool
    {
        if (! $email->isFromTrashNothing()) {
            return false;
        }

        $secret = $email->getTrashNothingSecret();
        if ($secret === null) {
            return false;
        }

        // Validate secret against config
        $configSecret = config('freegle.mail.trashnothing_secret');
        if (empty($configSecret)) {
            // If no secret configured, accept any TN email with a secret header
            return true;
        }

        return $secret === $configSecret;
    }

    /**
     * Check if email is spam using the SpamCheckService.
     *
     * Runs all spam detection checks from legacy Spam::checkMessage() and
     * Spam::checkSpam(): keywords, IP checks, subject reuse, greeting spam,
     * Spamhaus DBL, known spammer references, and more.
     */
    private function isSpam(ParsedEmail $email): bool
    {
        $result = $this->spamCheck->checkMessage($email);

        if ($result !== null) {
            [, $reason, $detail] = $result;
            Log::info('Spam detected', [
                'reason' => $reason,
                'detail' => $detail,
            ]);

            return true;
        }

        // Also run SpamAssassin if available (matches legacy MailRouter::checkSpam)
        // Note: isSpam() is only called when shouldSkipSpamCheck() is false,
        // so SpamAssassin always runs here.
        [$score, $isSpam] = $this->spamCheck->checkSpamAssassin(
            $email->rawMessage,
            $email->subject ?? ''
        );

        if ($isSpam) {
            Log::info('SpamAssassin flagged as spam', [
                'score' => $score,
            ]);

            return true;
        }

        return false;
    }

    /**
     * Check if email contains worry words.
     *
     * Worry words are stored in the 'worrywords' database table with types:
     * - Regulated: UK regulated substances
     * - Reportable: UK reportable substances
     * - Medicine: Medicines/supplements
     * - Review: Just needs looking at
     * - Allowed: Exclusions (removed from text before checking)
     */
    private function containsWorryWords(ParsedEmail $email): bool
    {
        $subject = $email->subject ?? '';
        $body = $email->textBody ?? '';

        // Get worry words from database
        $worryWords = DB::table('worrywords')->get();

        // Check for pound sign (£) as a special case
        if (str_contains($subject, '£') || str_contains($body, '£')) {
            Log::debug('Worry word found: £');

            return true;
        }

        // First, remove any ALLOWED type words from the text
        foreach ($worryWords as $worryWord) {
            if ($worryWord->type === 'Allowed') {
                $pattern = '/\b'.preg_quote($worryWord->keyword, '/').'\b/i';
                $subject = preg_replace($pattern, '', $subject);
                $body = preg_replace($pattern, '', $body);
            }
        }

        // Check for phrases (words containing spaces) with literal matching
        foreach ($worryWords as $worryWord) {
            if ($worryWord->type !== 'Allowed' && str_contains($worryWord->keyword, ' ')) {
                if (stripos($subject, $worryWord->keyword) !== false ||
                    stripos($body, $worryWord->keyword) !== false) {
                    Log::debug('Worry word phrase found', [
                        'keyword' => $worryWord->keyword,
                        'type' => $worryWord->type,
                    ]);

                    return true;
                }
            }
        }

        // Check individual words with exact matching (threshold = 1, so exact match only)
        $subjectWords = preg_split('/\b/', $subject);
        $bodyWords = preg_split('/\b/', $body);
        $allWords = array_merge($subjectWords, $bodyWords);

        foreach ($allWords as $word) {
            $word = trim($word);
            if (empty($word)) {
                continue;
            }

            foreach ($worryWords as $worryWord) {
                if ($worryWord->type !== 'Allowed' && ! empty($worryWord->keyword)) {
                    // Check length ratio (0.75 to 1.25)
                    $ratio = strlen($word) / strlen($worryWord->keyword);
                    if ($ratio >= 0.75 && $ratio <= 1.25) {
                        // Exact match only (levenshtein distance < 1)
                        if (levenshtein(strtolower($worryWord->keyword), strtolower($word)) < 1) {
                            Log::debug('Worry word found', [
                                'word' => $word,
                                'keyword' => $worryWord->keyword,
                                'type' => $worryWord->type,
                            ]);

                            return true;
                        }
                    }
                }
            }
        }

        return false;
    }

    /**
     * Handle direct mail to users.
     *
     * Direct mail is sent to {something}@users.ilovefreegle.org where {something}
     * is a user's email address that can be looked up. This handles replies to
     * What's New emails and direct user-to-user communication.
     */
    private function handleDirectMail(ParsedEmail $email): RoutingResult
    {
        Log::info('Processing direct mail', [
            'from' => $email->fromAddress,
            'to' => $email->envelopeTo,
        ]);

        // Find the recipient user by looking up the envelope-to address.
        // Uses findUserByEmail which handles Freegle-formatted addresses
        // (*-UID@users.ilovefreegle.org) and canonical email matching.
        $recipientUser = $this->findUserByEmail($email->envelopeTo);

        if ($recipientUser === null) {
            Log::info('Direct mail to unknown user address', [
                'to' => $email->envelopeTo,
            ]);

            return RoutingResult::DROPPED;
        }

        // Find the sender user
        $senderUser = $this->findUserByEmail($email->fromAddress);
        if ($senderUser === null) {
            Log::info('Direct mail from unknown user', [
                'from' => $email->fromAddress,
            ]);

            return RoutingResult::DROPPED;
        }

        // Don't create a chat between the same user
        if ($senderUser->id === $recipientUser->id) {
            Log::info('Direct mail to self - dropping', [
                'user_id' => $senderUser->id,
            ]);

            return RoutingResult::DROPPED;
        }

        // Get or create chat between sender and recipient
        $chat = $this->getOrCreateUserChat($senderUser->id, $recipientUser->id);
        if ($chat === null) {
            Log::warning('Could not create chat for direct mail', [
                'from_user' => $senderUser->id,
                'to_user' => $recipientUser->id,
            ]);

            return RoutingResult::DROPPED;
        }

        // Create the chat message
        $this->createChatMessageFromEmail($chat, $senderUser->id, $email);

        $this->lastRoutingContext = [
            'user_id' => $senderUser->id,
            'to_user_id' => $recipientUser->id,
            'chat_id' => $chat->id,
        ];

        Log::info('Created chat message from direct mail', [
            'chat_id' => $chat->id,
            'from_user' => $senderUser->id,
            'to_user' => $recipientUser->id,
        ]);

        return RoutingResult::TO_USER;
    }

    /**
     * Find a group by name.
     */
    private function findGroup(?string $name): ?Group
    {
        if (empty($name)) {
            return null;
        }

        return Group::where('nameshort', $name)->first();
    }

    /**
     * Find a user by email address.
     *
     * Handles Freegle-formatted addresses (*-UID@users.ilovefreegle.org) by
     * extracting the UID directly. Also uses canonical email matching for
     * TN variant addresses (matching legacy User::findByEmail behaviour).
     */
    private function findUserByEmail(?string $email): ?User
    {
        if (empty($email)) {
            return null;
        }

        // Check for Freegle-formatted address with embedded UID
        $userDomain = config('freegle.mail.user_domain', 'users.ilovefreegle.org');
        if (preg_match('/.*\-(\d+)@'.preg_quote($userDomain, '/').'$/', $email, $matches)) {
            return User::find((int) $matches[1]);
        }

        // Try direct email lookup first
        $userEmail = UserEmail::where('email', $email)->first();
        if ($userEmail !== null) {
            return User::find($userEmail->userid);
        }

        // Try canonical email lookup (handles TN variants, gmail dots, etc.)
        $canon = $this->canonicalizeEmail($email);
        if ($canon !== $email) {
            $userEmail = UserEmail::where('canon', $canon)->first();
            if ($userEmail !== null) {
                return User::find($userEmail->userid);
            }
        }

        return null;
    }

    /**
     * Canonicalize an email address to match legacy User::canonMail().
     *
     * Handles: TN group suffixes, googlemail→gmail, plus addressing, gmail dots.
     */
    private function canonicalizeEmail(string $email): string
    {
        // Googlemail → Gmail
        $email = str_replace('@googlemail.', '@gmail.', $email);
        $email = str_replace('@googlemail.co.uk', '@gmail.co.uk', $email);

        // Strip TN group suffix: user-gNNNN@user.trashnothing.com → user@user.trashnothing.com
        if (preg_match('/(.*)\-(.*)(@user\.trashnothing\.com)/', $email, $matches)) {
            $email = $matches[1].$matches[3];
        }

        // Remove plus addressing (except Facebook proxy and leading +)
        if (
            str_starts_with($email, '+') === false &&
            preg_match('/(.*)\+(.*)(@.*)/', $email, $matches) &&
            strpos($email, '@proxymail.facebook.com') === false
        ) {
            $email = $matches[1].$matches[3];
        }

        // Remove dots in Gmail LHS
        $p = strpos($email, '@');
        if ($p !== false) {
            $lhs = substr($email, 0, $p);
            $rhs = substr($email, $p);

            if (stripos($rhs, '@gmail') !== false || stripos($rhs, '@googlemail') !== false) {
                $lhs = str_replace('.', '', $lhs);
            }

            // Remove dots from RHS (matches legacy behaviour)
            $email = $lhs.str_replace('.', '', $rhs);
        }

        return $email;
    }

    /**
     * Get or create a User2User chat between two users.
     */
    private function getOrCreateUserChat(int $userId1, int $userId2): ?ChatRoom
    {
        // Normalize order (smaller ID first)
        if ($userId1 > $userId2) {
            [$userId1, $userId2] = [$userId2, $userId1];
        }

        // Check if chat already exists
        $chat = ChatRoom::where('chattype', 'User2User')
            ->where('user1', $userId1)
            ->where('user2', $userId2)
            ->first();

        if ($chat !== null) {
            return $chat;
        }

        // Create new chat
        $chat = ChatRoom::create([
            'chattype' => 'User2User',
            'user1' => $userId1,
            'user2' => $userId2,
        ]);

        Log::info('Created new User2User chat', [
            'chat_id' => $chat->id,
            'user1' => $userId1,
            'user2' => $userId2,
            'created_new' => true,
        ]);

        return $chat;
    }

    /**
     * Check if subject indicates a TAKEN or RECEIVED post.
     *
     * These are completion markers in the legacy format: "TAKEN: item (location)" or "RECEIVED: item (location)".
     * Legacy toGroup() swallows these silently as TO_SYSTEM since mods don't need to review them.
     */
    private function isTakenOrReceivedSubject(?string $subject): bool
    {
        if ($subject === null) {
            return false;
        }

        return (bool) preg_match('/^\s*(TAKEN|RECEIVED)\s*:/i', $subject);
    }

    /**
     * Check if email is a read receipt (MDN).
     *
     * Read receipts sent to chat notification addresses are misdirected and should be dropped.
     * Legacy: MailRouter::replyToChatNotification checks $this->msg->isReceipt().
     */
    private function isReadReceipt(ParsedEmail $email): bool
    {
        // Check Content-Type for disposition-notification
        $contentType = $email->getHeader('content-type') ?? '';
        if (str_contains(strtolower($contentType), 'disposition-notification')) {
            return true;
        }

        // Check for MDN-specific content disposition header
        $contentDisposition = $email->getHeader('content-disposition') ?? '';
        if (str_contains(strtolower($contentDisposition), 'notification')) {
            return true;
        }

        return false;
    }

    /**
     * Get or create a User2Mod chat for a user with a group's moderators.
     */
    private function getOrCreateUser2ModChat(int $userId, int $groupId): ?ChatRoom
    {
        // Check if chat already exists
        $chat = ChatRoom::where('chattype', 'User2Mod')
            ->where('user1', $userId)
            ->where('groupid', $groupId)
            ->first();

        if ($chat !== null) {
            return $chat;
        }

        // Create new chat
        $chat = ChatRoom::create([
            'chattype' => 'User2Mod',
            'user1' => $userId,
            'groupid' => $groupId,
        ]);

        Log::info('Created new User2Mod chat', [
            'chat_id' => $chat->id,
            'user_id' => $userId,
            'group_id' => $groupId,
            'created_new' => true,
        ]);

        return $chat;
    }
}
