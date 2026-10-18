<?php

namespace App\Services;

use App\Models\BackgroundTask;
use App\Models\ChatMessage;
use App\Models\ChatRoom;
use App\Models\ChatRoster;
use App\Services\Judgement\Judge;
use App\Services\Judgement\Subject;
use App\Services\Lockdown\LockdownHoldsService;
use App\Services\Lockdown\LockdownService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Processes chat messages that arrive via incoming email (processingrequired = 1).
 *
 * Mirrors V1 cron/chat_process.php + ChatMessage::process().
 *
 * Key responsibilities:
 * - Spam/ban checks for User2User chats
 * - Setting processingrequired = 0, processingsuccessful = 1 (or 0 on failure)
 * - Updating chat_roster for the sender
 * - Reopening closed chats after new activity
 */
class ChatProcessService
{
    // V1: ChatMessage::REVIEW_SPAM, REVIEW_FORCE, etc.
    private const REVIEW_SPAM = 'Spam';

    private const REVIEW_LAST = 'Last';

    /**
     * Map a ContentCheckService check identifier to the specific chat_messages
     * `reportreason` enum value, so the modtools review UI can tell the moderator
     * WHY a message was held instead of the unhelpful "failed spam checks, but we
     * don't have any more information about why". Every value here is a member of
     * the reportreason enum and is already rendered with friendly text by
     * ModChatReview.vue. Anything unmapped falls back to the generic 'Spam'.
     */
    private const CHECK_TO_REPORTREASON = [
        ContentCheckService::CHECK_MONEY => 'Money',
        ContentCheckService::CHECK_URL => 'Link',
        ContentCheckService::CHECK_MESSAGING_LINK => 'Link',
        ContentCheckService::CHECK_SPAMHAUS_DBL => 'URL on DBL',
        ContentCheckService::CHECK_EMAIL_ADDRESS => 'Email',
        ContentCheckService::CHECK_LANGUAGE => 'Language',
        ContentCheckService::CHECK_KNOWN_SPAMMER => 'Referenced known spammer',
    ];

    /**
     * Resolve the specific reportreason for a checkChatMessage() result.
     * Returns the generic 'Spam' for a null result or an unmapped check.
     */
    private function reportReasonForCheck(?array $result): string
    {
        $check = $result['check'] ?? null;

        return self::CHECK_TO_REPORTREASON[$check] ?? self::REVIEW_SPAM;
    }

    /**
     * Ask the judge about a chat message the deterministic checks passed clean, and map a
     * confident "yes" to its reportreason (ai-judgement.md, config('freegle.judgement.chat_reportreason')).
     * Returns null when the judge found nothing to flag, INCLUDING when it was unavailable —
     * an unavailable judge is "no signal", never a hold, so this can never turn a Fully/Moderated
     * decision that would otherwise pass into one that fails open. Checked in config array order
     * (free, scam, decent), first confident match wins.
     */
    private function reviewReasonFromJudge(string $body): ?string
    {
        if (trim($body) === '') {
            return null;
        }

        $verdict = $this->judge->judge(new Subject(kind: Subject::KIND_CHAT, body: $body));

        if (!$verdict->available) {
            return null;
        }

        $threshold = (float) config('freegle.judgement.threshold', 0.8);

        foreach ((array) config('freegle.judgement.chat_reportreason', []) as $questionId => $reason) {
            if ($verdict->yesAtOrAbove($questionId, $threshold)) {
                return $reason;
            }
        }

        return null;
    }

    /** kind value lockdown_holds uses for chat rows. */
    private const HOLD_KIND = LockdownHoldsService::KIND_CHAT;

    private ContentCheckService $contentCheck;

    private Judge $judge;

    private LockdownService $lockdown;

    public function __construct(
        ?ContentCheckService $contentCheck = null,
        ?LockdownService $lockdown = null,
        ?Judge $judge = null
    ) {
        // Resolve from the container when not injected (keeps `new ChatProcessService()` working).
        $this->contentCheck = $contentCheck ?? app(ContentCheckService::class);
        $this->lockdown = $lockdown ?? app(LockdownService::class);
        $this->judge = $judge ?? app(Judge::class);
    }

