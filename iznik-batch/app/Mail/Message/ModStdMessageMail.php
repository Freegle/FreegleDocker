<?php

namespace App\Mail\Message;

use App\Mail\MjmlMailable;
use Illuminate\Mail\Mailables\Envelope;

/**
 * Email sent from a moderator to a message poster using a standard message.
 * Used for approve, reject, reply, and delete actions.
 */
class ModStdMessageMail extends MjmlMailable
{
    protected string $mjmlTemplate = 'emails.mjml.message.mod-std-message';

    public function __construct(
        public readonly string $modName,
        public readonly string $groupName,
        public readonly string $stdSubject,
        public readonly string $stdBody,
        public readonly string $messageSubject,
        public readonly int $msgId,
        public readonly int $recipientUserId,
    ) {
        parent::__construct();

        $this->mjmlData = [
            'modName' => $this->modName,
            'groupName' => $this->groupName,
            'body' => $this->stdBody,
            'messageSubject' => $this->messageSubject,
            'userSite' => config('freegle.sites.user'),
        ];
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: $this->stdSubject,
        );
    }

    public function getSubject(): string
    {
        return $this->stdSubject;
    }

    protected function getRecipientUserId(): ?int
    {
        return $this->recipientUserId;
    }
}
