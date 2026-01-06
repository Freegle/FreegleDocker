<?php

namespace App\Mail\Digest;

use App\Mail\MjmlMailable;
use App\Mail\Traits\LoggableEmail;
use App\Mail\Traits\TrackableEmail;
use App\Models\Group;
use App\Models\Message;
use App\Models\User;
use App\Support\EmojiUtils;

class SingleDigest extends MjmlMailable
{
    use LoggableEmail;
    use TrackableEmail;

    public string $userSite;

    public string $deliveryUrl;

    public function __construct(
        protected User $user,
        protected Group $group,
        protected Message $message,
        protected int $frequency
    ) {
        parent::__construct();

        $this->userSite = config('freegle.sites.user');
        $this->deliveryUrl = config('freegle.delivery.base_url');

        // Initialize email tracking.
        $userId = $this->user->exists ? $this->user->id : null;

        $this->initTracking(
            'SingleDigest',
            $this->user->email_preferred,
            $userId,
            $this->group->id,
            $this->message->subject,
            [
                'message_id' => $this->message->id,
                'frequency' => $this->frequency,
            ]
        );
    }

    /**
     * Get the recipient's user ID for common header tracking.
     */
    protected function getRecipientUserId(): ?int
    {
        return $this->user->id ?? null;
    }

    /**
     * Build the message.
     */
    public function build(): static
    {
        // Get image URL via delivery service if message has attachments.
        $imageUrl = $this->getMessageImageUrl();

        // Decode emoji sequences in message text.
        $messageText = $this->message->textbody
            ? EmojiUtils::decodeEmojis($this->message->textbody)
            : null;

        return $this->mjmlView('emails.mjml.digest.single', array_merge([
            'user' => $this->user,
            'group' => $this->group,
            'message' => $this->message,
            'messageText' => $messageText,
            'frequency' => $this->frequency,
            'settingsUrl' => $this->trackedUrl($this->getSettingsUrl(), 'footer_settings', 'settings'),
            'messageUrl' => $this->trackedUrl($this->getMessageUrl(), 'view_message', 'view'),
            'userSite' => $this->userSite,
            'imageUrl' => $imageUrl,
        ], $this->getTrackingData()), 'emails.text.digest.single')
            ->to($this->user->email_preferred)
            ->applyLogging('SingleDigest');
    }

    /**
     * Get the subject line.
     */
    protected function getSubject(): string
    {
        return $this->message->subject;
    }

    /**
     * Get the settings URL for this user/group.
     */
    protected function getSettingsUrl(): string
    {
        return $this->userSite . '/settings';
    }

    /**
     * Get the URL to view this message.
     */
    protected function getMessageUrl(): string
    {
        return $this->userSite . '/message/' . $this->message->id;
    }

    /**
     * Get the message image URL via delivery service.
     */
    protected function getMessageImageUrl(): ?string
    {
        if (!$this->message->attachments || $this->message->attachments->isEmpty()) {
            return null;
        }

        $attachment = $this->message->attachments->first();

        // If there's an external URL, use it directly.
        if (!empty($attachment->externalurl)) {
            return $this->getDeliveryUrl($attachment->externalurl, 300);
        }

        // Build URL from image domain - message images use timg_ prefix for thumbnails.
        $imagesDomain = config('freegle.images.domain', 'https://images.ilovefreegle.org');
        $sourceUrl = "{$imagesDomain}/timg_{$attachment->id}.jpg";

        return $this->getDeliveryUrl($sourceUrl, 300);
    }

    /**
     * Get a URL via the delivery service for image resizing/optimization.
     */
    protected function getDeliveryUrl(string $sourceUrl, int $width): string
    {
        if (!$this->deliveryUrl) {
            return $sourceUrl;
        }

        return $this->deliveryUrl . '/?url=' . urlencode($sourceUrl) . '&w=' . $width;
    }
}
