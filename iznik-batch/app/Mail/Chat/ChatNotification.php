<?php

namespace App\Mail\Chat;

use App\Mail\MjmlMailable;
use App\Mail\Traits\LoggableEmail;
use App\Mail\Traits\TrackableEmail;
use App\Models\ChatMessage;
use App\Models\ChatRoom;
use App\Models\Message;
use App\Models\User;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Support\Collection;
use Symfony\Component\Mime\Email;

class ChatNotification extends MjmlMailable
{
    use TrackableEmail;
    use LoggableEmail;

    public User $recipient;

    public ?User $sender;

    public ChatRoom $chatRoom;

    public ChatMessage $message;

    public string $chatType;

    public string $userSite;

    public string $deliveryUrl;

    public string $chatUrl;

    public string $replySubject;

    public Collection $previousMessages;

    public ?Message $refMessage;

    protected string $replyToAddress;

    protected string $fromDisplayName;

    protected string $userDomain;

    /**
     * Create a new message instance.
     */
    public function __construct(
        User $recipient,
        ?User $sender,
        ChatRoom $chatRoom,
        ChatMessage $message,
        string $chatType,
        ?Collection $previousMessages = NULL
    ) {
        $this->recipient = $recipient;
        $this->sender = $sender;
        $this->chatRoom = $chatRoom;
        $this->message = $message;
        $this->chatType = $chatType;
        $this->previousMessages = $previousMessages ?? collect();
        $this->userSite = config('freegle.sites.user');
        $this->deliveryUrl = config('freegle.delivery.base_url');
        $this->userDomain = config('freegle.mail.user_domain', 'users.ilovefreegle.org');
        $this->chatUrl = $this->userSite . '/chats/' . $chatRoom->id;

        // Get referenced message from the chat message.
        $this->refMessage = $message->refMessage;

        // Build the subject line.
        $this->replySubject = $this->generateSubject();

        // Build reply-to address for chat routing.
        // Format: notify-{chatid}-{userid}@{domain}
        $this->replyToAddress = 'notify-' . $chatRoom->id . '-' . $recipient->id . '@' . $this->userDomain;

        // Build from display name: "SenderName on Freegle".
        $senderName = $sender?->displayname ?? 'Someone';
        $siteName = config('freegle.branding.name', 'Freegle');
        $this->fromDisplayName = $senderName . ' on ' . $siteName;

        // Initialize email tracking.
        // Only pass user ID if it's a persisted user (exists in database).
        $userId = $this->recipient->exists ? $this->recipient->id : null;

        $this->initTracking(
            'ChatNotification',
            $this->recipient->email_preferred,
            $userId,
            NULL,
            $this->replySubject,
            [
                'chat_id' => $chatRoom->id,
                'sender_id' => $sender?->id,
                'message_id' => $message->id,
            ]
        );
    }

    /**
     * Get the message envelope with custom from/replyTo.
     */
    public function envelope(): Envelope
    {
        return new Envelope(
            from: new Address($this->replyToAddress, $this->fromDisplayName),
            replyTo: [new Address($this->replyToAddress, $this->fromDisplayName)],
            subject: $this->replySubject,
        );
    }

