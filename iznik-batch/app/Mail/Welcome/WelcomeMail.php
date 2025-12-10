<?php

namespace App\Mail\Welcome;

use App\Mail\MjmlMailable;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Mail\Mailables\Envelope;

class WelcomeMail extends MjmlMailable
{
    /**
     * Create a new message instance.
     */
    public function __construct(
        public string $recipientEmail,
        public ?string $password = NULL
    ) {
    }

    /**
     * Get the message envelope.
     */
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
        return 'Welcome to ' . config('freegle.branding.name') . '!';
    }

    /**
     * Build the message.
     */
    public function build(): static
    {
        return $this->mjmlView('emails.mjml.welcome.welcome', [
            'email' => $this->recipientEmail,
            'password' => $this->password,
        ]);
    }
}
