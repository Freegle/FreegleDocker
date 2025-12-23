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
     * Set the MJML template and render it.
     *
     * @param string $template MJML template path (e.g., 'emails.mjml.welcome.welcome')
     * @param array $data Data to pass to templates
     * @param string|null $textTemplate Optional plain text template path
     */
    protected function mjmlView(string $template, array $data = [], ?string $textTemplate = NULL): static
    {
        $this->mjmlTemplate = $template;
        $this->mjmlData = array_merge($this->getDefaultData(), $data);

        // Render the Blade template to get MJML content.
        $mjmlContent = view($this->mjmlTemplate, $this->mjmlData)->render();

        // Compile MJML to HTML.
        $html = $this->compileMjml($mjmlContent);

        // Set the HTML content.
        $this->html($html);

        // Set plain text version if template provided.
        if ($textTemplate && view()->exists($textTemplate)) {
            $this->text($textTemplate, $this->mjmlData);
        }

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

    /**
     * Generate responsive image data for email templates.
     *
     * Uses the image delivery service (weserv/images) to create
     * multiple sizes for responsive srcset.
     *
     * @param string $sourceUrl The source image URL
     * @param array $widths Array of widths to generate (default: [80, 160, 240])
     * @param int $defaultWidth The default width for src attribute
     * @return array{src: string, srcset: string}
     */
    protected function responsiveImage(string $sourceUrl, array $widths = [80, 160, 240], int $defaultWidth = 80): array
    {
        $deliveryBase = config('freegle.delivery.base_url', 'https://delivery.ilovefreegle.org');

        // Generate srcset entries.
        // Use &amp; for MJML/XML compatibility - will be decoded to & in final HTML.
        $srcsetParts = [];
        foreach ($widths as $width) {
            $resizedUrl = "{$deliveryBase}/?url=" . urlencode($sourceUrl) . "&amp;w={$width}";
            $srcsetParts[] = "{$resizedUrl} {$width}w";
        }

        // Generate default src.
        $src = "{$deliveryBase}/?url=" . urlencode($sourceUrl) . "&amp;w={$defaultWidth}";

        return [
            'src' => $src,
            'srcset' => implode(', ', $srcsetParts),
        ];
    }
}