    /**
     * Build the message.
     */
    public function build(): static
    {
        // Prepare the message with display-friendly data.
        $preparedMessage = $this->prepareMessage($this->message);
        $preparedPreviousMessages = $this->prepareMessages($this->previousMessages);

        // Check if we should show outcome buttons (for OFFER items with Interested messages).
        $showOutcomeButtons = $this->shouldShowOutcomeButtons();
        $outcomeUrls = $showOutcomeButtons ? $this->getOutcomeUrls() : [];

        // Check if reply is expected.
        $replyExpected = $this->message->replyexpected ?? FALSE;

        // Get job ads for the recipient.
        $jobAds = $this->recipient->getJobAds();

        $this->to($this->recipient->email_preferred, $this->recipient->displayname)
            ->subject($this->replySubject)
            ->mjmlView('emails.mjml.chat.notification', array_merge([
                'recipient' => $this->recipient,
                'recipientName' => $this->recipient->displayname,
                'sender' => $this->sender,
                'senderName' => $this->sender?->displayname ?? 'Someone',
                'senderProfileUrl' => $this->getSenderProfileUrl(),
                'chatRoom' => $this->chatRoom,
                'chatMessage' => $preparedMessage,
                'previousMessages' => $preparedPreviousMessages,
                'chatType' => $this->chatType,
                'chatUrl' => $this->trackedUrl($this->chatUrl, 'reply_button', 'reply'),
                'refMessage' => $this->refMessage,
                'replyExpected' => $replyExpected,
                'showOutcomeButtons' => $showOutcomeButtons,
                'outcomeUrls' => $outcomeUrls,
                'isUser2Mod' => $this->chatType === ChatRoom::TYPE_USER2MOD,
                'settingsUrl' => $this->trackedUrl($this->userSite . '/settings', 'footer_settings', 'settings'),
                'jobAds' => $jobAds['jobs'],
                'jobsUrl' => $this->trackedUrl($this->userSite . '/jobs', 'jobs_link', 'jobs'),
                'donateUrl' => $this->trackedUrl('https://freegle.in/paypal1510', 'donate_link', 'donate'),
            ], $this->getTrackingData()), 'emails.text.chat.notification');

        // Add custom X-Freegle headers and read receipts.
        $this->withSymfonyMessage(function (Email $symfonyMessage) {
            $headers = $symfonyMessage->getHeaders();

            // Add mail type header for tracking.
            $headers->addTextHeader('X-Freegle-Mail-Type', 'ChatNotification');

            // Add List-Unsubscribe headers for RFC 8058 one-click unsubscribe.
            // Only for persisted users with valid IDs.
            if ($this->recipient->exists && $this->recipient->id) {
                $headers->addTextHeader('List-Unsubscribe', $this->recipient->listUnsubscribe());
                $headers->addTextHeader('List-Unsubscribe-Post', 'List-Unsubscribe=One-Click');
            }

            // Add referenced message IDs if available.
            if ($this->refMessage) {
                $headers->addTextHeader('X-Freegle-Msgids', (string) $this->refMessage->id);
            }

            // Add sender user ID for User2User chats.
            if ($this->chatType === ChatRoom::TYPE_USER2USER && $this->sender?->id) {
                $headers->addTextHeader('X-Freegle-From-UID', (string) $this->sender->id);
            }

            // Add group ID for User2Mod chats.
            if ($this->chatType === ChatRoom::TYPE_USER2MOD && $this->chatRoom->groupid) {
                $headers->addTextHeader('X-Freegle-Group-Volunteer', (string) $this->chatRoom->groupid);
            }

            // Add read receipt headers for User2User chats.
            if ($this->chatType === ChatRoom::TYPE_USER2USER && $this->recipient->exists) {
                $readReceiptAddr = "readreceipt-{$this->chatRoom->id}-{$this->recipient->id}-{$this->message->id}@{$this->userDomain}";
                $headers->addTextHeader('Disposition-Notification-To', $readReceiptAddr);
                $headers->addTextHeader('Return-Receipt-To', $readReceiptAddr);
            }
        });

        // Apply email logging if configured.
        $this->applyLogging('ChatNotification');

        return $this;
    }

    /**
     * Get the subject line for the email.
     */
    protected function getSubject(): string
    {
        return $this->replySubject;
    }

    /**
     * Generate the subject line based on context.
     */
    protected function generateSubject(): string
    {
        $senderName = $this->sender?->displayname ?? 'Someone';

        if ($this->chatType === ChatRoom::TYPE_USER2MOD) {
            $group = $this->chatRoom->group;
            $groupName = $group?->nameshort ?? 'your local Freegle group';
            return "Message from {$groupName} volunteers";
        }

        if ($this->refMessage) {
            // Use "Regarding:" instead of "Re:" for Validity Certification compliance.
            // Email deliverability standards flag "Re:" as potentially deceptive.
            return "Regarding: {$this->refMessage->subject}";
        }

        return "{$senderName} sent you a message on Freegle";
    }

    /**
     * Prepare messages for display.
     */
    protected function prepareMessages(Collection $messages): Collection
    {
        return $messages->map(function ($message) {
            return $this->prepareMessage($message);
        });
    }

    /**
     * Prepare a single message for display.
     */
    protected function prepareMessage(ChatMessage $message): array
    {
        $isFromRecipient = $message->userid === $this->recipient->id;
        $messageUser = $message->user;

        // Get display text based on message type.
        $displayText = $this->getMessageDisplayText($message);

        // Get profile image URL.
        $profileUrl = $this->getProfileImageUrl($messageUser);

        // Get image URL if this is an image message.
        $imageUrl = $this->getMessageImageUrl($message);

        return [
            'id' => $message->id,
            'type' => $message->type,
            'text' => $displayText,
            'imageUrl' => $imageUrl,
            'profileUrl' => $profileUrl,
            'userName' => $messageUser?->displayname ?? 'Someone',
            'date' => $message->date,
            'formattedDate' => $message->date?->format('M j, g:i a') ?? '',
            'isFromRecipient' => $isFromRecipient,
            'replyExpected' => $message->replyexpected ?? FALSE,
        ];
    }

