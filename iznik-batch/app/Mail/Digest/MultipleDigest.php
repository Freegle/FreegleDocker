<?php

namespace App\Mail\Digest;

use App\Mail\MjmlMailable;
use App\Mail\Traits\LoggableEmail;
use App\Mail\Traits\TrackableEmail;
use App\Models\Group;
use App\Models\User;
use App\Support\EmojiUtils;
use Illuminate\Support\Collection;

class MultipleDigest extends MjmlMailable
{
    use LoggableEmail;
    use TrackableEmail;

    public string $userSite;

    public string $deliveryUrl;

    public function __construct(
        protected User $user,
        protected Group $group,
        protected Collection $messages,
        protected int $frequency
    ) {
        parent::__construct();

        $this->userSite = config('freegle.sites.user');
        $this->deliveryUrl = config('freegle.delivery.base_url');

        // Initialize email tracking.
        $userId = $this->user->exists ? $this->user->id : null;
        $count = $this->messages->count();

        $this->initTracking(
            'MultipleDigest',
            $this->user->email_preferred,
            $userId,
            $this->group->id,
            "{$count} new post" . ($count === 1 ? '' : 's') . " on {$this->group->nameshort}",
            [
                'message_count' => $count,
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
        // Prepare messages with image URLs and decoded text.
        $preparedMessages = $this->prepareMessages();

        return $this->mjmlView('emails.mjml.digest.multiple', array_merge([
            'user' => $this->user,
            'group' => $this->group,
            'messages' => $preparedMessages,
            'frequency' => $this->frequency,
            'messageCount' => $this->messages->count(),
            'settingsUrl' => $this->trackedUrl($this->getSettingsUrl(), 'footer_settings', 'settings'),
            'userSite' => $this->userSite,
        ], $this->getTrackingData()), 'emails.text.digest.multiple')
            ->to($this->user->email_preferred)
            ->applyLogging('MultipleDigest');
    }

    /**
     * Get the subject line.
     */
    protected function getSubject(): string
    {
        $count = $this->messages->count();
        return "{$count} new post" . ($count === 1 ? '' : 's') . " on {$this->group->nameshort}";
    }

    /**
     * Get the settings URL for this user/group.
     */
    protected function getSettingsUrl(): string
    {
        return $this->userSite . '/settings';
    }

    /**
     * Prepare messages with image URLs and decoded text.
     */
    protected function prepareMessages(): Collection
    {
        return $this->messages->map(function ($message) {
            return [
                'id' => $message->id,
                'subject' => $message->subject,
                'type' => $message->type,
                'textbody' => $message->textbody
                    ? EmojiUtils::decodeEmojis($message->textbody)
                    : null,
                'imageUrl' => $this->getMessageImageUrl($message),
                'messageUrl' => $this->trackedUrl(
                    $this->userSite . '/message/' . $message->id,
                    'view_message_' . $message->id,
                    'view'
                ),
            ];
        });
    }

    /**
     * Get the message image URL via delivery service.
     */
    protected function getMessageImageUrl($message): ?string
    {
        if (!$message->attachments || $message->attachments->isEmpty()) {
            return null;
        }

        $attachment = $message->attachments->first();

        // If there's an external URL, use it directly.
        if (!empty($attachment->externalurl)) {
            return $this->getDeliveryUrl($attachment->externalurl, 150);
        }

        // Build URL from image domain - message images use timg_ prefix for thumbnails.
        $imagesDomain = config('freegle.images.domain', 'https://images.ilovefreegle.org');
        $sourceUrl = "{$imagesDomain}/timg_{$attachment->id}.jpg";

        return $this->getDeliveryUrl($sourceUrl, 150);
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