    /**
     * Process all pending chat messages (processingrequired = 1), oldest first.
     *
     * While a lockdown holds chat, member-to-member messages wait here unprocessed; chat
     * with the volunteers keeps flowing. Once chat is lifted they are simply pending again,
     * so this processes everything the lockdown held, straight away, through the same
     * checks as any other message: a sender Support has since marked as a spammer is
     * dropped by the spammer check. Work goes in batches only to keep each query small.
     *
     * @return int Number of messages processed.
     */
    public function processIncoming(): int
    {
        // Ticks over even on a quiet run with no messages, so the presser sees this loop
        // still running (section 11.6 point 3).
        $this->lockdown->ack('chat-process');

        $count = 0;
        $lastId = 0;

        while (true) {
            $chatHeld = $this->lockdown->held('chat');
            $messages = DB::table('chat_messages')
                ->join('chat_rooms', 'chat_messages.chatid', '=', 'chat_rooms.id')
                ->where('chat_messages.processingrequired', 1)
                ->where('chat_messages.id', '>', $lastId)
                ->when($chatHeld, fn ($query) => $query->where('chat_rooms.chattype', '!=', ChatRoom::TYPE_USER2USER))
                ->orderBy('chat_messages.id', 'asc')
                ->limit(LockdownHoldsService::releaseBatchSize())
                ->select('chat_messages.*', 'chat_rooms.chattype', 'chat_rooms.user1', 'chat_rooms.user2')
                ->get();
            if ($messages->isEmpty()) {
                break;
            }
            $lastId = (int) $messages->last()->id;

            // Which of this batch the lockdown held, so their holds can be closed once they
            // go through. One query per batch; nothing at all if there has never been a lockdown.
            $held = $this->lockdown->incidentId() === null ? [] : DB::table('lockdown_holds')
                ->where('kind', self::HOLD_KIND)
                ->whereNull('outcome')
                ->whereIn('refid', $messages->pluck('id'))
                ->pluck('refid')
                ->flip()
                ->all();

            foreach ($messages as $message) {
                // Re-read per message (section 11.6 point 2): a press landing between two
                // messages of this run stops the next member-to-member one at once. held()
                // is a memory read within the five-second cache, so this costs nothing.
                if ($message->chattype === ChatRoom::TYPE_USER2USER && $this->lockdown->held('chat')) {
                    continue;
                }
                $this->lockdown->ack('chat-process');

                if ($this->processMessage($message)) {
                    $count++;
                    if (array_key_exists($message->id, $held)) {
                        // The ordinary checks may have refused it (a sender marked as a
                        // spammer, a blocked phrase), which leaves it unsuccessful.
                        $delivered = (int) DB::table('chat_messages')->where('id', $message->id)->value('processingsuccessful') === 1;
                        $this->recordHoldOutcome((int) $message->id, $delivered ? 'released' : 'rejected');
                    }
                }
            }
        }

        Log::info("ChatProcess: processed {$count} messages");

        return $count;
    }

