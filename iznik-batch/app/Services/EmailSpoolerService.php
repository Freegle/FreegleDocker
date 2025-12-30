<?php

namespace App\Services;

use Illuminate\Mail\Mailable;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Symfony\Component\Mime\Email;
use Symfony\Component\Mime\Part\Multipart\AlternativePart;
use Symfony\Component\Mime\Part\TextPart;

/**
 * File-based email spooler service.
 *
 * Writes emails to a spool directory for asynchronous processing.
 * This provides resilience and backlog monitoring capabilities.
 */
class EmailSpoolerService
{
    protected string $spoolDir;
    protected string $pendingDir;
    protected string $sendingDir;
    protected string $failedDir;
    protected string $sentDir;

    public function __construct()
    {
        $this->spoolDir = storage_path('spool/mail');
        $this->pendingDir = $this->spoolDir . '/pending';
        $this->sendingDir = $this->spoolDir . '/sending';
        $this->failedDir = $this->spoolDir . '/failed';
        $this->sentDir = $this->spoolDir . '/sent';

        $this->ensureDirectoriesExist();
    }

    /**
     * Spool an email for later sending.
     */
    public function spool(Mailable $mailable, string|array $to, ?string $emailType = null): string
    {
        $id = $this->generateId();
        $filename = $id . '.json';

        // Render the email content now.
        $rendered = $mailable->render();

        // Get subject from envelope.
        $envelope = $mailable->envelope();

        // Extract BCC addresses from the mailable.
        $bcc = [];
        if (property_exists($mailable, 'bcc') && !empty($mailable->bcc)) {
            foreach ($mailable->bcc as $bccEntry) {
                if (is_array($bccEntry) && isset($bccEntry['address'])) {
                    $bcc[] = $bccEntry['address'];
                } elseif (is_string($bccEntry)) {
                    $bcc[] = $bccEntry;
                }
            }
        }

        // Extract AMP HTML if the mailable uses the AmpEmail trait.
        $ampHtml = null;
        if (property_exists($mailable, 'ampHtml') && !empty($mailable->ampHtml)) {
            $ampHtml = $mailable->ampHtml;
        }

        // Generate plain text version from HTML.
        $textContent = $this->htmlToPlainText($rendered);

        $data = [
            'id' => $id,
            'to' => is_array($to) ? $to : [$to],
            'bcc' => $bcc,
            'subject' => $envelope->subject,
            'html' => $rendered,
            'amp_html' => $ampHtml,
            'text' => $textContent,
            'from' => [
                'address' => $envelope->from?->address ?? config('mail.from.address'),
                'name' => $envelope->from?->name ?? config('mail.from.name'),
            ],
            'email_type' => $emailType,
            'mailable_class' => get_class($mailable),
            'created_at' => now()->toIso8601String(),
            'attempts' => 0,
            'last_attempt' => null,
            'last_error' => null,
        ];

        $path = $this->pendingDir . '/' . $filename;
        file_put_contents($path, json_encode($data, JSON_PRETTY_PRINT));

        Log::info('Email spooled', [
            'id' => $id,
            'to' => $data['to'],
            'subject' => $data['subject'],
            'type' => $emailType,
            'has_amp' => !empty($ampHtml),
        ]);

        return $id;
    }

