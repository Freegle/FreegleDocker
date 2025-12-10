<?php

namespace App\Mail\Donation;

use App\Mail\MjmlMailable;
use App\Models\User;

class DonationThankYou extends MjmlMailable
{
    public User $user;

    public string $userSite;

    /**
     * Create a new message instance.
     */
    public function __construct(User $user)
    {
        $this->user = $user;
        $this->userSite = config('freegle.sites.user');
    }

    /**
     * Build the message.
     */
    public function build(): static
    {
        return $this->to($this->user->email_preferred, $this->user->displayname)
            ->subject($this->getSubject())
            ->mjmlView('emails.mjml.donation.thank-you', [
                'user' => $this->user,
                'userSite' => $this->userSite,
            ]);
    }

    /**
     * Get the subject line.
     */
    protected function getSubject(): string
    {
        return 'Thank you for your donation to Freegle!';
    }
}