    /**
     * Process a single pending message.
     *
     * @return bool True if the message was processed (success or failure), false if skipped.
     */
    private function processMessage(object $message): bool
    {
        $id = $message->id;
        $chatid = $message->chatid;
        $userid = $message->userid;
        $chattype = $message->chattype;
        $platform = (bool) $message->platform;

        // A ban is a single site-wide fact about the sender (users.banned) - there is one
        // national site, so there is no separate scope left to be banned "on" or "in
        // common with" another member. See isBanned() below.

        $review = 0;
        $reviewreason = null;
        $spam = 0;

        // Someone on the spammer list reaches nobody, whatever kind of chat this is.
        // Their mail is already dropped on the way in, volunteers address included
        // (IncomingMailService::isKnownSpammer), but the Contact button on a group page
        // opens a chat with the volunteers, and this check used to sit inside the
        // User2User branch below, so that route stayed open (Discourse 10149).
        //
        // A ban is deliberately NOT treated this way: the site-wide ban check stays
        // below, for member-to-member chats only, so a banned member can still write to
        // the volunteers to appeal. That is Edward's decision on the same thread.
        //
        // Same reasoning for a PendingAdd on the volunteers route: that is a proposal
        // awaiting a second moderator, not the list, and writing to the volunteers is how
        // someone argues they should not be added. Member-to-member keeps both
        // collections, exactly as before.
        $spamCollections = $chattype === ChatRoom::TYPE_USER2USER
            ? ['Spammer', 'PendingAdd']
            : ['Spammer'];

        $isSpammer = DB::table('spam_users')
            ->where('userid', $userid)
            ->whereIn('collection', $spamCollections)
            ->exists();

        if ($isSpammer) {
            $this->processFailed($id, ChatMessage::PROCESSFAIL_SPAMMER);

            return true;
        }

        // --- User2User review and ban checks ---
        if ($chattype === ChatRoom::TYPE_USER2USER) {
            // Check if the sender is banned (site-wide).
            if ($this->isBanned($userid)) {
                $this->processFailed($id, ChatMessage::PROCESSFAIL_BANNED_IN_COMMON);

                return true;
            }

            // Check if sender's messages should be held for review.
            $user = DB::table('users')->where('id', $userid)->first();
            $chatmodstatus = $user?->chatmodstatus ?? 'Moderated';

            if ($chatmodstatus === 'Fully') {
                // Fully moderated: every message goes to review (shadow ban).
                $review = 1;
                $reviewreason = self::REVIEW_SPAM;
            } elseif ($chatmodstatus === 'Moderated' && $this->isContentCheckable($message->type)) {
                // V1 parity: ChatMessage::process() ran Spam::checkReview() on
                // Moderated members' user-text messages and held any that matched
                // a concern keyword / link / phone number etc. That scan was lost
                // when chat_process.php was migrated to this service, so restore it.
                // Map the specific check that fired to its reportreason enum
                // value, so the review UI tells the moderator WHY (e.g. "It looks
                // like it refers to money.") rather than "...no more information
                // about why". Unmapped checks fall back to the generic 'Spam'.
                $checkResult = $this->contentCheck->checkChatMessage((string) ($message->message ?? ''));
                if ($checkResult !== null) {
                    $review = 1;
                    $reviewreason = $this->reportReasonForCheck($checkResult);
                } else {
                    // ai-judgement.md: the deterministic checks above catch keyword-listable
                    // abuse; the judge catches what a keyword list can't (paraphrased scams,
                    // subtler money asks, abuse without a slur). Only asked when the cheap
                    // checks found nothing, and only for Moderated members - Fully moderated
                    // members are held above regardless, Unmoderated members are never
                    // content-checked at all. Judge unavailable is "no signal", never a hold.
                    $reviewreason = $this->reviewReasonFromJudge((string) ($message->message ?? ''));
                    if ($reviewreason !== null) {
                        $review = 1;
                    }
                }
            }

            // If the PREVIOUS message in this chat is held for review, hold this
            // one too. Use id < $id, not id != $id: V1's chat_process.php was a
            // continuous daemon that processed each message as it arrived, so the
            // newest other row WAS the previous one. This service processes in
            // batches (processIncoming orders by id asc), so when a burst of
            // messages is pending, "newest other row" is a LATER, not-yet-processed
            // message (reviewrequired defaults to 0) and the hold chain silently
            // breaks — subsequent messages from a member already under review get
            // delivered (Discourse #9656). Looking strictly backwards at the
            // immediately preceding (already-processed) message restores the chain.
            if (! $review) {
                $lastReview = DB::table('chat_messages')
                    ->where('chatid', $chatid)
                    ->where('id', '<', $id)
                    ->orderByDesc('id')
                    ->value('reviewrequired');

                if ($lastReview) {
                    $review = 1;
                    $reviewreason = self::REVIEW_LAST;
                }
            }
        }

        // Mark the message as processed.
        DB::table('chat_messages')
            ->where('id', $id)
            ->update([
                'reviewrequired' => $review,
                'reportreason' => $reviewreason,
                'reviewrejected' => $spam,
                'processingrequired' => 0,
                'processingsuccessful' => 1,
            ]);

        // Update the sender's roster position.
        $this->updateSenderRoster($id, $chatid, $userid, $platform);

        // Reopen any CLOSED roster entries for this chat (not BLOCKED).
        DB::table('chat_roster')
            ->where('chatid', $chatid)
            ->where('status', ChatRoster::STATUS_CLOSED)
            ->update(['status' => ChatRoster::STATUS_OFFLINE]);

        // V1 parity: ChatMessage::process() called notifyMembers() here when the
        // message wasn't held/banned (the spam/ban paths above early-return).
        // Hand off to a background task so we don't block this cron on FCM round-trips.
        // Under the warn-not-hold experiment a held message is delivered behind a warning,
        // so the recipient is told about it and the room surfaces, exactly as for a clean
        // message. The review flag stays set for moderators.
        $deliverable = \App\Support\ChatWarnNotHold::deliverable((bool) $review, $reviewreason);

        if ($deliverable) {
            BackgroundTask::create([
                'task_type' => BackgroundTask::TASK_PUSH_NOTIFY_CHAT_MESSAGE,
                'data' => ['message_id' => $id],
                'created_at' => now(),
                'attempts' => 0,
            ]);

            // Surface the room in recipients' chat list now the message is deliverable.
            // ListForUser filters chat_rooms.latestmessage >= now - CHAT_ACTIVE_LIMIT (31d),
            // and neither CreateChatMessage nor this service bumped latestmessage - only the
            // hourly chats:update-counts recompute did. So a reply landing in a room dormant
            // for >31 days (Freegle keeps one User2User room per pair, reused across items)
            // stayed out of the recipient's list until that hourly cron ran, even though the
            // notification email fired immediately - up to ~1h of "email arrived but the chat
            // isn't showing". Bump here, gated on the same deliverability the email/in-app
            // fetch use (not held for review, not spam), so list visibility tracks delivery.
            // GREATEST guards against moving latestmessage backwards when a burst is processed
            // out of natural order; the hourly recompute remains as a backfill.
            // keep-raw: GREATEST(COALESCE(latestmessage, ?), ?) - both GREATEST() and
            // COALESCE() are SQL functions composed over the latestmessage column as the
            // UPDATE SET value; the query builder has no method for either, and wrapping
            // them in DB::raw() as the update() value would only relocate the raw SQL
            // into the array, not convert it.
            DB::update(
                'UPDATE chat_rooms SET latestmessage = GREATEST(COALESCE(latestmessage, ?), ?) WHERE id = ?',
                [$message->date, $message->date, $chatid]
            );
        }

        return true;
    }