    /**
     * Process pending emails from the spool.
     *
     * Retries indefinitely until the email is sent. Logs an alert if any
     * email has been stuck for 5+ minutes, but only alerts once per email
     * to avoid log flooding.
     *
     * @param int $limit Maximum emails to process.
     * @return array Stats about processed emails.
     */
    public function processSpool(int $limit = 100): array
    {
        $stats = [
            'processed' => 0,
            'sent' => 0,
            'retried' => 0,
            'stuck_alerts' => 0,
        ];

        $files = glob($this->pendingDir . '/*.json');
        $files = array_slice($files, 0, $limit);

        foreach ($files as $pendingPath) {
            $filename = basename($pendingPath);
            $sendingPath = $this->sendingDir . '/' . $filename;

            // Move to sending directory.
            if (!rename($pendingPath, $sendingPath)) {
                Log::warning('Could not move spool file to sending', ['file' => $filename]);
                continue;
            }

            $stats['processed']++;

            $data = json_decode(file_get_contents($sendingPath), true);
            if (!$data) {
                Log::error('Invalid spool file', ['file' => $filename]);
                // Move invalid files to failed - these can't be retried.
                rename($sendingPath, $this->failedDir . '/' . $filename);
                continue;
            }

            $data['attempts']++;
            $data['last_attempt'] = now()->toIso8601String();

            try {
                // Send the email with appropriate MIME structure.
                if (!empty($data['amp_html'])) {
                    // Send with AMP: multipart/alternative with text, AMP, and HTML.
                    $this->sendWithAmp($data);
                } else {
                    // Standard HTML email.
                    Mail::html($data['html'], function ($message) use ($data) {
                        $message->to($data['to'])
                            ->subject($data['subject'])
                            ->from($data['from']['address'], $data['from']['name']);

                        // Apply BCC if present.
                        if (!empty($data['bcc'])) {
                            $message->bcc($data['bcc']);
                        }
                    });
                }

                // Move to sent directory.
                rename($sendingPath, $this->sentDir . '/' . $filename);
                $stats['sent']++;

                Log::info('Spooled email sent', [
                    'id' => $data['id'],
                    'to' => $data['to'],
                    'attempts' => $data['attempts'],
                ]);
            } catch (\Exception $e) {
                $data['last_error'] = $e->getMessage();

                // Check if email has been stuck for 5+ minutes.
                $createdAt = \Carbon\Carbon::parse($data['created_at']);
                $ageMinutes = now()->diffInMinutes($createdAt);
                $lastAlertedAt = isset($data['last_alerted_at'])
                    ? \Carbon\Carbon::parse($data['last_alerted_at'])
                    : null;

                // Alert if stuck for 5+ mins and haven't alerted in the last 5 mins.
                if ($ageMinutes >= 5) {
                    $shouldAlert = $lastAlertedAt === null
                        || now()->diffInMinutes($lastAlertedAt) >= 5;

                    if ($shouldAlert) {
                        $data['last_alerted_at'] = now()->toIso8601String();
                        $stats['stuck_alerts']++;

                        Log::error('Email stuck in spool for 5+ minutes - SMTP delivery issue', [
                            'id' => $data['id'],
                            'to' => $data['to'],
                            'age_minutes' => $ageMinutes,
                            'attempts' => $data['attempts'],
                            'error' => $e->getMessage(),
                        ]);
                    }
                }

                // Always move back to pending for retry (indefinite retries).
                file_put_contents($sendingPath, json_encode($data, JSON_PRETTY_PRINT));
                rename($sendingPath, $pendingPath);
                $stats['retried']++;
            }
        }

        return $stats;
    }

    /**
     * Get backlog statistics.
     */
    public function getBacklogStats(): array
    {
        $pendingFiles = glob($this->pendingDir . '/*.json');
        $sendingFiles = glob($this->sendingDir . '/*.json');
        $failedFiles = glob($this->failedDir . '/*.json');

        $oldestPending = null;
        $oldestAge = null;

        foreach ($pendingFiles as $file) {
            $data = json_decode(file_get_contents($file), true);
            if ($data && isset($data['created_at'])) {
                $created = \Carbon\Carbon::parse($data['created_at']);
                if ($oldestPending === null || $created < $oldestPending) {
                    $oldestPending = $created;
                }
            }
        }

        if ($oldestPending) {
            $oldestAge = now()->diffInMinutes($oldestPending);
        }

        return [
            'pending_count' => count($pendingFiles),
            'sending_count' => count($sendingFiles),
            'failed_count' => count($failedFiles),
            'oldest_pending_at' => $oldestPending?->toIso8601String(),
            'oldest_pending_age_minutes' => $oldestAge,
            'status' => $this->getHealthStatus(count($pendingFiles), $oldestAge),
        ];
    }

    /**
     * Clean up old sent emails.
     *
     * @param int $daysToKeep Number of days to keep sent emails.
     * @return int Number of files deleted.
     */
    public function cleanupSent(int $daysToKeep = 7): int
    {
        $deleted = 0;
        $cutoff = now()->subDays($daysToKeep);

        foreach (glob($this->sentDir . '/*.json') as $file) {
            if (filemtime($file) < $cutoff->timestamp) {
                unlink($file);
                $deleted++;
            }
        }

        return $deleted;
    }

    /**
     * Retry a failed email.
     */
    public function retryFailed(string $id): bool
    {
        $filename = $id . '.json';
        $failedPath = $this->failedDir . '/' . $filename;

        if (!file_exists($failedPath)) {
            return false;
        }

        $data = json_decode(file_get_contents($failedPath), true);
        if ($data) {
            $data['attempts'] = 0;
            $data['last_error'] = null;
            file_put_contents($failedPath, json_encode($data, JSON_PRETTY_PRINT));
        }

        return rename($failedPath, $this->pendingDir . '/' . $filename);
    }

