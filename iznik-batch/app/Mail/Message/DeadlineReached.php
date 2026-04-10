<?php

namespace App\Mail\Message;

use App\Mail\MjmlMailable;
use App\Mail\Traits\LoggableEmail;
use App\Mail\Traits\TrackableEmail;
use App\Models\Group;
use App\Models\Message;
use App\Models\User;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Mail\Mailables\Envelope;

class DeadlineReached extends MjmlMailable
{
    use TrackableEmail;
    use LoggableEmail;

    public Message $message;

    public User $user;

    /**
     * Create a new message instance.
     */
    public function __construct(Message $message, User $user)
    {
        parent::__construct();

        $this->message = $message;
        $this->user = $user;

        $group = $message->groups->first();

        $this->initTracking(
            'DeadlineReached',
            $user->email_preferred,
            $user->id,
            $group?->id,
            $this->getSubject(),
            ['message_id' => $message->id]
        );
    }

    /**
     * Build the message.
     */
    public function build(): static
    {
        $userSite = config('freegle.sites.user');
        $messageId = $this->message->id;
        $group = $this->message->groups->first();
        $groupName = $group?->nameshort ?? 'Freegle';
        $outcomeType = $this->message->type === Message::TYPE_OFFER
            ? Message::OUTCOME_TAKEN
            : Message::OUTCOME_RECEIVED;

        return $this->to($this->user->email_preferred, $this->user->displayname)
            ->subject($this->getSubject())
            ->mjmlView('emails.mjml.message.deadline-reached', array_merge([
                'post' => $this->message,
                'user' => $this->user,
                'outcomeType' => $outcomeType,
                'groupName' => $groupName,
                'extendUrl' => $this->trackedUrl(
                    "{$userSite}/mypost/{$messageId}/extend",
                    'extend_button',
                    'extend'
                ),
                'completedUrl' => $this->trackedUrl(
                    "{$userSite}/mypost/{$messageId}/completed",
                    'completed_button',
                    'completed'
                ),
                'withdrawUrl' => $this->trackedUrl(
                    "{$userSite}/mypost/{$messageId}/withdraw",
                    'withdraw_button',
                    'withdraw'
                ),
                'settingsUrl' => $this->trackedUrl(
                    "{$userSite}/settings",
                    'footer_settings',
                    'settings'
                ),
                'email' => $this->user->email_preferred,
            ], $this->getTrackingData()))
            ->applyLogging('DeadlineReached');
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            from: new Address(
                config('freegle.mail.noreply_addr'),
                config('freegle.branding.name')
            ),
            subject: $this->getSubject(),
        );
    }

    /**
     * Get the subject line.
     */
    protected function getSubject(): string
    {
        return 'Deadline reached: ' . $this->message->subject;
    }

    /**
     * Get the recipient's user ID for common header tracking.
     */
    protected function getRecipientUserId(): ?int
    {
        return $this->user->id ?? null;
    }
}
