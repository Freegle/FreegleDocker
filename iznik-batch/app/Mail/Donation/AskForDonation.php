<?php

namespace App\Mail\Donation;

use App\Mail\MjmlMailable;
use App\Mail\Traits\LoggableEmail;
use App\Models\User;

class AskForDonation extends MjmlMailable
{
    use LoggableEmail;

    public User $user;

    public ?string $itemSubject;

    public string $userSite;

    public float $target;

    public string $donateUrl;

    /**
     * Create a new message instance.
     */
    public function __construct(User $user, ?string $itemSubject = null)
    {
        $this->user = $user;
        $this->itemSubject = $itemSubject;
        $this->userSite = config('freegle.sites.user');
        $this->target = config('freegle.donation.target', 2500);
        $this->donateUrl = config('freegle.donation.url', 'http://freegle.in/paypal1510');
    }

    /**
     * Build the message.
     */
    public function build(): static
    {
        return $this->to($this->user->email_preferred, $this->user->displayname)
            ->subject($this->getSubject())
            ->mjmlView('emails.mjml.donation.ask', [
                'user' => $this->user,
                'userSite' => $this->userSite,
                'itemSubject' => $this->itemSubject,
                'target' => $this->target,
                'donateUrl' => $this->donateUrl,
            ])
            ->applyLogging('AskForDonation');
    }

    /**
     * Get the subject line.
     */
    protected function getSubject(): string
    {
        return $this->itemSubject
            ? "Regarding: {$this->itemSubject}"
            : "Thanks for freegling!";
    }
}