    /**
     * Retry all failed emails.
     */
    public function retryAllFailed(): int
    {
        $count = 0;
        foreach (glob($this->failedDir . '/*.json') as $file) {
            $data = json_decode(file_get_contents($file), true);
            if ($data) {
                $data['attempts'] = 0;
                $data['last_error'] = null;
                file_put_contents($file, json_encode($data, JSON_PRETTY_PRINT));
                rename($file, $this->pendingDir . '/' . basename($file));
                $count++;
            }
        }
        return $count;
    }

    protected function generateId(): string
    {
        return uniqid('mail_', true) . '_' . bin2hex(random_bytes(4));
    }

    protected function ensureDirectoriesExist(): void
    {
        foreach ([$this->pendingDir, $this->sendingDir, $this->failedDir, $this->sentDir] as $dir) {
            if (!is_dir($dir)) {
                mkdir($dir, 0755, true);
            }
        }
    }

    protected function getHealthStatus(int $pendingCount, ?int $oldestAgeMinutes): string
    {
        if ($pendingCount === 0) {
            return 'healthy';
        }

        if ($pendingCount > 1000 || ($oldestAgeMinutes !== null && $oldestAgeMinutes > 60)) {
            return 'critical';
        }

        if ($pendingCount > 100 || ($oldestAgeMinutes !== null && $oldestAgeMinutes > 15)) {
            return 'warning';
        }

        return 'healthy';
    }

    /**
     * Convert HTML to plain text for email fallback.
     */
    protected function htmlToPlainText(string $html): string
    {
        // Remove style and script tags and their content.
        $text = preg_replace('/<style[^>]*>.*?<\/style>/is', '', $html);
        $text = preg_replace('/<script[^>]*>.*?<\/script>/is', '', $text);

        // Convert <br> and </p> to newlines.
        $text = preg_replace('/<br\s*\/?>/i', "\n", $text);
        $text = preg_replace('/<\/p>/i', "\n\n", $text);
        $text = preg_replace('/<\/div>/i', "\n", $text);
        $text = preg_replace('/<\/tr>/i', "\n", $text);
        $text = preg_replace('/<\/li>/i', "\n", $text);

        // Convert links to text with URL.
        $text = preg_replace('/<a[^>]+href=["\']([^"\']+)["\'][^>]*>([^<]+)<\/a>/i', '$2 ($1)', $text);

        // Remove remaining HTML tags.
        $text = strip_tags($text);

        // Decode HTML entities.
        $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');

        // Normalize whitespace.
        $text = preg_replace('/[ \t]+/', ' ', $text);
        $text = preg_replace('/\n{3,}/', "\n\n", $text);
        $text = trim($text);

        return $text;
    }

    /**
     * Send email with AMP content (multipart/alternative: text, AMP, HTML).
     */
    protected function sendWithAmp(array $data): void
    {
        // Create text part.
        $textPart = new TextPart($data['text'] ?? $this->htmlToPlainText($data['html']), 'utf-8', 'plain');

        // Create AMP part with correct MIME type.
        $ampPart = new TextPart($data['amp_html'], 'utf-8', 'x-amp-html');

        // Create HTML part.
        $htmlPart = new TextPart($data['html'], 'utf-8', 'html');

        // Create multipart/alternative with all versions.
        // Order: text, AMP, HTML (AMP must come before HTML for proper fallback).
        $alternativePart = new AlternativePart($textPart, $ampPart, $htmlPart);

        // Build the email using raw Symfony Mailer.
        $email = (new Email())
            ->from(new \Symfony\Component\Mime\Address($data['from']['address'], $data['from']['name']))
            ->subject($data['subject'])
            ->setBody($alternativePart);

        // Add recipients.
        foreach ($data['to'] as $recipient) {
            $email->addTo($recipient);
        }

        // Add BCC if present.
        if (!empty($data['bcc'])) {
            foreach ($data['bcc'] as $bccRecipient) {
                $email->addBcc($bccRecipient);
            }
        }

        // Send via the transport.
        $transport = app('mail.manager')->getSymfonyTransport();
        $transport->send($email, \Symfony\Component\Mailer\Envelope::create($email));
    }
}
