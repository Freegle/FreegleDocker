<?php

namespace App\Mail\Pledge;

use App\Mail\MjmlMailable;
use App\Mail\Traits\LoggableEmail;
use App\Models\User;
use Illuminate\Mail\Mailables\Envelope;
use Symfony\Component\Mime\Address;

class PledgeThankYou extends MjmlMailable
{
    use LoggableEmail;

    public User $user;

    public int $monthsFreegled;

    public string $userSite;

    public function __construct(User $user, int $monthsFreegled)
    {
        parent::__construct();

        $this->user = $user;
        $this->monthsFreegled = $monthsFreegled;
        $this->userSite = config('freegle.sites.user');
    }

    protected function getRecipientUserId(): ?int
    {
        return $this->user->id ?? NULL;
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

    public function build(): static
    {
        $firstName = $this->user->firstname ?: $this->user->displayname;
        $email = $this->user->email_preferred;

        // Set recipient if we have an email (test mode sets ->to() externally).
        if ($email) {
            $this->to($email, $this->user->displayname);
        }

        return $this->subject($this->getSubject())
            ->mjmlView('emails.mjml.pledge.thank-you', [
                'firstName' => $firstName,
                'monthsFreegled' => $this->monthsFreegled,
                'userSite' => $this->userSite,
                'settingsUrl' => $this->userSite . '/settings',
                'email' => $email ?? 'you',
            ], 'emails.text.pledge.thank-you')
            ->applyLogging('PledgeThankYou');
    }

    protected function getSubject(): string
    {
        return 'Thank you for taking the Freegle Pledge!';
    }
}
