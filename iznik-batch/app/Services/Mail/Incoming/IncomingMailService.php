<?php

namespace App\Services\Mail\Incoming;

use App\Models\ChatMessage;
use App\Models\ChatRoom;
use App\Models\Group;
use App\Models\Membership;
use App\Models\Message;
use App\Models\User;
use App\Models\UserEmail;
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
    // Maximum age for chat replies (84 days = 12 weeks)
    private const STALE_CHAT_DAYS = 84;

    // Maximum age for message replies (42 days = 6 weeks)
    private const EXPIRED_MESSAGE_DAYS = 42;

    public function __construct(
        private readonly MailParserService $parser
    ) {}

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
     * Check if this is a self-sent message.
     */
    private function isSelfSent(ParsedEmail $email): bool
    {
        $fromAddress = strtolower($email->fromAddress ?? '');
        $envelopeTo = strtolower($email->envelopeTo);

        return $fromAddress === $envelopeTo && ! empty($fromAddress);
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
     */
    private function handleFbl(ParsedEmail $email): RoutingResult
    {
        Log::info('Processing FBL report');
        // TODO: Implement FBL processing

        return RoutingResult::TO_SYSTEM;
    }

    /**
     * Handle read receipts.
     */
    private function handleReadReceipt(ParsedEmail $email): RoutingResult
    {
        Log::info('Processing read receipt');
        // TODO: Implement read receipt processing

        return RoutingResult::RECEIPT;
    }

    /**
     * Handle tryst/handover calendar responses.
     */
    private function handleTrystResponse(ParsedEmail $email): RoutingResult
    {
        Log::info('Processing tryst response');
        // TODO: Implement tryst response processing

        return RoutingResult::TRYST;
    }

    /**
     * Handle digest off command.
     */
    private function handleDigestOff(ParsedEmail $email): RoutingResult
    {
        Log::info('Processing digest off command', [
            'user_id' => $email->commandUserId,
            'group_id' => $email->commandGroupId,
        ]);
        // TODO: Implement digest off processing

        return RoutingResult::TO_SYSTEM;
    }

    /**
     * Handle events off command.
     */
    private function handleEventsOff(ParsedEmail $email): RoutingResult
    {
        Log::info('Processing events off command');
        // TODO: Implement events off processing

        return RoutingResult::TO_SYSTEM;
    }

    /**
     * Handle newsletters off command.
     */
    private function handleNewslettersOff(ParsedEmail $email): RoutingResult
    {
        Log::info('Processing newsletters off command');
        // TODO: Implement newsletters off processing

        return RoutingResult::TO_SYSTEM;
    }

    /**
     * Handle relevant off command.
     */
    private function handleRelevantOff(ParsedEmail $email): RoutingResult
    {
        Log::info('Processing relevant off command');
        // TODO: Implement relevant off processing

        return RoutingResult::TO_SYSTEM;
    }

    /**
     * Handle volunteering off command.
     */
    private function handleVolunteeringOff(ParsedEmail $email): RoutingResult
    {
        Log::info('Processing volunteering off command');
        // TODO: Implement volunteering off processing

        return RoutingResult::TO_SYSTEM;
    }

    /**
     * Handle notification mails off command.
     */
    private function handleNotificationMailsOff(ParsedEmail $email): RoutingResult
    {
        Log::info('Processing notification mails off command');
        // TODO: Implement notification mails off processing

        return RoutingResult::TO_SYSTEM;
    }

    /**
     * Handle one-click unsubscribe.
     */
    private function handleOneClickUnsubscribe(ParsedEmail $email): RoutingResult
    {
        Log::info('Processing one-click unsubscribe');
        // TODO: Implement one-click unsubscribe processing

        return RoutingResult::TO_SYSTEM;
    }

    /**
     * Handle subscribe command.
     */
    private function handleSubscribe(ParsedEmail $email): RoutingResult
    {
        Log::info('Processing subscribe command', [
            'group' => $email->targetGroupName,
        ]);
        // TODO: Implement subscribe processing

        return RoutingResult::TO_SYSTEM;
    }

    /**
     * Handle unsubscribe command.
     */
    private function handleUnsubscribe(ParsedEmail $email): RoutingResult
    {
        Log::info('Processing unsubscribe command', [
            'group' => $email->targetGroupName,
        ]);
        // TODO: Implement unsubscribe processing

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

        // TODO: Create/get chat and add message
        Log::info('Processing reply to message', [
            'message_id' => $messageId,
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

        // TODO: Create/get chat between user and moderators
        // TODO: Add message to chat

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

        // Check if Trash Nothing post with valid secret (skip spam check)
        $skipSpamCheck = $this->shouldSkipSpamCheck($email);

        // Check for spam if not TN
        if (! $skipSpamCheck && $this->isSpam($email)) {
            return RoutingResult::INCOMING_SPAM;
        }

        // Check posting status (column is camelCase: ourPostingStatus)
        $postingStatus = $membership->ourPostingStatus ?? 'DEFAULT';

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
        return match ($postingStatus) {
            'MODERATED' => RoutingResult::PENDING,
            'PROHIBITED' => RoutingResult::DROPPED,
            default => RoutingResult::APPROVED,
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

        // TODO: Validate secret against config
        // For now, any TN secret skips spam check
        return true;
    }

    /**
     * Check if email is spam.
     */
    private function isSpam(ParsedEmail $email): bool
    {
        $subject = strtolower($email->subject ?? '');
        $body = strtolower($email->textBody ?? '');

        // Simple spam patterns - TODO: Implement full spam checking
        $spamPatterns = [
            'make money fast',
            'guaranteed returns',
            'western union',
            'send money',
            'lottery winner',
            'nigerian prince',
        ];

        foreach ($spamPatterns as $pattern) {
            if (str_contains($subject, $pattern) || str_contains($body, $pattern)) {
                Log::info('Spam pattern detected', [
                    'pattern' => $pattern,
                ]);

                return true;
            }
        }

        return false;
    }

    /**
     * Check if email contains worry words.
     */
    private function containsWorryWords(ParsedEmail $email): bool
    {
        $subject = strtolower($email->subject ?? '');
        $body = strtolower($email->textBody ?? '');

        // Common worry words - TODO: Load from database/config
        $worryWords = [
            'kitten',
            'puppy',
            'dog',
            'cat',
            'pet',
            'animal',
            'medication',
            'medicine',
            'drugs',
        ];

        foreach ($worryWords as $word) {
            if (str_contains($subject, $word) || str_contains($body, $word)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Handle direct mail to users.
     */
    private function handleDirectMail(ParsedEmail $email): RoutingResult
    {
        Log::info('Processing direct mail', [
            'from' => $email->fromAddress,
            'to' => $email->envelopeTo,
        ]);

        // TODO: Implement direct mail routing

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

        $userEmail = UserEmail::where('email', $email)->first();
        if ($userEmail === null) {
            return null;
        }

        return User::find($userEmail->userid);
    }
}
