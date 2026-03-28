<?php

namespace App\Mail\Session;

use App\Mail\MjmlMailable;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Mail\Mailables\Envelope;

/**
 * Email verification sent when a user adds a new email address.
 *
 * V1 parity: User::verifyEmail() lines 3822-3896.
 */
class VerifyEmailMail extends MjmlMailable
{
    public function __construct(
        public readonly int $userId,
        public readonly string $email,
        public readonly string $confirmUrl,
    ) {
        parent::__construct();

        $this->mjmlData = [
            'email' => $this->email,
            'confirmUrl' => $this->confirmUrl,
        ];
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

    public function getSubject(): string
    {
        return 'Please verify your email';
    }

    protected function getRecipientUserId(): ?int
    {
        return $this->userId;
    }
}
