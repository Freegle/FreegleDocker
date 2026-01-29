<?php

namespace App\Services\Mail\Incoming;

use App\Models\ChatMessage;
use App\Models\ChatRoom;
use App\Models\Group;
use App\Models\Membership;
use App\Models\Message;
use App\Models\User;
use App\Models\UserEmail;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

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
    // Maximum age for chat replies (90 days to match legacy User::OPEN_AGE)
    private const STALE_CHAT_DAYS = 90;

    // Maximum age for message replies (42 days = 6 weeks)
    private const EXPIRED_MESSAGE_DAYS = 42;

    // Routing context - populated during routing for dry-run comparison
    private ?int $routingUserId = null;

    private ?int $routingGroupId = null;

    private ?int $routingChatId = null;

    private ?string $routingSpamReason = null;

    /**
     * Route a parsed email in dry-run mode (no database changes).
     *
     * This method wraps the normal routing logic in a transaction that
     * always rolls back. All routing decisions are made (including DB reads)
     * but no changes persist. Used for shadow testing the new code against
     * legacy archives.
     *
     * @param  ParsedEmail  $email  The parsed email to route
     * @return RoutingOutcome The routing outcome with context (what WOULD have happened)
     */
    public function routeDryRun(ParsedEmail $email): RoutingOutcome
    {
        $result = null;

        // Reset routing context
        $this->routingUserId = null;
        $this->routingGroupId = null;
        $this->routingChatId = null;
        $this->routingSpamReason = null;

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

        return new RoutingOutcome(
            result: $result,
            userId: $this->routingUserId,
            groupId: $this->routingGroupId,
            chatId: $this->routingChatId,
            spamReason: $this->routingSpamReason,
        );
    }

    /**
     * Route a parsed email to the appropriate handler.
     *
     * @param  ParsedEmail  $email  The parsed email to route
     * @return RoutingResult The routing outcome
     */
    public function route(ParsedEmail $email): RoutingResult
    {
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

        // Phase 2: Check for bounces (BEFORE auto-reply check)
        // Bounces have Auto-Submitted header but should be processed, not dropped
        if ($email->isBounce()) {
            return $this->handleBounce($email);
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

        // Check if sender is a known spammer
        if ($this->isKnownSpammer($email)) {
            Log::debug('Dropping message from known spammer');

            return RoutingResult::DROPPED;
        }

        // Phase 3: Check for chat/message replies
        if ($email->isChatNotificationReply()) {
            return $this->handleChatNotificationReply($email);
        }

        // Check for replyto- addresses
        $replyToResult = $this->handleReplyToAddress($email);
        if ($replyToResult !== null) {
            return $replyToResult;
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
                    // Turn off email digests for all their memberships
                    DB::table('memberships')
                        ->where('userid', $user->id)
                        ->update(['emailfrequency' => 0]);

                    Log::info('Turned off emails due to FBL', [
                        'user_id' => $user->id,
                        'email' => $recipientEmail,
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
            DB::table('trysts')
                ->where('id', $trystId)
                ->update([$updateColumn => $response]);
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
        $membership->emailfrequency = 0;
        $membership->save();

        Log::info('Turned off digest for user', [
            'user_id' => $userId,
            'group_id' => $groupId,
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
        $membership->eventsallowed = 0;
        $membership->save();

        Log::info('Turned off events for user', [
            'user_id' => $userId,
            'group_id' => $groupId,
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
        $user->newslettersallowed = 0;
        $user->save();

        Log::info('Turned off newsletters for user', [
            'user_id' => $userId,
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
        $user->relevantallowed = 0;
        $user->save();

        Log::info('Turned off relevant for user', [
            'user_id' => $userId,
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
        $membership->volunteeringallowed = 0;
        $membership->save();

        Log::info('Turned off volunteering for user', [
            'user_id' => $userId,
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
        $settings = json_decode($user->settings ?? '{}', TRUE) ?: [];

        // Only update if not already off
        if (($settings['notificationmails'] ?? TRUE) === TRUE) {
            $settings['notificationmails'] = FALSE;
            $user->settings = json_encode($settings);
            $user->save();

            Log::info('Turned off notification mails for user', [
                'user_id' => $userId,
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

        // Put user into limbo (soft delete)
        DB::table('users')
            ->where('id', $userId)
            ->update(['deleted' => now()]);

        Log::info('Put user into limbo via one-click unsubscribe', [
            'user_id' => $userId,
            'type' => $type,
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
        Membership::create([
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

        // Extract user ID from bounce address if present
        $localPart = explode('@', $email->envelopeTo)[0] ?? '';
        if (preg_match('/^bounce-(\d+)-/', $localPart, $matches)) {
            $userId = (int) $matches[1];

            if ($email->isPermanentBounce() && $email->bounceRecipient) {
                // Record permanent bounce against user's email
                $this->recordBounce($userId, $email->bounceRecipient);
            }
        }

        return RoutingResult::DROPPED;
    }

    /**
     * Record a bounce against a user's email.
     *
     * The bounced column stores a timestamp (when the bounce was recorded),
     * not a count. Setting it marks the email as having bounced.
     */
    private function recordBounce(int $userId, string $emailAddress): void
    {
        DB::table('users_emails')
            ->where('userid', $userId)
            ->where('email', $emailAddress)
            ->update([
                'bounced' => now(),
            ]);

        Log::info('Recorded bounce for user', [
            'user_id' => $userId,
            'email' => $emailAddress,
        ]);
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

        // Track routing context
        $this->routingUserId = $userId;
        $this->routingChatId = $chatId;

        return RoutingResult::TO_USER;
    }

    /**
     * Check if chat is stale with unfamiliar sender.
     */
    private function isStaleChatWithUnfamiliarSender(ChatRoom $chat, ParsedEmail $email): bool
    {
        // Check if chat is stale (>90 days old, per User::OPEN_AGE)
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
     */
    private function createChatMessageFromEmail(ChatRoom $chat, int $userId, ParsedEmail $email): void
    {
        $body = $email->textBody ?? $email->htmlBody ?? '';

        ChatMessage::create([
            'chatid' => $chat->id,
            'userid' => $userId,
            'message' => $body,
            'type' => ChatMessage::TYPE_DEFAULT,
            'date' => now(),
            'platform' => 0, // Email source
        ]);

        Log::info('Created chat message from email', [
            'chat_id' => $chat->id,
            'user_id' => $userId,
        ]);
    }

    /**
     * Handle messages to group volunteers.
     */
    private function handleVolunteersMessage(ParsedEmail $email): RoutingResult
    {
        Log::info('Processing volunteers message', [
            'group' => $email->targetGroupName,
            'is_volunteers' => $email->isToVolunteers,
            'is_auto' => $email->isToAuto,
        ]);

        // Check for spam keywords before routing to volunteers (matches legacy toVolunteers behavior).
        // Legacy runs Spam::checkMessage() which includes the spam_keywords DB check.
        // Only skip if TN secret is valid.
        $skipSpamCheck = $this->shouldSkipSpamCheck($email);

        if (! $skipSpamCheck && $this->isSpam($email)) {
            return RoutingResult::INCOMING_SPAM;
        }

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

        // Track routing context early
        $this->routingUserId = $user->id;
        $this->routingGroupId = $group->id;

        // Check if sender is a known spammer (matches legacy toVolunteers behavior)
        $isSpammer = DB::table('spam_users')
            ->where('userid', $user->id)
            ->where('collection', 'Spammer')
            ->exists();

        if ($isSpammer) {
            Log::info('Volunteers message from known spammer', [
                'user_id' => $user->id,
            ]);

            return RoutingResult::INCOMING_SPAM;
        }

        // Drop autoreplies to volunteer addresses (matches legacy behavior)
        if (! $skipSpamCheck && $email->isAutoReply()) {
            Log::info('Dropping autoreply to volunteers', [
                'from' => $email->fromAddress,
            ]);

            return RoutingResult::DROPPED;
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

        // Create the chat message
        $this->createChatMessageFromEmail($chat, $user->id, $email);

        // Track routing context
        $this->routingUserId = $user->id;
        $this->routingGroupId = $group->id;
        $this->routingChatId = $chat->id;

        Log::info('Created volunteers message', [
            'chat_id' => $chat->id,
            'user_id' => $user->id,
            'group_id' => $group->id,
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

        // Track routing context
        $this->routingUserId = $user->id;
        $this->routingGroupId = $group->id;

        // Check if Trash Nothing post with valid secret (skip spam check)
        $skipSpamCheck = $this->shouldSkipSpamCheck($email);

        // Check for spam if not TN
        if (! $skipSpamCheck && $this->isSpam($email)) {
            return RoutingResult::INCOMING_SPAM;
        }

        // Check posting status (column is camelCase: ourPostingStatus)
        // Keep null to match legacy behavior where null defaults to MODERATED (Pending)
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

        // Check if PROHIBITED - drop regardless of other settings
        if ($postingStatus === 'PROHIBITED') {
            Log::info('User has PROHIBITED posting status - dropping', [
                'user_id' => $user->id,
                'group_id' => $group->id,
            ]);

            return RoutingResult::DROPPED;
        }

        // Check if "Big Switch" is enabled (group overrides all moderation)
        if ($group->overridemoderation === 'ModerateAll') {
            Log::info('Group has ModerateAll override - pending', [
                'group_id' => $group->id,
            ]);

            return RoutingResult::PENDING;
        }

        // Check if user is a moderator - mods always go to pending for email posts
        // This is requested by volunteers to avoid accidents.
        if ($user->isModeratorOf($group->id)) {
            Log::info('Post from moderator - pending', [
                'user_id' => $user->id,
                'group_id' => $group->id,
            ]);

            return RoutingResult::PENDING;
        }

        // Check if group has moderation enabled
        $groupModerated = $group->getSetting('moderated', 0);
        if ($groupModerated) {
            Log::info('Group has moderation enabled - pending', [
                'group_id' => $group->id,
            ]);

            return RoutingResult::PENDING;
        }

        // Route based on posting status
        // Legacy behavior: null posting status defaults to MODERATED (Pending)
        // Only 'DEFAULT' or 'UNMODERATED' explicit status goes to Approved
        return match ($postingStatus) {
            'DEFAULT', 'UNMODERATED' => RoutingResult::APPROVED,
            default => RoutingResult::PENDING,  // null, MODERATED, etc.
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
     * Check if email is spam using the spam_keywords database table.
     *
     * Matches legacy Spam::checkSpam() behavior: checks message body and subject
     * against keywords with action 'Spam' or 'Review', using word boundary regex
     * matching, with support for exclude patterns.
     */
    private function isSpam(ParsedEmail $email): bool
    {
        $subject = $email->subject ?? '';
        $body = $email->textBody ?? '';

        // Strip job text URLs before checking (matches legacy behavior)
        $body = preg_replace('/\<https\:\/\/www\.ilovefreegle\.org\/jobs\/.*\>.*$/im', '', $body);

        // Decode HTML entities used by spammers to disguise words (matches legacy)
        $body = str_replace('&#616;', 'i', $body);
        $body = str_replace('&#537;', 's', $body);
        $body = str_replace('&#206;', 'I', $body);
        $body = str_replace('=C2', '£', $body);

        // Check keywords from database (legacy checks both Review and Spam actions)
        $keywords = DB::table('spam_keywords')
            ->whereIn('action', ['Spam', 'Review'])
            ->get();

        foreach ($keywords as $keyword) {
            $word = trim($keyword->word);
            if (strlen($word) === 0) {
                continue;
            }

            $pattern = '/\b' . preg_quote($word, '/') . '\b/i';

            // Check if keyword matches in body or subject
            $matchesBody = preg_match($pattern, $body);
            $matchesSubject = preg_match($pattern, $subject);

            if ($matchesBody || $matchesSubject) {
                // Check exclude pattern - if message matches exclude, it's not spam
                if (! empty($keyword->exclude)) {
                    $excludePattern = '/' . $keyword->exclude . '/i';
                    $messageText = $body . ' ' . $subject;

                    if (@preg_match($excludePattern, $messageText)) {
                        continue;
                    }
                }

                Log::info('Spam keyword detected from database', [
                    'keyword' => $word,
                    'action' => $keyword->action,
                ]);

                return TRUE;
            }
        }

        return FALSE;
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

            return TRUE;
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
                if (stripos($subject, $worryWord->keyword) !== FALSE ||
                    stripos($body, $worryWord->keyword) !== FALSE) {
                    Log::debug('Worry word phrase found', [
                        'keyword' => $worryWord->keyword,
                        'type' => $worryWord->type,
                    ]);

                    return TRUE;
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

                            return TRUE;
                        }
                    }
                }
            }
        }

        return FALSE;
    }

    /**
     * Handle direct mail to users.
     *
     * Direct mail is sent to {something}@users.ilovefreegle.org where {something}
     * contains a user ID that can be extracted. This handles replies to
     * What's New emails and direct user-to-user communication.
     *
     * Both sender and recipient must be identifiable Freegle users. If either
     * cannot be found, the message is dropped.
     */
    private function handleDirectMail(ParsedEmail $email): RoutingResult
    {
        Log::info('Processing direct mail', [
            'from' => $email->fromAddress,
            'to' => $email->envelopeTo,
        ]);

        // Find the recipient user by looking up the envelope-to address.
        // This handles both Freegle-formatted addresses like *-{uid}@users.ilovefreegle.org
        // and regular email addresses in the users_emails table.
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
     */
    private function findUserByEmail(?string $email): ?User
    {
        if (empty($email)) {
            return null;
        }

        // First check for Freegle-formatted addresses like *-{uid}@users.ilovefreegle.org
        // These have the user ID embedded in the local part after the last hyphen.
        // This matches the legacy User::findByEmail() behavior.
        $userDomain = config('freegle.mail.user_domain');
        if (preg_match('/.*-(\d+)@' . preg_quote($userDomain, '/') . '$/i', $email, $matches)) {
            $userId = (int) $matches[1];
            $user = User::find($userId);
            if ($user !== null) {
                return $user;
            }
        }

        // Canonicalize the email for lookup (matches legacy User::canonMail())
        $canonEmail = $this->canonicalizeEmail($email);

        // Look up by email or canonicalized email
        $userEmail = UserEmail::where('email', $email)
            ->orWhere('canon', $canonEmail)
            ->first();

        if ($userEmail === null) {
            return null;
        }

        return User::find($userEmail->userid);
    }

    /**
     * Canonicalize an email address (matches legacy User::canonMail()).
     *
     * This handles:
     * - TN addresses: rkrochelle-g8860@user.trashnothing.com → rkrochelle@user.trashnothing.com
     * - Googlemail → Gmail
     * - Plus addressing removal
     * - Dot removal for Gmail LHS
     * - Dot removal for ALL domain RHS (legacy behavior for space saving)
     */
    private function canonicalizeEmail(string $email): string
    {
        // Googlemail is Gmail really in US and UK
        $email = str_replace('@googlemail.', '@gmail.', $email);
        $email = str_replace('@googlemail.co.uk', '@gmail.co.uk', $email);

        // Canonicalize TN addresses - strip everything after hyphen before @user.trashnothing.com
        // e.g., rkrochelle-g8860@user.trashnothing.com → rkrochelle@user.trashnothing.com
        // Note: Legacy uses (.*)\-(.*) which matches any hyphen suffix, not just -gNNN
        if (preg_match('/(.*)-(.*)(@user\.trashnothing\.com)/i', $email, $matches)) {
            $email = $matches[1] . $matches[3];
        }

        // Remove plus addressing (except Facebook)
        // e.g., john+freegle@gmail.com → john@gmail.com
        if (strpos($email, '@proxymail.facebook.com') === false &&
            substr($email, 0, 1) !== '+' &&
            preg_match('/(.*)\+(.*)(@.*)/', $email, $matches)) {
            $email = $matches[1] . $matches[3];
        }

        // Split into LHS and RHS at @
        $atPos = strpos($email, '@');
        if ($atPos !== false) {
            $lhs = substr($email, 0, $atPos);
            $rhs = substr($email, $atPos);

            // Remove dots in LHS for Gmail (they're ignored)
            if (stripos($rhs, '@gmail') !== false || stripos($rhs, '@googlemail') !== false) {
                $lhs = str_replace('.', '', $lhs);
            }

            // Remove dots from RHS (domain) - legacy behavior for space saving
            // This is the format historically used in the canon column
            $rhs = str_replace('.', '', $rhs);

            $email = $lhs . $rhs;
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
        return ChatRoom::create([
            'chattype' => 'User2User',
            'user1' => $userId1,
            'user2' => $userId2,
        ]);
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
        return ChatRoom::create([
            'chattype' => 'User2Mod',
            'user1' => $userId,
            'groupid' => $groupId,
        ]);
    }
}
