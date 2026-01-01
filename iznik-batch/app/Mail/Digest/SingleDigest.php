<?php

namespace App\Mail\Digest;

use App\Mail\MjmlMailable;
use App\Mail\Traits\LoggableEmail;
use App\Models\Group;
use App\Models\Message;
use App\Models\User;

class SingleDigest extends MjmlMailable
{
    use LoggableEmail;

    public function __construct(
        protected User $user,
        protected Group $group,
        protected Message $message,
        protected int $frequency
    ) {
        parent::__construct();
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
        return $this->mjmlView('emails.mjml.digest.single', [
            'user' => $this->user,
            'group' => $this->group,
            'message' => $this->message,
            'frequency' => $this->frequency,
            'settingsUrl' => $this->getSettingsUrl(),
            'messageUrl' => $this->getMessageUrl(),
        ])->to($this->user->email_preferred)
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
        return config('freegle.sites.user') . '/settings';
    }

    /**
     * Get the URL to view this message.
     */
    protected function getMessageUrl(): string
    {
        return config('freegle.sites.user') . '/message/' . $this->message->id;
    }
}
