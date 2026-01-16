<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Str;
use Spatie\Mjml\Mjml;
use Symfony\Component\Mime\Email;

abstract class MjmlMailable extends Mailable
{
    use Queueable, SerializesModels;

    protected string $mjmlTemplate;
    protected array $mjmlData = [];

    /**
     * Trace ID for log correlation. Initialized early with default value.
     */
    protected string $traceId = '';

    /**
     * Timestamp when email was created.
     */
    protected string $timestamp = '';

    public function __construct()
    {
        // Initialize tracking fields immediately to avoid "accessed before initialization" errors.
        $this->traceId = 'freegle-' . time() . '_' . Str::random(8);
        $this->timestamp = now()->toIso8601String();
    }

    /**
     * Build the message.
     *
     * This method is called when the email is sent. It renders the MJML
     * template, compiles it to HTML, and sets it as the email content.
     */
    public function build(): static
    {
        // Render the Blade template to get MJML content.
        $mjmlContent = $this->renderMjmlTemplate();

        // Compile MJML to HTML.
        $html = $this->compileMjml($mjmlContent);

        // Set the HTML content.
        return $this->html($html);
    }

    /**
     * Render the MJML template.
     *
     * Views should be pre-compiled before tests run via `php artisan view:cache`.
     * If a view renders empty, that's a real problem that needs investigation -
     * not something to retry or patch over by deleting compiled views.
     */
    protected function renderMjmlTemplate(): string
    {
        return view($this->mjmlTemplate, $this->mjmlData)->render();
    }

    /**
     * Compile MJML to HTML.
     *
     * @throws \RuntimeException If MJML compilation fails or produces empty output
     */
    protected function compileMjml(string $mjml): string
    {
        // Check for empty input - this indicates a template rendering failure.
        if (empty(trim($mjml))) {
            // Get the actual file path for additional diagnostics.
            $templatePath = $this->mjmlTemplate ?? 'unknown';
            $filePath = null;
            $fileSize = null;

            if ($templatePath !== 'unknown') {
                // Convert dot notation to file path.
                $relativePath = str_replace('.', '/', $templatePath) . '.blade.php';
                $filePath = resource_path('views/' . $relativePath);
                $fileSize = file_exists($filePath) ? filesize($filePath) : 'file not found';
            }

            $error = 'MJML template rendered to empty string - template may be missing or have syntax errors';
            \Log::error($error, [
                'mailable' => static::class,
                'template' => $templatePath,
                'file_path' => $filePath,
                'file_size' => $fileSize,
                'view_exists' => view()->exists($templatePath),
            ]);
            throw new \RuntimeException($error . " (template: {$templatePath}, file_size: {$fileSize})");
        }

        try {
            $result = Mjml::new()->toHtml($mjml);

            // Check for empty output - MJML compilation failed silently.
            if (empty(trim($result))) {
                $error = 'MJML compilation produced empty HTML output';
                \Log::error($error, [
                    'mailable' => static::class,
                    'template' => $this->mjmlTemplate ?? 'unknown',
                    'mjml_length' => strlen($mjml),
                ]);
                throw new \RuntimeException($error);
            }

            return $result;
        } catch (\RuntimeException $e) {
            // Re-throw our own exceptions.
            throw $e;
        } catch (\Exception $e) {
            // Log and throw on MJML compilation failure - never send broken emails.
            $error = 'MJML compilation failed: ' . $e->getMessage();
            \Log::error($error, [
                'mailable' => static::class,
                'template' => $this->mjmlTemplate ?? 'unknown',
                'exception' => $e->getMessage(),
            ]);
            throw new \RuntimeException($error, 0, $e);
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

        // Check that the view exists before rendering.
        if (!view()->exists($this->mjmlTemplate)) {
            $error = "MJML template not found: {$this->mjmlTemplate}";
            \Log::error($error, [
                'mailable' => static::class,
                'template' => $this->mjmlTemplate,
                'view_paths' => config('view.paths'),
            ]);
            throw new \RuntimeException($error);
        }

        // Render the Blade template to get MJML content with retry on empty.
        try {
            $mjmlContent = $this->renderMjmlTemplate();
        } catch (\Throwable $e) {
            $error = "MJML template rendering failed: {$e->getMessage()}";
            \Log::error($error, [
                'mailable' => static::class,
                'template' => $this->mjmlTemplate,
                'exception' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            throw new \RuntimeException($error, 0, $e);
        }

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

    /**
     * Add common tracking headers to the email.
     *
     * Called via withSymfonyMessage() callback when the email is built.
     * traceId and timestamp are initialized in the constructor to ensure
     * they're available when this callback runs.
     */
    protected function addCommonHeaders(Email $message): void
    {
        $headers = $message->getHeaders();

        // Trace ID for correlating email with Loki logs.
        $headers->addTextHeader('X-Freegle-Trace-Id', $this->traceId);

        // Email type for filtering/categorization.
        $headers->addTextHeader('X-Freegle-Email-Type', class_basename($this));

        // Timestamp when email was created.
        $headers->addTextHeader('X-Freegle-Timestamp', $this->timestamp);

        // User ID if available (subclasses can override getRecipientUserId).
        $userId = $this->getRecipientUserId();
        if ($userId !== null) {
            $headers->addTextHeader('X-Freegle-User-Id', (string) $userId);
        }
    }

    /**
     * Get the recipient's user ID for tracking.
     * Override in subclasses to provide user-specific tracking.
     */
    protected function getRecipientUserId(): ?int
    {
        return null;
    }

    /**
     * Configure the message with common headers.
     *
     * Subclasses should call this in their content() method via withSymfonyMessage().
     */
    protected function configureMessage(): static
    {
        return $this->withSymfonyMessage(function (Email $message) {
            $this->addCommonHeaders($message);
        });
    }
}
