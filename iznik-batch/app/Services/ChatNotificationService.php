<?php

namespace App\Services;

use App\Mail\Chat\ChatNotification;
use App\Models\ChatMessage;
use App\Models\ChatRoom;
use App\Models\ChatRoster;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

class ChatNotificationService
{
    /**
     * Default delay in seconds before notifying about a message.
     * This allows users to type multiple messages before notification.
     */
    public const DEFAULT_DELAY = 30;

    /**
     * How far back to look for unmailed messages.
     */
    public const DEFAULT_SINCE_HOURS = 24;

    /**
     * Send notifications for a specific chat type.
     */
    public function notifyByEmail(
        string $chatType,
        ?int $chatId = null,
        int $delay = self::DEFAULT_DELAY,
        int $sinceHours = self::DEFAULT_SINCE_HOURS,
        bool $forceAll = false
    ): int {
        $notified = 0;

        // Get chat rooms with unmailed messages.
        $chatRooms = $this->getChatRoomsWithUnmailedMessages(
            $chatType,
            $chatId,
            $delay,
            $sinceHours,
            $forceAll
        );

        foreach ($chatRooms as $chatRoom) {
            try {
                $notified += $this->processChatRoom($chatRoom, $chatType, $forceAll);
            } catch (\Exception $e) {
                Log::error("Error processing chat room {$chatRoom->id}: " . $e->getMessage());
            }
        }

        return $notified;
    }

    /**
     * Get chat rooms that have unmailed messages.
     */
    protected function getChatRoomsWithUnmailedMessages(
        string $chatType,
        ?int $chatId,
        int $delay,
        int $sinceHours,
        bool $forceAll
    ): Collection {
        $startTime = now()->subHours($sinceHours);
        $endTime = now()->subSeconds($delay);

        $query = ChatRoom::select('chat_rooms.*')
            ->join('chat_messages', 'chat_rooms.id', '=', 'chat_messages.chatid')
            ->where('chat_rooms.chattype', $chatType)
            ->where('chat_messages.date', '>=', $startTime)
            ->where('chat_messages.date', '<=', $endTime)
            ->distinct();

        // For User2User chats, only include reviewed messages.
        if ($chatType === ChatRoom::TYPE_USER2USER) {
            $query->where('chat_messages.reviewrequired', 0)
                ->where('chat_messages.processingrequired', 0)
                ->where('chat_messages.processingsuccessful', 1);
        }

        if (!$forceAll) {
            $query->where('chat_messages.mailedtoall', 0)
                ->where('chat_messages.seenbyall', 0)
                ->where('chat_messages.reviewrejected', 0);
        }

        if ($chatId) {
            $query->where('chat_rooms.id', $chatId);
        }

        return $query->get();
    }

    /**
     * Process a single chat room and send notifications.
     */
    protected function processChatRoom(ChatRoom $chatRoom, string $chatType, bool $forceAll): int
    {
        $notified = 0;
        $lastMaxMailed = $this->getLastMailedToAll($chatRoom);

        // Get members who haven't been mailed.
        $membersToNotify = $this->getMembersToNotify($chatRoom, $forceAll);

        foreach ($membersToNotify as $roster) {
            try {
                $sendingTo = $roster->user;
                if (!$sendingTo || !$sendingTo->email_preferred) {
                    continue;
                }

                // Check if user wants email notifications.
                if (!$this->shouldNotifyUser($sendingTo, $chatRoom, $chatType)) {
                    continue;
                }

                // Get the other user in the conversation.
                $sendingFrom = $this->getOtherUser($chatRoom, $sendingTo);

                // Get unmailed messages for this user.
                $unmailedMessages = $this->getUnmailedMessages(
                    $chatRoom,
                    $roster,
                    $sendingTo,
                    $forceAll
                );

                if ($unmailedMessages->isEmpty()) {
                    continue;
                }

                // Send the notification email.
                $this->sendNotificationEmail(
                    $sendingTo,
                    $sendingFrom,
                    $chatRoom,
                    $unmailedMessages,
                    $chatType
                );

                // Update roster with last message emailed.
                $lastMessage = $unmailedMessages->last();
                $roster->update(['lastmsgemailed' => $lastMessage->id]);

                $notified++;
            } catch (\Exception $e) {
                Log::error("Error notifying user {$roster->userid} for chat {$chatRoom->id}: " . $e->getMessage());
            }
        }

        // Update mailedtoall flag for messages that have been sent to everyone.
        if ($notified > 0) {
            $this->updateMailedToAll($chatRoom, $lastMaxMailed);
        }

        return $notified;
    }

