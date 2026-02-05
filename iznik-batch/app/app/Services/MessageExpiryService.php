<?php

namespace App\Services;

use App\Mail\Message\DeadlineReached;
use App\Models\Message;
use App\Models\MessageGroup;
use App\Models\MessageOutcome;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

class MessageExpiryService
{
    /**
     * Default number of days to look back for messages.
     */
    public const EXPIRE_LOOKBACK_DAYS = 90;

    /**
     * Process messages that have reached their deadline.
     */
    public function processDeadlineExpired(): array
    {
        $stats = [
            'processed' => 0,
            'emails_sent' => 0,
            'errors' => 0,
        ];

        $messages = $this->getMessagesWithExpiredDeadline();

        foreach ($messages as $message) {
            try {
                $this->markAsExpired($message);
                $this->sendDeadlineNotification($message);
                $stats['processed']++;
                $stats['emails_sent']++;
            } catch (\Exception $e) {
                Log::error("Error processing expired message {$message->id}: " . $e->getMessage());
                $stats['errors']++;
            }
        }

        return $stats;
    }

    /**
     * Get messages that have reached their deadline without an outcome.
     */
    protected function getMessagesWithExpiredDeadline(): Collection
    {
        $earliestDate = now()->subDays(self::EXPIRE_LOOKBACK_DAYS);

        return Message::select('messages.*', 'messages_groups.groupid')
            ->join('messages_groups', 'messages_groups.msgid', '=', 'messages.id')
            ->leftJoin('messages_outcomes', 'messages_outcomes.msgid', '=', 'messages.id')
            ->where('messages.arrival', '>=', $earliestDate)
            ->whereNotNull('messages.deadline')
            ->whereRaw('messages.deadline < CURDATE()')
            ->whereNull('messages_outcomes.id')
            ->get();
    }

    /**
     * Mark a message as expired.
     */
    protected function markAsExpired(Message $message): void
    {
        MessageOutcome::create([
            'msgid' => $message->id,
            'outcome' => MessageOutcome::OUTCOME_EXPIRED,
            'comments' => 'Reached deadline',
            'timestamp' => now(),
        ]);

        Log::info("Deadline expired for message #{$message->id}: {$message->subject}");
    }

    /**
     * Send a notification email about the deadline.
     */
    protected function sendDeadlineNotification(Message $message): void
    {
        $user = $message->fromUser;

        if (!$user || !$user->email_preferred) {
            return;
        }

        Mail::send(new DeadlineReached($message, $user));
    }

    /**
     * Process messages that are expired based on spatial index.
     */
    public function processExpiredFromSpatialIndex(): int
    {
        $count = 0;

        $messages = DB::table('messages_spatial')
            ->where('successful', 0)
            ->pluck('msgid');

        foreach ($messages as $msgid) {
            try {
                $message = Message::find($msgid);
                if ($message) {
                    $this->processMessageExpiry($message);
                    $count++;
                }
            } catch (\Exception $e) {
                Log::error("Error processing spatial index expiry for {$msgid}: " . $e->getMessage());
            }

            if ($count % 100 === 0) {
                Log::info("Processed {$count} spatial index messages");
            }
        }

        return $count;
    }

    /**
     * Process expiry for a single message based on group repost settings.
     */
    protected function processMessageExpiry(Message $message): void
    {
        // Check if message has already been marked as expired or has an outcome.
        $hasOutcome = MessageOutcome::where('msgid', $message->id)->exists();

        if (!$hasOutcome) {
            // Mark as expired.
            MessageOutcome::create([
                'msgid' => $message->id,
                'outcome' => MessageOutcome::OUTCOME_EXPIRED,
                'comments' => 'Auto-expired based on group settings',
                'timestamp' => now(),
            ]);
        }

        // Remove from spatial index.
        DB::table('messages_spatial')
            ->where('msgid', $message->id)
            ->delete();
    }
}
