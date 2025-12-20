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
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;

class TestMailCommand extends Command
{
    /**
     * The name and signature of the console command.
     */
    protected $signature = 'mail:test
                            {type? : Email type (chat:user2user, chat:user2mod, digest, donations:ask, donations:thank, welcome)}
                            {--user= : User ID to generate email for}
                            {--to= : Override recipient email address}
                            {--chat= : Chat room ID (for chat types)}
                            {--group= : Group ID (for digest)}
                            {--message= : Message ID (for digest)}
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
        $overrideTo = $this->option('to');

        if (!$type) {
            $this->error("Email type is required. Use --list to see available types.");
            return Command::FAILURE;
        }

        if (!isset($this->emailTypes[$type])) {
            $this->error("Unknown email type: {$type}");
            $this->listEmailTypes();
            return Command::FAILURE;
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

            // Override recipient if specified.
            if ($overrideTo) {
                $mailable->to($overrideTo);
                $this->info("Recipient overridden to: {$overrideTo}");
            }

            if ($dryRun) {
                $this->previewEmail($mailable);
            } else {
                if (!$overrideTo) {
                    $this->error('You must specify --to when sending test emails to avoid sending to real users.');
                    DB::rollBack();
                    return Command::FAILURE;
                }

                Mail::send($mailable);
                $this->info("Test email sent successfully to: {$overrideTo}");
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
        $userId = $this->option('user');
        $chatId = $this->option('chat');

        // Find a suitable chat room.
        if ($chatId) {
            $chatRoom = ChatRoom::find($chatId);
            if (!$chatRoom) {
                $this->error("Chat room not found: {$chatId}");
                return null;
            }
        } elseif ($userId) {
            // Find a chat room for this user.
            $chatRoom = ChatRoom::where('chattype', $chatType)
                ->where(function ($q) use ($userId) {
                    $q->where('user1', $userId)->orWhere('user2', $userId);
                })
                ->whereHas('messages')
                ->first();

            if (!$chatRoom) {
                $this->error("No {$chatType} chat found for user {$userId}");
                return null;
            }
        } else {
            // Find any chat with messages.
            $chatRoom = ChatRoom::where('chattype', $chatType)
                ->whereHas('messages')
                ->inRandomOrder()
                ->first();

            if (!$chatRoom) {
                $this->error("No {$chatType} chats with messages found");
                return null;
            }
        }

        $this->info("Using chat room: {$chatRoom->id}");

        // Get recipient user.
        $recipient = User::find($userId ?? $chatRoom->user1);
        if (!$recipient) {
            $this->error("Could not find recipient user");
            return null;
        }

        $this->info("Generating email for user: {$recipient->displayname} (ID: {$recipient->id})");

        // Get sender (the other user).
        $senderId = ($chatRoom->user1 === $recipient->id) ? $chatRoom->user2 : $chatRoom->user1;
        $sender = $senderId ? User::find($senderId) : null;

        // Get recent messages.
        $messages = ChatMessage::where('chatid', $chatRoom->id)
            ->where('userid', '!=', $recipient->id)
            ->orderBy('id', 'desc')
            ->limit(5)
            ->with(['user', 'refMessage'])
            ->get()
            ->reverse();

        if ($messages->isEmpty()) {
            // Create a fake message for testing.
            $this->warn("No messages from other users found, using synthetic message");
            $messages = collect([
                (object) [
                    'id' => 0,
                    'message' => 'This is a test message for email preview.',
                    'date' => now(),
                    'user' => $sender,
                    'refMessage' => null,
                ],
            ]);
        }

        return new ChatNotification($recipient, $sender, $chatRoom, $messages, $chatType);
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