    /**
     * Get members of a chat who need to be notified.
     */
    protected function getMembersToNotify(ChatRoom $chatRoom, bool $forceAll): Collection
    {
        $query = ChatRoster::where('chatid', $chatRoom->id)
            ->with('user');

        if (!$forceAll) {
            // Only get members who haven't been mailed the latest message.
            $query->where(function ($q) use ($chatRoom) {
                $q->whereNull('lastmsgemailed')
                    ->orWhereRaw('lastmsgemailed < ?', [$chatRoom->lastmsg ?? 0]);
            });
        }

        return $query->get();
    }

    /**
     * Check if a user should be notified.
     */
    protected function shouldNotifyUser(User $user, ChatRoom $chatRoom, string $chatType): bool
    {
        // For User2Mod chats, always notify the member (user1).
        if ($chatType === ChatRoom::TYPE_USER2MOD && $chatRoom->user1 === $user->id) {
            return true;
        }

        // Check user's notification preferences.
        // This will be expanded based on the original implementation.
        return true; // For now, always notify.
    }

    /**
     * Get the other user in a chat room.
     */
    protected function getOtherUser(ChatRoom $chatRoom, User $currentUser): ?User
    {
        if ($chatRoom->user1 === $currentUser->id) {
            return User::find($chatRoom->user2);
        }

        return User::find($chatRoom->user1);
    }

    /**
     * Get unmailed messages for a user.
     */
    protected function getUnmailedMessages(
        ChatRoom $chatRoom,
        ChatRoster $roster,
        User $sendingTo,
        bool $forceAll
    ): Collection {
        $query = ChatMessage::where('chatid', $chatRoom->id)
            ->whereHas('user', function ($q) {
                $q->whereNull('deleted');
            })
            ->where('date', '>=', now()->subDays(90))
            ->orderBy('id', 'asc');

        if (!$forceAll) {
            $query->where('reviewrejected', 0);

            if ($roster->lastmsgemailed) {
                $query->where('id', '>', $roster->lastmsgemailed);
            }
        }

        // Don't notify users of their own messages.
        $query->where('userid', '!=', $sendingTo->id);

        return $query->with(['user', 'refMessage'])->get();
    }

    /**
     * Send a notification email to a user.
     */
    protected function sendNotificationEmail(
        User $sendingTo,
        ?User $sendingFrom,
        ChatRoom $chatRoom,
        Collection $messages,
        string $chatType
    ): void {
        Mail::send(new ChatNotification(
            $sendingTo,
            $sendingFrom,
            $chatRoom,
            $messages,
            $chatType
        ));
    }

    /**
     * Get the last message ID that was mailed to all members.
     */
    protected function getLastMailedToAll(ChatRoom $chatRoom): ?int
    {
        return ChatMessage::where('chatid', $chatRoom->id)
            ->where('mailedtoall', 1)
            ->max('id');
    }

    /**
     * Update the mailedtoall flag for messages that have been sent to everyone.
     */
    protected function updateMailedToAll(ChatRoom $chatRoom, ?int $lastMaxMailed): void
    {
        // Find the minimum last message emailed across all roster members.
        $minMailedToAll = ChatRoster::where('chatid', $chatRoom->id)
            ->min('lastmsgemailed');

        if ($minMailedToAll) {
            ChatMessage::where('chatid', $chatRoom->id)
                ->where('id', '>', $lastMaxMailed ?? 0)
                ->where('id', '<=', $minMailedToAll)
                ->update(['mailedtoall' => 1]);
        }
    }
}
