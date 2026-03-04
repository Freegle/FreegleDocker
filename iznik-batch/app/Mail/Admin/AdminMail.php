<?php

namespace App\Mail\Admin;

use App\Mail\MjmlMailable;
use App\Mail\Traits\LoggableEmail;
use App\Mail\Traits\TrackableEmail;
use App\Models\User;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Mail\Mailables\Envelope;

class AdminMail extends MjmlMailable
{
    use LoggableEmail;
    use TrackableEmail;

    public User $user;

    public string $adminSubject;

    public string $adminText;

    public ?string $ctaLink;

    public ?string $ctaText;

    public ?string $groupName;

    public ?string $modsEmail;

    public bool $essential;

    public string $userSite;

    public string $settingsUrl;

    public ?string $marketingOptOutUrl;

    /**
     * Create a new message instance.
     *
     * @param User $user Recipient user
     * @param array $admin Admin record (from admins table)
     * @param string|null $groupName Group name for footer
     * @param string|null $modsEmail Group mods email for reply-to
     */
    public function __construct(User $user, array $admin, ?string $groupName = null, ?string $modsEmail = null)
    {
        parent::__construct();

        $this->user = $user;
        $this->adminSubject = $admin['subject'];
        $this->adminText = $admin['text'];
        $this->ctaLink = $admin['ctalink'] ?? null;
        $this->ctaText = $admin['ctatext'] ?? null;
        $this->groupName = $groupName;
        $this->modsEmail = $modsEmail;
        $this->essential = (bool) ($admin['essential'] ?? true);
        $this->userSite = config('freegle.sites.user');

        // Marketing opt-out shown for non-essential admins.
        $this->marketingOptOutUrl = !$this->essential ? $user->marketingOptOutUrl() : null;

        // Initialize email tracking.
        $this->initTracking(
            'Admin',
            $this->user->email_preferred,
            $this->user->id,
            $admin['groupid'] ?? null,
            $this->adminSubject,
            [
                'admin_id' => $admin['id'] ?? null,
                'parent_id' => $admin['parentid'] ?? null,
                'essential' => $this->essential,
            ]
        );
    }

    /**
     * Get the recipient's user ID for common header tracking.
     */
    protected function getRecipientUserId(): ?int
    {
        return $this->user->id ?? null;
    }

    /**
     * Build the message.
     */
    public function build(): static
    {
        $this->settingsUrl = $this->trackedUrl(
            $this->userSite . '/settings',
            'footer_settings',
            'settings'
        );

        $data = array_merge([
            'user' => $this->user,
            'userSite' => $this->userSite,
            'adminSubject' => $this->adminSubject,
            'adminText' => $this->adminText,
            'ctaLink' => $this->ctaLink ? $this->trackedUrl($this->ctaLink, 'cta_button', 'cta') : null,
            'ctaText' => $this->ctaText,
            'groupName' => $this->groupName,
            'modsEmail' => $this->modsEmail,
            'essential' => $this->essential,
            'settingsUrl' => $this->settingsUrl,
            'marketingOptOutUrl' => $this->marketingOptOutUrl
                ? $this->trackedUrl($this->marketingOptOutUrl, 'marketing_optout', 'optout')
                : null,
        ], $this->getTrackingData());

        $result = $this->to($this->user->email_preferred, $this->user->displayname)
            ->subject($this->getSubject())
            ->mjmlView('emails.mjml.admin.admin', $data, 'emails.text.admin.admin');

        // Add reply-to for group mods.
        if ($this->modsEmail) {
            $result->replyTo($this->modsEmail);
        }

        return $result->applyLogging('Admin');
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
     * Get the subject line - comes directly from the admin record.
     */
    protected function getSubject(): string
    {
        return $this->adminSubject;
    }
}
