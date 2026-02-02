<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;
use Symfony\Component\Mime\Email;

/**
 * Auto-reply sent when a human accidentally replies to a bounce Return-Path address.
 *
 * Includes all standard loop prevention headers to avoid mail loops.
 */
class BounceAddressAutoReply extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public string $recipientEmail,
    ) {}

    public function envelope(): Envelope
    {
        $siteName = config('freegle.site_name', 'Freegle');

        return new Envelope(
            from: new Address(
                'noreply@'.config('freegle.mail.user_domain', 'users.ilovefreegle.org'),
                $siteName
            ),
            subject: "Your reply could not be delivered - {$siteName}",
        );
    }

    public function content(): \Illuminate\Mail\Mailables\Content
    {
        return new \Illuminate\Mail\Mailables\Content(
            text: 'emails.bounce-auto-reply-text',
        );
    }

    /**
     * Add loop prevention headers and null Return-Path.
     */
    public function build(): static
    {
        $this->withSymfonyMessage(function (Email $message) {
            $message->getHeaders()->addTextHeader('Auto-Submitted', 'auto-replied');
            $message->getHeaders()->addTextHeader('X-Auto-Response-Suppress', 'All');
            $message->getHeaders()->addTextHeader('Precedence', 'auto_reply');
            // Null Return-Path so bounces of this auto-reply don't generate further replies
            $message->returnPath('');
        });

        return $this;
    }
}
