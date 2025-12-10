<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;
use Spatie\Mjml\Mjml;

abstract class MjmlMailable extends Mailable
{
    use Queueable, SerializesModels;

    protected string $mjmlTemplate;
    protected array $mjmlData = [];

    /**
     * Build the message.
     *
     * This method is called when the email is sent. It renders the MJML
     * template, compiles it to HTML, and sets it as the email content.
     */
    public function build(): static
    {
        // Render the Blade template to get MJML content.
        $mjmlContent = view($this->mjmlTemplate, $this->mjmlData)->render();

        // Compile MJML to HTML.
        $html = $this->compileMjml($mjmlContent);

        // Set the HTML content.
        return $this->html($html);
    }

    /**
     * Compile MJML to HTML.
     */
    protected function compileMjml(string $mjml): string
    {
        try {
            $result = Mjml::new()->toHtml($mjml);
            return $result;
        } catch (\Exception $e) {
            // Fallback to raw MJML if compilation fails.
            \Log::error('MJML compilation failed: ' . $e->getMessage());
            return $mjml;
        }
    }

    /**
     * Set the MJML template.
     */
    protected function mjmlView(string $template, array $data = []): static
    {
        $this->mjmlTemplate = $template;
        $this->mjmlData = array_merge($this->getDefaultData(), $data);
        return $this;
    }

    /**
     * Get default data for all MJML templates.
     */
    protected function getDefaultData(): array
    {
        return [
            'siteName' => config('freegle.branding.name', 'Freegle'),
            'logoUrl' => config('freegle.branding.logo_url'),
            'userSite' => config('freegle.sites.user'),
            'modSite' => config('freegle.sites.mod'),
            'supportEmail' => config('freegle.mail.support_addr'),
            'currentYear' => date('Y'),
        ];
    }

    /**
     * Get the message envelope.
     */
    public function envelope(): Envelope
    {
        return new Envelope(
            subject: $this->getSubject(),
        );
    }

    /**
     * Get the subject line.
     */
    abstract protected function getSubject(): string;

    /**
     * Get the attachments for the message.
     */
    public function attachments(): array
    {
        return [];
    }
}
