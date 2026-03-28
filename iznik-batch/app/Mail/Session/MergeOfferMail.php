<?php

namespace App\Mail\Session;

use App\Mail\MjmlMailable;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Mail\Mailables\Envelope;

/**
 * Merge offer email sent to users who may have duplicate accounts.
 *
 * V1 parity: merge.php lines 149-211.
 */
class MergeOfferMail extends MjmlMailable
{
    public function __construct(
        public readonly int $recipientUserId,
        public readonly string $recipientName,
        public readonly string $recipientEmail,
        public readonly string $name1,
        public readonly string $email1,
        public readonly string $name2,
        public readonly string $email2,
        public readonly string $mergeUrl,
    ) {
        parent::__construct();

        $this->mjmlData = [
            'name1' => $this->name1,
            'email1' => $this->email1,
            'name2' => $this->name2,
            'email2' => $this->email2,
            'mergeUrl' => $this->mergeUrl,
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
        return 'You have multiple Freegle accounts - please read';
    }

    protected function getRecipientUserId(): ?int
    {
        return $this->recipientUserId;
    }
}
