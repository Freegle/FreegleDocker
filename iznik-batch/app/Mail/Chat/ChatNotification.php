<?php

namespace App\Mail\Chat;

use App\Mail\MjmlMailable;
use App\Mail\Traits\LoggableEmail;
use App\Mail\Traits\TrackableEmail;
use App\Models\ChatMessage;
use App\Models\ChatRoom;
use App\Models\Message;
use App\Models\User;
use App\Models\UserAddress;
use App\Support\EmojiUtils;
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

        // Get job ads for the recipient and add tracked URLs.
        $jobAds = $this->recipient->getJobAds();
        $jobCount = count($jobAds['jobs']);
        foreach ($jobAds['jobs'] as $index => $job) {
            $job->tracked_url = $this->trackedUrl(
                config('freegle.sites.user') . '/job/' . $job->id .
                '?source=email&campaign=chat_notification&position=' . $index .
                '&list_length=' . $jobCount,
                'job_ad_' . $index,
                'job_click'
            );
        }

        // Check if recipient is the poster of the referenced message.
        $isRecipientPoster = $this->refMessage && $this->refMessage->fromuser === $this->recipient->id;

        // Get item image URL if there's a referenced message with attachments.
        $refMessageImageUrl = $this->getRefMessageImageUrl();

        // Build sender page URL (for clicking on sender name/image).
        $senderPageUrl = $this->sender?->id
            ? $this->trackedUrl($this->userSite . '/profile/' . $this->sender->id, 'sender_profile', 'profile')
            : null;

        $this->to($this->recipient->email_preferred, $this->recipient->displayname)
            ->subject($this->replySubject)
            ->mjmlView('emails.mjml.chat.notification', array_merge([
                'recipient' => $this->recipient,
                'recipientName' => $this->recipient->displayname,
                'sender' => $this->sender,
                'senderName' => $this->sender?->displayname ?? 'Someone',
                'senderProfileUrl' => $this->getSenderProfileUrl(),
                'senderPageUrl' => $senderPageUrl,
                'chatRoom' => $this->chatRoom,
                'chatMessage' => $preparedMessage,
                'previousMessages' => $preparedPreviousMessages,
                'chatType' => $this->chatType,
                'chatUrl' => $this->trackedUrl($this->chatUrl, 'reply_button', 'reply'),
                'refMessage' => $this->refMessage,
                'refMessageImageUrl' => $refMessageImageUrl,
                'isRecipientPoster' => $isRecipientPoster,
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

        // Get referenced message info if this message refers to an item.
        $refMessageInfo = $this->getMessageRefInfo($message);

        // Get user page URL for clicking on profile.
        $userPageUrl = $messageUser?->id
            ? $this->trackedUrl($this->userSite . '/profile/' . $messageUser->id, 'message_profile', 'profile')
            : null;

        // Get map URL for address messages.
        $mapUrl = $this->getAddressMapUrl($message);

        return [
            'id' => $message->id,
            'type' => $message->type,
            'text' => $displayText,
            'imageUrl' => $imageUrl,
            'profileUrl' => $profileUrl,
            'userPageUrl' => $userPageUrl,
            'userName' => $messageUser?->displayname ?? 'Someone',
            'date' => $message->date,
            'formattedDate' => $message->date?->format('M j, g:i a') ?? '',
            'isFromRecipient' => $isFromRecipient,
            'replyExpected' => $message->replyexpected ?? FALSE,
            'refMessage' => $refMessageInfo,
            'mapUrl' => $mapUrl,
        ];
    }

    /**
     * Get referenced message info (subject and image) for a chat message.
     */
    protected function getMessageRefInfo(ChatMessage $message): ?array
    {
        $refMsg = $message->refMessage;
        if (!$refMsg) {
            return null;
        }

        // Get the primary attachment for the referenced message.
        $attachment = $refMsg->attachments()
            ->orderByDesc('primary')
            ->first();

        $imageUrl = null;
        if ($attachment) {
            if (!empty($attachment->externalurl)) {
                $imageUrl = $this->getDeliveryUrl($attachment->externalurl, 75);
            } else {
                $imagesDomain = config('freegle.images.domain', 'https://images.ilovefreegle.org');
                $imageUrl = $this->getDeliveryUrl("{$imagesDomain}/timg_{$attachment->id}.jpg", 75);
            }
        }

        return [
            'subject' => $refMsg->subject,
            'imageUrl' => $imageUrl,
            'url' => $this->trackedUrl($this->userSite . '/message/' . $refMsg->id, 'ref_message', 'view_item'),
        ];
    }

    /**
     * Get display text for a message based on its type.
     */
    protected function getMessageDisplayText(ChatMessage $message): string
    {
        // Decode emoji escape sequences (\u{codepoints}\u) to actual emojis.
        $text = EmojiUtils::decodeEmojis($message->message ?? '');

        switch ($message->type) {
            case ChatMessage::TYPE_INTERESTED:
                // Show the user's message if any, otherwise just indicate interest.
                return $text ?: 'Interested in this:';

            case ChatMessage::TYPE_PROMISED:
                $otherUser = $this->sender?->displayname ?? 'Someone';
                if ($message->userid === $this->recipient->id) {
                    return "You promised this to {$otherUser}:";
                }
                return "{$otherUser} promised this to you:";

            case ChatMessage::TYPE_RENEGED:
                $otherUser = $this->sender?->displayname ?? 'Someone';
                if ($message->userid === $this->recipient->id) {
                    return "You cancelled your promise for:";
                }
                return "{$otherUser} cancelled their promise for:";

            case ChatMessage::TYPE_COMPLETED:
                return "This item is no longer available:";

            case ChatMessage::TYPE_ADDRESS:
                return $this->getAddressDisplayText($message);

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
     * Get display text for an address message.
     * The message field contains the address ID which we look up from users_addresses.
     */
    protected function getAddressDisplayText(ChatMessage $message): string
    {
        $otherUser = $this->sender?->displayname ?? 'Someone';
        $isFromRecipient = $message->userid === $this->recipient->id;

        // Build the intro text based on who sent the address.
        $intro = $isFromRecipient
            ? "You sent an address to {$otherUser}."
            : "{$otherUser} sent you an address.";

        // The message field contains the address ID.
        $addressId = intval($message->message);
        if (!$addressId) {
            return $intro;
        }

        // Look up the address.
        $userAddress = UserAddress::find($addressId);
        if (!$userAddress) {
            return $intro;
        }

        // Get the formatted multiline address.
        $formattedAddress = $userAddress->getMultiLine();
        if (!$formattedAddress) {
            return $intro;
        }

        // Build the full text with the address.
        $result = $intro . "\n\n" . $formattedAddress;

        // Add collection instructions if present.
        if (!empty($userAddress->instructions)) {
            $result .= "\n\n" . $userAddress->instructions;
        }

        return $result;
    }

    /**
     * Get Google Maps URL for an address message.
     */
    protected function getAddressMapUrl(ChatMessage $message): ?string
    {
        if ($message->type !== ChatMessage::TYPE_ADDRESS) {
            return null;
        }

        // The message field contains the address ID.
        $addressId = intval($message->message);
        if (!$addressId) {
            return null;
        }

        // Look up the address.
        $userAddress = UserAddress::find($addressId);
        if (!$userAddress) {
            return null;
        }

        // Get coordinates (falls back to postcode if needed).
        [$lat, $lng] = $userAddress->getCoordinates();
        if (!$lat || !$lng) {
            return null;
        }

        // Build Google Maps URL with tracked link.
        $googleMapsUrl = "https://maps.google.com/?q={$lat},{$lng}&z=16";

        return $this->trackedUrl($googleMapsUrl, 'address_map', 'map');
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

        $imagesDomain = config('freegle.images.domain', 'https://images.ilovefreegle.org');

        // Chat message images use mimg_{id}.jpg format (m = message/chat).
        if ($message->imageid) {
            $sourceUrl = "{$imagesDomain}/mimg_{$message->imageid}.jpg";
            return $this->getDeliveryUrl($sourceUrl, 200);
        }

        // Try to get image from the images relationship.
        $image = $message->images()->first();
        if ($image) {
            $sourceUrl = "{$imagesDomain}/mimg_{$image->id}.jpg";
            return $this->getDeliveryUrl($sourceUrl, 200);
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

    /**
     * Get the primary image URL for the referenced message.
     */
    protected function getRefMessageImageUrl(): ?string
    {
        if (!$this->refMessage) {
            return null;
        }

        // Get primary attachment for this message.
        $attachment = $this->refMessage->attachments()
            ->orderByDesc('primary')
            ->first();

        if (!$attachment) {
            return null;
        }

        // If there's an external URL, use it directly.
        if (!empty($attachment->externalurl)) {
            return $this->getDeliveryUrl($attachment->externalurl, 200);
        }

        // Build URL from image domain - message images use timg_ prefix for thumbnails.
        $imagesDomain = config('freegle.images.domain', 'https://images.ilovefreegle.org');
        $sourceUrl = "{$imagesDomain}/timg_{$attachment->id}.jpg";

        return $this->getDeliveryUrl($sourceUrl, 200);
    }
}
