<?php

namespace App\Mail\Invitation;

use App\Mail\MjmlMailable;
use App\Mail\Traits\LoggableEmail;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Mail\Mailables\Envelope;

/**
 * Invitation email sent to a new user on behalf of an existing Freegle user.
 *
 * Matches the legacy User::invite() email from iznik-server.
 */
class InvitationMail extends MjmlMailable
{
    use LoggableEmail;

    public function __construct(
        public int $inviteId,
        public string $senderName,
        public string $senderEmail,
        public string $toEmail,
    ) {
        parent::__construct();
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            from: new Address(
                config('freegle.mail.noreply_addr'),
                config('freegle.branding.name')
            ),
            replyTo: [new Address($this->senderEmail)],
            subject: $this->getSubject(),
        );
    }

    protected function getSubject(): string
    {
        return "{$this->senderName} has invited you to try Freegle!";
    }

    public function build(): static
    {
        $userSite = config('freegle.sites.user');
        $inviteUrl = "{$userSite}/invite/{$this->inviteId}";

        return $this->mjmlView(
            'emails.mjml.invitation.invite',
            [
                'senderName' => $this->senderName,
                'senderEmail' => $this->senderEmail,
                'inviteUrl' => $inviteUrl,
            ]
        )->to($this->toEmail)
            ->applyLogging('Invitation');
    }
}