    /**
     * Get display text for a message based on its type.
     */
    protected function getMessageDisplayText(ChatMessage $message): string
    {
        $text = $message->message ?? '';

        switch ($message->type) {
            case ChatMessage::TYPE_INTERESTED:
                $refSubject = $message->refMessage?->subject ?? '';
                if ($refSubject && $text) {
                    return "{$text}";
                }
                return $text ?: 'Expressed interest';

            case ChatMessage::TYPE_PROMISED:
                $otherUser = $this->sender?->displayname ?? 'Someone';
                $refSubject = $message->refMessage?->subject ?? 'an item';
                if ($message->userid === $this->recipient->id) {
                    return "You promised {$refSubject} to {$otherUser}";
                }
                return "{$otherUser} promised {$refSubject} to you";

            case ChatMessage::TYPE_RENEGED:
                $otherUser = $this->sender?->displayname ?? 'Someone';
                if ($message->userid === $this->recipient->id) {
                    return "You cancelled your promise";
                }
                return "{$otherUser} cancelled their promise";

            case ChatMessage::TYPE_COMPLETED:
                return "This item is no longer available.";

            case ChatMessage::TYPE_ADDRESS:
                return "Shared their address with you.";

            case ChatMessage::TYPE_NUDGE:
                if ($message->userid === $this->recipient->id) {
                    return "You sent a nudge - please can you reply?";
                }
                return "Nudge - please can you reply?";

            case ChatMessage::TYPE_MODMAIL:
                return "Message from volunteers: " . $text;

            case ChatMessage::TYPE_IMAGE:
                return $text ?: 'Sent an image';

            case ChatMessage::TYPE_SCHEDULE:
                return $text ?: 'Suggested a collection time';

            default:
                return $text ?: '(Empty message)';
        }
    }

    /**
     * Get profile image URL for a user, optimized via delivery service.
     *
     * @param User|null $user The user
     * @param int $width The desired width (default 40px for message avatars)
     */
    protected function getProfileImageUrl(?User $user, int $width = 40): string
    {
        // Check both for a user ID and that the user exists in the database.
        // Mock/test users may have IDs but exists=false.
        if (!$user || !$user->id || !$user->exists) {
            return $this->getDefaultProfileUrl($width);
        }

        // Get the user's profile image URL from their users_images record.
        $sourceUrl = $user->getProfileImageUrl(TRUE);

        if (!$sourceUrl) {
            return $this->getDefaultProfileUrl($width);
        }

        return $this->getDeliveryUrl($sourceUrl, $width);
    }

    /**
     * Get default profile image URL via delivery service.
     */
    protected function getDefaultProfileUrl(int $width = 40): string
    {
        $imagesDomain = config('freegle.images.domain', 'https://images.ilovefreegle.org');
        $sourceUrl = $imagesDomain . '/defaultprofile.png';
        return $this->getDeliveryUrl($sourceUrl, $width);
    }

    /**
     * Get sender profile URL for the sender section.
     */
    protected function getSenderProfileUrl(): string
    {
        return $this->getProfileImageUrl($this->sender, 40);
    }

    /**
     * Get a URL via the delivery service for image resizing/optimization.
     *
     * @param string $sourceUrl The source image URL
     * @param int $width The desired width
     */
    protected function getDeliveryUrl(string $sourceUrl, int $width): string
    {
        if (!$this->deliveryUrl) {
            return $sourceUrl;
        }

        return $this->deliveryUrl . '/?url=' . urlencode($sourceUrl) . '&w=' . $width;
    }

    /**
     * Get image URL for an image message.
     */
    protected function getMessageImageUrl(ChatMessage $message): ?string
    {
        if ($message->type !== ChatMessage::TYPE_IMAGE) {
            return NULL;
        }

        // Chat images are accessed via /cimg/{imageid}.
        if ($message->imageid) {
            return $this->userSite . '/cimg/' . $message->imageid;
        }

        // Try to get image from the images relationship.
        $image = $message->images()->first();
        if ($image) {
            return $this->userSite . '/cimg/' . $image->id;
        }

        return NULL;
    }

    /**
     * Check if we should show outcome buttons.
     * Only show for Interested messages on OFFER items where recipient is the poster.
     */
    protected function shouldShowOutcomeButtons(): bool
    {
        if (!$this->refMessage) {
            return FALSE;
        }

        // Only for OFFER items.
        if ($this->refMessage->type !== Message::TYPE_OFFER) {
            return FALSE;
        }

        // Only if recipient is the poster.
        if ($this->refMessage->fromuser !== $this->recipient->id) {
            return FALSE;
        }

        // Only if this is an Interested message.
        return $this->message->type === ChatMessage::TYPE_INTERESTED;
    }

    /**
     * Get outcome URLs for TAKEN/WITHDRAWN buttons.
     */
    protected function getOutcomeUrls(): array
    {
        if (!$this->refMessage) {
            return [];
        }

        $msgId = $this->refMessage->id;

        return [
            'taken' => $this->trackedUrl(
                $this->userSite . '/message/' . $msgId . '?outcome=Taken',
                'outcome_taken',
                'outcome'
            ),
            'withdrawn' => $this->trackedUrl(
                $this->userSite . '/message/' . $msgId . '?outcome=Withdrawn',
                'outcome_withdrawn',
                'outcome'
            ),
        ];
    }
}
