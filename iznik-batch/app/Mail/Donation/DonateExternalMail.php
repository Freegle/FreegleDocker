<?php

namespace App\Mail\Donation;

use App\Mail\MjmlMailable;
use App\Mail\Traits\LoggableEmail;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Mail\Mailables\Envelope;

/**
 * Notification email sent to info@ilovefreegle.org when an external donation is recorded.
 *
 * Matches the legacy donations.php PUT email from iznik-server.
 */
class DonateExternalMail extends MjmlMailable
{
    use LoggableEmail;

    public function __construct(
        public string $userName,
        public int $userId,
        public string $userEmail,
        public float $amount,
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
            subject: $this->getSubject(),
        );
    }

    protected function getSubject(): string
    {
        return "{$this->userName} ({$this->userEmail}) donated £{$this->amount} via an external donation. Please can you thank them?";
    }

    public function build(): static
    {
        $infoAddr = config('freegle.mail.info_addr');
        $ccAddr = config('freegle.mail.donation_cc_addr');

        $mailable = $this->mjmlView(
            'emails.mjml.donation.donate-external',
            [
                'userName' => $this->userName,
                'userId' => $this->userId,
                'userEmail' => $this->userEmail,
                'amount' => $this->amount,
            ]
        )->to($infoAddr)
            ->applyLogging('DonateExternal');

        if ($ccAddr) {
            $mailable->cc($ccAddr);
        }

        return $mailable;
    }
}