    /**
     * Whether a chat message type carries member-entered text that should be
     * content checked. Mirrors the V1 ChatMessage::process() type filter; system
     * and templated messages (System, Promised, Nudge, etc.) are excluded.
     */
    private function isContentCheckable(?string $type): bool
    {
        return in_array($type, [
            ChatMessage::TYPE_DEFAULT,
            ChatMessage::TYPE_INTERESTED,
            ChatMessage::TYPE_REPORTEDUSER,
            ChatMessage::TYPE_ADDRESS,
        ], true);
    }

    /**
     * Mark a message as failed processing.
     *
     * A failed message is never notified to the recipient (ChatNotificationService only
     * takes processingsuccessful = 1), so record WHY. Without this a suppressed reply is
     * indistinguishable from one that was never sent, which is how a batch of dropped
     * replies got misdiagnosed as a rippling delay.
     */
    private function processFailed(int $messageId, ?string $reason = null): void
    {
        DB::table('chat_messages')
            ->where('id', $messageId)
            ->update([
                'processingrequired' => 0,
                'processingsuccessful' => 0,
                'processingfailreason' => $reason,
            ]);

        Log::info('ChatProcess: message suppressed', [
            'chatmsgid' => $messageId,
            'reason' => $reason,
        ]);
    }

    /**
     * Check if $userId is banned. There is one national site now, so a ban is a single
     * site-wide fact (users.banned) rather than something scoped to a community.
     */
    private function isBanned(int $userId): bool
    {
        return DB::table('users')
            ->where('id', $userId)
            ->whereNotNull('banned')
            ->exists();
    }

    /**
     * Set a chat lockdown_holds row's outcome, and stamp releasedat so
     * ChatNotificationService can re-admit a message whose chat_messages.date has since
     * aged out of its look-back window by the time this fires - the same problem, and the
     * same fix, as rippling_held_replies.releasedat (ChatNotificationService::getUnmailedMessages).
     */
    private function recordHoldOutcome(int $refId, string $outcome): void
    {
        DB::table('lockdown_holds')
            ->where('kind', self::HOLD_KIND)
            ->where('refid', $refId)
            ->update(['outcome' => $outcome, 'releasedat' => now()]);
    }

    /**
     * Update the sender's roster entry after they sent a message.
     *
     * V1: If the message came by email (!platform), mark it seen/emailed (since the sender
     * wrote it, they've "seen" it). For platform messages, same unless they have email-mine on.
     */
    private function updateSenderRoster(int $messageId, int $chatid, int $userid, bool $platform): void
    {
        if (! $platform) {
            // Incoming email reply: only update if there are no unseen messages from the other user.
            $hasUnseen = DB::table('chat_messages as cm')
                ->leftJoin('chat_roster as cr', function ($join) use ($userid) {
                    $join->on('cr.chatid', '=', 'cm.chatid')
                        ->where('cr.userid', '=', $userid);
                })
                ->where('cm.chatid', $chatid)
                ->where('cm.userid', '!=', $userid)
                ->where('cm.seenbyall', 0)
                ->where('cm.mailedtoall', 0)
                ->where(function ($q) {
                    $q->whereNull('cr.lastmsgseen')
                        ->orWhereColumn('cr.lastmsgseen', '<', 'cm.id');
                })
                ->where(function ($q) {
                    $q->whereNull('cr.lastmsgemailed')
                        ->orWhereColumn('cr.lastmsgemailed', '<', 'cm.id');
                })
                ->exists();

            if ($hasUnseen) {
                return;
            }
        }

        DB::table('chat_roster')
            ->where('chatid', $chatid)
            ->where('userid', $userid)
            ->where(function ($q) use ($messageId) {
                $q->whereNull('lastmsgseen')
                    ->orWhere('lastmsgseen', '<', $messageId);
            })
            ->update([
                'lastmsgseen' => $messageId,
                'lastmsgemailed' => $messageId,
            ]);
    }
}
