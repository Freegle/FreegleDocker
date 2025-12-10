<?php

namespace App\Mail\Digest;

use App\Mail\MjmlMailable;
use App\Models\Group;
use App\Models\User;
use Illuminate\Support\Collection;

class MultipleDigest extends MjmlMailable
{
    public function __construct(
        protected User $user,
        protected Group $group,
        protected Collection $messages,
        protected int $frequency
    ) {}

    /**
     * Build the message.
     */
    public function build(): static
    {
        return $this->mjmlView('emails.mjml.digest.multiple', [
            'user' => $this->user,
            'group' => $this->group,
            'messages' => $this->messages,
            'frequency' => $this->frequency,
            'messageCount' => $this->messages->count(),
            'settingsUrl' => $this->getSettingsUrl(),
        ])->to($this->user->email_preferred);
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
        return config('freegle.sites.user') . '/settings';
    }
}
