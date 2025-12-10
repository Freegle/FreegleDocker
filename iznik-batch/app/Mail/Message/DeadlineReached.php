<?php

namespace App\Mail\Message;

use App\Mail\MjmlMailable;
use App\Models\Group;
use App\Models\Message;
use App\Models\User;

class DeadlineReached extends MjmlMailable
{
    public Message $message;

    public User $user;

    public string $userSite;

    public string $extendUrl;

    public string $completedUrl;

    public string $withdrawUrl;

    public string $outcomeType;

    /**
     * Create a new message instance.
     */
    public function __construct(Message $message, User $user)
    {
        $this->message = $message;
        $this->user = $user;
        $this->userSite = config('freegle.sites.user');

        // Build action URLs.
        $messageId = $message->id;
        $this->extendUrl = "{$this->userSite}/mypost/{$messageId}/extend";
        $this->completedUrl = "{$this->userSite}/mypost/{$messageId}/completed";
        $this->withdrawUrl = "{$this->userSite}/mypost/{$messageId}/withdraw";

        // Determine outcome type based on message type.
        $this->outcomeType = $message->type === Message::TYPE_OFFER
            ? Message::OUTCOME_TAKEN
            : Message::OUTCOME_RECEIVED;
    }

    /**
     * Build the message.
     */
    public function build(): static
    {
        $group = $this->message->groups->first();
        $groupName = $group?->nameshort ?? 'Freegle';

        return $this->to($this->user->email_preferred, $this->user->displayname)
            ->subject($this->getSubject())
            ->mjmlView('emails.mjml.message.deadline-reached', [
                'message' => $this->message,
                'user' => $this->user,
                'userSite' => $this->userSite,
                'extendUrl' => $this->extendUrl,
                'completedUrl' => $this->completedUrl,
                'withdrawUrl' => $this->withdrawUrl,
                'outcomeType' => $this->outcomeType,
                'groupName' => $groupName,
            ]);
    }

    /**
     * Get the subject line.
     */
    protected function getSubject(): string
    {
        return 'Deadline reached: ' . $this->message->subject;
    }
}
