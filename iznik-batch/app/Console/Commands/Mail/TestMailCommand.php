<?php

namespace App\Console\Commands\Mail;

use App\Mail\Chat\ChatNotification;
use App\Mail\Digest\MultipleDigest;
use App\Mail\Digest\SingleDigest;
use App\Mail\Donation\AskForDonation;
use App\Mail\Donation\DonationThankYou;
use App\Mail\Welcome\WelcomeMail;
use App\Models\ChatMessage;
use App\Models\ChatRoom;
use App\Models\Group;
use App\Models\Membership;
use App\Models\Message;
use App\Models\User;
use App\Services\EmailSpoolerService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class TestMailCommand extends Command
{
    /**
     * The name and signature of the console command.
     */
    protected $signature = 'mail:test
                            {type? : Email type (chat:user2user, chat:user2mod, digest, donations:ask, donations:thank, welcome)}
                            {--user= : User ID to generate email for}
                            {--to= : Find user by this email address (used to build email content)}
                            {--send-to= : Deliver email to this address instead (for testing with real user data)}
                            {--from= : Override From header address (for AMP testing - must be registered with Google)}
                            {--chat= : Chat room ID (for chat types)}
                            {--group= : Group ID (for digest)}
                            {--message= : Message ID (for digest)}
                            {--message-type= : Specific chat message type (Default, Interested, Promised, Reneged, Completed, Image, Address, Nudge, Schedule)}
                            {--all-types : Send test emails for all chat message types}
                            {--amp= : Override AMP email setting (on/off, default uses config)}
                            {--dry-run : Preview email content without sending}
                            {--list : List available email types}';

    /**
     * The console command description.
     */
    protected $description = 'Send test emails without affecting database state';

    /**
     * Available email types.
     */
    protected array $emailTypes = [
        'chat:user2user' => 'User-to-user chat notification',
        'chat:user2mod' => 'User-to-moderator chat notification',
        'digest' => 'Digest email (single message)',
        'donations:ask' => 'Donation request email',
        'donations:thank' => 'Donation thank you email',
        'welcome' => 'Welcome email for new users',
    ];

    /**
     * Get the AMP override value from --amp option.
     * Returns true for 'on', false for 'off', null for default.
     */
    protected function getAmpOverride(): ?bool
    {
        $ampOption = $this->option('amp');
        if ($ampOption === null) {
            return null;
        }

        return strtolower($ampOption) === 'on';
    }

    /**
     * Chat message types to test with --all-types.
     */
    protected array $chatMessageTypes = [
        ChatMessage::TYPE_DEFAULT,
        ChatMessage::TYPE_INTERESTED,
        ChatMessage::TYPE_PROMISED,
        ChatMessage::TYPE_RENEGED,
        ChatMessage::TYPE_COMPLETED,
        ChatMessage::TYPE_IMAGE,
        ChatMessage::TYPE_ADDRESS,
        ChatMessage::TYPE_NUDGE,
        ChatMessage::TYPE_SCHEDULE,
    ];

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        if ($this->option('list')) {
            $this->listEmailTypes();
            return Command::SUCCESS;
        }

        $type = $this->argument('type');
        $dryRun = $this->option('dry-run');
        $allTypes = $this->option('all-types');

        if (!$type) {
            $this->error("Email type is required. Use --list to see available types.");
            return Command::FAILURE;
        }

        if (!isset($this->emailTypes[$type])) {
            $this->error("Unknown email type: {$type}");
            $this->listEmailTypes();
            return Command::FAILURE;
        }

        // Handle --all-types for chat notifications.
        if ($allTypes && str_starts_with($type, 'chat:')) {
            return $this->sendAllChatMessageTypes($type, $dryRun);
        }

        // Wrap everything in a transaction that we'll roll back.
        // This ensures no database state is modified.
        DB::beginTransaction();

        try {
            $mailable = $this->buildMailable($type);

            if (!$mailable) {
                DB::rollBack();
                return Command::FAILURE;
            }

            // Override From header if specified.
            // This sets the From header (visible to recipient), not the envelope from (bounce address).
            // Google AMP validation checks the From header domain against their allowlist.
            $fromOverride = $this->option('from');
            if ($fromOverride) {
                $mailable->from($fromOverride);
                $this->info("From header overridden to: {$fromOverride}");
            }

            if ($dryRun) {
                $this->previewEmail($mailable);
            } else {
                // Use the spooler to send like production does.
                // Call render() first to trigger build() which sets the 'to' address.
                $mailable->render();

                $spooler = app(EmailSpoolerService::class);

                // Use --send-to if provided, otherwise use the mailable's to address.
                $sendToOverride = $this->option('send-to');
                if ($sendToOverride) {
                    // Clear the mailable's existing recipients and set the override.
                    // This ensures the spooler uses our override address, not the original.
                    $mailable->to = [];
                    $mailable->to($sendToOverride);
                    $to = [$sendToOverride];
                    $this->info("Delivering to override address: {$sendToOverride}");
                } else {
                    $to = collect($mailable->to)->pluck('address')->toArray();
                }

                $spoolId = $spooler->spool($mailable, $to, class_basename($mailable));

                // Process the spooled email immediately.
                $stats = $spooler->processSpool(1);

                if ($stats['sent'] > 0) {
                    $this->info("Test email sent successfully! (spool ID: {$spoolId})");
                } else {
                    $this->warn("Email spooled but not sent yet. Check spool directory.");
                }
            }
        } catch (\Exception $e) {
            $this->error("Error: " . $e->getMessage());
            DB::rollBack();
            return Command::FAILURE;
        }

        // Always roll back - we never want to persist any changes.
        DB::rollBack();

        return Command::SUCCESS;
    }

    /**
     * Send test emails for all chat message types.
     */
    protected function sendAllChatMessageTypes(string $type, bool $dryRun): int
    {
        $chatType = match ($type) {
            'chat:user2user' => ChatRoom::TYPE_USER2USER,
            'chat:user2mod' => ChatRoom::TYPE_USER2MOD,
            default => ChatRoom::TYPE_USER2USER,
        };

        $toEmail = $this->option('to');
        if (!$toEmail) {
            $this->error("Please specify --to=email to find chat messages for that user");
            return Command::FAILURE;
        }

        // Find the user by email.
        $recipient = User::whereHas('emails', function ($q) use ($toEmail) {
            $q->where('email', $toEmail);
        })->first();

        if (!$recipient) {
            $this->error("No user found with email: {$toEmail}");
            return Command::FAILURE;
        }

        $this->info("Found user: {$recipient->displayname} (ID: {$recipient->id})");
        $this->newLine();

        $sentCount = 0;
        $skippedTypes = [];

        foreach ($this->chatMessageTypes as $messageType) {
            $this->info("=== Testing message type: {$messageType} ===");

            DB::beginTransaction();

            try {
                $mailable = $this->buildChatNotificationForType($chatType, $recipient, $messageType);

                if (!$mailable) {
                    $skippedTypes[] = $messageType;
                    $this->warn("Skipped {$messageType} - no messages found");
                    DB::rollBack();
                    $this->newLine();
                    continue;
                }

                // Override From header if specified.
                $fromOverride = $this->option('from');
                if ($fromOverride) {
                    $mailable->from($fromOverride);
                }

                if ($dryRun) {
                    $this->previewEmail($mailable);
                } else {
                    $mailable->render();

                    $spooler = app(EmailSpoolerService::class);

                    // Use --send-to if provided, otherwise use the mailable's to address.
                    $sendToOverride = $this->option('send-to');
                    if ($sendToOverride) {
                        // Clear the mailable's existing recipients and set the override.
                        $mailable->to = [];
                        $mailable->to($sendToOverride);
                        $to = [$sendToOverride];
                    } else {
                        $to = collect($mailable->to)->pluck('address')->toArray();
                    }

                    $spoolId = $spooler->spool($mailable, $to, class_basename($mailable));

                    $stats = $spooler->processSpool(1);

                    if ($stats['sent'] > 0) {
                        $this->info("Sent {$messageType} notification (spool ID: {$spoolId})");
                        $sentCount++;
                    } else {
                        $this->warn("Spooled but not sent yet");
                    }
                }
            } catch (\Exception $e) {
                $this->error("Error for {$messageType}: " . $e->getMessage());
                $skippedTypes[] = $messageType;
            }

            DB::rollBack();
            $this->newLine();

            // Small delay between sends to avoid overwhelming the mail server.
            if (!$dryRun) {
                usleep(500000); // 0.5 second
            }
        }

        $this->newLine();
        $this->info("=== Summary ===");
        $this->info("Sent: {$sentCount} emails");

        if (!empty($skippedTypes)) {
            $this->warn("Skipped types (no messages found): " . implode(', ', $skippedTypes));
        }

        return Command::SUCCESS;
    }

    /**
     * List available email types.
     */
    protected function listEmailTypes(): void
    {
        $this->info('Available email types:');
        $this->newLine();

        $rows = [];
        foreach ($this->emailTypes as $type => $description) {
            $rows[] = [$type, $description];
        }

        $this->table(['Type', 'Description'], $rows);

        $this->newLine();
        $this->line('Examples:');
        $this->line('  php artisan mail:test chat:user2user --user=12345 --to=test@example.com');
        $this->line('  php artisan mail:test chat:user2user --to=test@example.com --amp=on');
        $this->line('  php artisan mail:test chat:user2user --to=test@example.com --amp=off');
        $this->line('  php artisan mail:test chat:user2user --to=realuser@example.com --send-to=mytest@example.com');
        $this->line('  php artisan mail:test digest --user=12345 --group=67890 --to=test@example.com');
        $this->line('  php artisan mail:test donations:ask --user=12345 --dry-run');
    }

    /**
     * Build the mailable for the specified type.
     */
    protected function buildMailable(string $type): ?\Illuminate\Mail\Mailable
    {
        return match ($type) {
            'chat:user2user' => $this->buildChatNotification(ChatRoom::TYPE_USER2USER),
            'chat:user2mod' => $this->buildChatNotification(ChatRoom::TYPE_USER2MOD),
            'digest' => $this->buildDigest(),
            'donations:ask' => $this->buildDonationAsk(),
            'donations:thank' => $this->buildDonationThank(),
            'welcome' => $this->buildWelcome(),
            default => null,
        };
    }

    /**
     * Build a chat notification email.
     */
    protected function buildChatNotification(string $chatType): ?ChatNotification
    {
        $toEmail = $this->option('to');
        $chatId = $this->option('chat');
        $messageType = $this->option('message-type');

        // Find recipient by email.
        if (!$toEmail) {
            $this->error("Please specify --to=email to find chat messages for that user");
            return null;
        }

        // Find the user by email.
        $recipient = User::whereHas('emails', function ($q) use ($toEmail) {
            $q->where('email', $toEmail);
        })->first();

        if (!$recipient) {
            $this->error("No user found with email: {$toEmail}");
            return null;
        }

        $this->info("Found user: {$recipient->displayname} (ID: {$recipient->id})");

        // If a specific message type is requested, use that.
        if ($messageType) {
            return $this->buildChatNotificationForType($chatType, $recipient, $messageType);
        }

        // Find a chat room for this user.
        $chatRoomQuery = ChatRoom::where('chattype', $chatType)
            ->where(function ($q) use ($recipient) {
                $q->where('user1', $recipient->id)->orWhere('user2', $recipient->id);
            });

        if ($chatId) {
            $chatRoomQuery->where('id', $chatId);
        }

        $chatRoom = $chatRoomQuery->whereHas('messages', function ($q) use ($recipient) {
            $q->where('userid', '!=', $recipient->id);
        })->orderBy('id', 'desc')->first();

        if (!$chatRoom) {
            $this->error("No {$chatType} chat with messages found for user {$recipient->id}");
            return null;
        }

        $this->info("Using chat room: {$chatRoom->id}");

        // Get sender (the other user).
        $senderId = ($chatRoom->user1 === $recipient->id) ? $chatRoom->user2 : $chatRoom->user1;
        $sender = $senderId ? User::find($senderId) : null;

        // Get recent messages from the other user.
        $messages = ChatMessage::where('chatid', $chatRoom->id)
            ->where('userid', '!=', $recipient->id)
            ->orderBy('id', 'desc')
            ->limit(5)
            ->with(['user', 'refMessage'])
            ->get()
            ->reverse();

        if ($messages->isEmpty()) {
            $this->error("No messages from other users found in this chat");
            return null;
        }

        $this->info("Found " . $messages->count() . " messages from sender");

        // Get the most recent message and use the rest as previous messages.
        $latestMessage = $messages->last();
        $previousMessages = $messages->count() > 1 ? $messages->slice(0, -1) : collect();

        $mailable = new ChatNotification($recipient, $sender, $chatRoom, $latestMessage, $chatType, $previousMessages);

        // Apply AMP override if specified.
        $ampOverride = $this->getAmpOverride();
        if ($ampOverride !== null) {
            $mailable->setAmpOverride($ampOverride);
            $this->info("AMP email: " . ($ampOverride ? 'ON' : 'OFF') . " (override)");
        }

        return $mailable;
    }

    /**
     * Build a chat notification for a specific message type.
     */
    protected function buildChatNotificationForType(string $chatType, User $recipient, string $messageType): ?ChatNotification
    {
        $this->info("Looking for {$messageType} messages...");

        // Get recipient's chat room IDs first (fast query).
        $recipientChatIds = ChatRoom::where('chattype', $chatType)
            ->where(function ($q) use ($recipient) {
                $q->where('user1', $recipient->id)->orWhere('user2', $recipient->id);
            })
            ->pluck('id');

        // Find a message of this type in recipient's chats.
        $latestMessage = null;
        $chatRoom = null;

        if ($recipientChatIds->isNotEmpty()) {
            $latestMessage = ChatMessage::whereIn('chatid', $recipientChatIds)
                ->where('userid', '!=', $recipient->id)
                ->where('type', $messageType)
                ->orderBy('id', 'desc')
                ->with(['user', 'refMessage', 'chatRoom'])
                ->first();

            if ($latestMessage) {
                $chatRoom = $latestMessage->chatRoom;
            }
        }

        if (!$latestMessage) {
            // Try to find any recent message of this type (limit search for performance).
            // Only search last 30 days and use indexed columns.
            $since = now()->subDays(30);
            $latestMessage = ChatMessage::select('chat_messages.*')
                ->join('chat_rooms', 'chat_rooms.id', '=', 'chat_messages.chatid')
                ->where('chat_rooms.chattype', $chatType)
                ->where('chat_messages.type', $messageType)
                ->where('chat_messages.date', '>=', $since)
                ->orderBy('chat_messages.id', 'desc')
                ->limit(1)
                ->with(['user', 'refMessage', 'chatRoom'])
                ->first();

            if ($latestMessage) {
                $chatRoom = $latestMessage->chatRoom;
                // Override recipient to be the other user in this chat.
                $recipientId = ($chatRoom->user1 === $latestMessage->userid) ? $chatRoom->user2 : $chatRoom->user1;
                $newRecipient = User::find($recipientId);
                if ($newRecipient) {
                    $recipient = $newRecipient;
                }
                $this->warn("Using chat from different user to find {$messageType} message");
            } else {
                $this->warn("No {$messageType} messages found in any {$chatType} chat");
                return null;
            }
        }

        $this->info("Using chat room: {$chatRoom->id}");
        $this->info("Found {$messageType} message ID: {$latestMessage->id}");

        // Get sender (the other user).
        $senderId = ($chatRoom->user1 === $recipient->id) ? $chatRoom->user2 : $chatRoom->user1;
        $sender = $senderId ? User::find($senderId) : null;

        // Get some previous messages for context.
        $previousMessages = ChatMessage::where('chatid', $chatRoom->id)
            ->where('id', '<', $latestMessage->id)
            ->orderBy('id', 'desc')
            ->limit(3)
            ->with(['user', 'refMessage'])
            ->get()
            ->reverse();

        $mailable = new ChatNotification($recipient, $sender, $chatRoom, $latestMessage, $chatType, $previousMessages);

        // Apply AMP override if specified.
        $ampOverride = $this->getAmpOverride();
        if ($ampOverride !== null) {
            $mailable->setAmpOverride($ampOverride);
        }

        return $mailable;
    }

    /**
     * Build a digest email.
     */
    protected function buildDigest(): ?SingleDigest
    {
        $userId = $this->option('user');
        $groupId = $this->option('group');
        $messageId = $this->option('message');

        // Get user.
        $user = $userId ? User::find($userId) : User::whereHas('emails')->inRandomOrder()->first();
        if (!$user) {
            $this->error("User not found");
            return null;
        }

        $this->info("Generating digest for user: {$user->displayname} (ID: {$user->id})");

        // Get group.
        if ($groupId) {
            $group = Group::find($groupId);
        } else {
            // Find a group the user is a member of.
            $membership = Membership::where('userid', $user->id)->first();
            $group = $membership ? Group::find($membership->groupid) : Group::inRandomOrder()->first();
        }

        if (!$group) {
            $this->error("No group found");
            return null;
        }

        $this->info("Using group: {$group->nameshort} (ID: {$group->id})");

        // Get a message.
        if ($messageId) {
            $message = Message::find($messageId);
        } else {
            $message = Message::whereHas('groups', function ($q) use ($group) {
                $q->where('groups.id', $group->id);
            })->inRandomOrder()->first();
        }

        if (!$message) {
            $this->error("No message found for group {$group->nameshort}");
            return null;
        }

        $this->info("Using message: {$message->subject} (ID: {$message->id})");

        return new SingleDigest($user, $group, $message, 24);
    }

    /**
     * Build a donation ask email.
     */
    protected function buildDonationAsk(): ?AskForDonation
    {
        $userId = $this->option('user');

        $user = $userId ? User::find($userId) : User::whereHas('emails')->inRandomOrder()->first();
        if (!$user) {
            $this->error("User not found");
            return null;
        }

        $this->info("Generating donation ask for user: {$user->displayname} (ID: {$user->id})");

        // Try to find a recent message from this user to reference.
        $recentMessage = Message::where('fromuser', $user->id)
            ->orderBy('arrival', 'desc')
            ->first();

        $itemSubject = $recentMessage?->subject;

        return new AskForDonation($user, $itemSubject);
    }

    /**
     * Build a donation thank you email.
     */
    protected function buildDonationThank(): ?DonationThankYou
    {
        $userId = $this->option('user');

        $user = $userId ? User::find($userId) : User::whereHas('emails')->inRandomOrder()->first();
        if (!$user) {
            $this->error("User not found");
            return null;
        }

        $this->info("Generating donation thank you for user: {$user->displayname} (ID: {$user->id})");

        return new DonationThankYou($user);
    }

    /**
     * Build a welcome email.
     */
    protected function buildWelcome(): ?WelcomeMail
    {
        $userId = $this->option('user');

        $user = $userId ? User::find($userId) : User::whereHas('emails')->inRandomOrder()->first();
        if (!$user) {
            $this->error("User not found");
            return null;
        }

        $this->info("Generating welcome email for user: {$user->displayname} (ID: {$user->id})");

        // Check if user has location for nearby offers.
        $location = $user->lastLocation;
        if ($location) {
            $this->info("User location: {$location->name} ({$location->lat}, {$location->lng})");
        } else {
            $this->warn("User has no location set - nearby offers will not be shown");
        }

        // WelcomeMail takes email, optional password, and user ID for nearby offers.
        return new WelcomeMail($user->email_preferred, 'test-password-123', $user->id);
    }

    /**
     * Preview email content without sending.
     */
    protected function previewEmail(\Illuminate\Mail\Mailable $mailable): void
    {
        $this->newLine();
        $this->info('=== EMAIL PREVIEW ===');
        $this->newLine();

        // Get to address.
        $to = collect($mailable->to)->map(fn ($r) => $r['address'] ?? $r)->implode(', ');
        $this->line("To: {$to}");

        // Get subject.
        if (method_exists($mailable, 'envelope')) {
            $envelope = $mailable->envelope();
            $this->line("Subject: {$envelope->subject}");
        } elseif (property_exists($mailable, 'subject')) {
            $this->line("Subject: {$mailable->subject}");
        }

        $this->newLine();
        $this->line('--- Content (HTML) ---');
        $this->newLine();

        try {
            $rendered = $mailable->render();
            // Show a truncated preview.
            if (strlen($rendered) > 2000) {
                $this->line(substr($rendered, 0, 2000) . "\n\n... (truncated, " . strlen($rendered) . " total characters)");
            } else {
                $this->line($rendered);
            }
        } catch (\Exception $e) {
            $this->error("Could not render email: " . $e->getMessage());
        }

        $this->newLine();
        $this->info('=== END PREVIEW ===');
        $this->newLine();
        $this->comment('Use without --dry-run and with --to=your@email.com to actually send.');
    }
}
