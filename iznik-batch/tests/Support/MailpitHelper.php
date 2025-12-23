<?php

namespace Tests\Support;

use Illuminate\Support\Facades\Http;

/**
 * Helper class for interacting with Mailpit API during testing.
 *
 * Mailpit is a drop-in replacement for MailHog with additional features
 * including built-in spam scoring via SpamAssassin integration.
 *
 * API Documentation: https://mailpit.axllent.org/docs/api-v1/
 */
class MailpitHelper
{
    protected string $baseUrl;

    public function __construct(?string $baseUrl = null)
    {
        // Support both MAILPIT and legacy MAILHOG env vars for backwards compatibility.
        $this->baseUrl = $baseUrl
            ?? env('MAILPIT_API_URL')
            ?? env('MAILHOG_API_URL', 'http://mailpit:8025');
    }

    /**
     * Get all messages from Mailpit.
     *
     * @return array
     */
    public function getMessages(): array
    {
        $response = Http::get("{$this->baseUrl}/api/v1/messages");

        if ($response->failed()) {
            return [];
        }

        return $response->json('messages', []);
    }

    /**
     * Get the most recent message.
     *
     * @return array|null
     */
    public function getLatestMessage(): ?array
    {
        $messages = $this->getMessages();
        return $messages[0] ?? null;
    }

    /**
     * Get a specific message by ID.
     *
     * @param string $messageId
     * @return array|null
     */
    public function getMessage(string $messageId): ?array
    {
        $response = Http::get("{$this->baseUrl}/api/v1/message/{$messageId}");

        if ($response->failed()) {
            return null;
        }

        $message = $response->json();

        // Fetch headers from separate endpoint and merge.
        $headersResponse = Http::get("{$this->baseUrl}/api/v1/message/{$messageId}/headers");
        if ($headersResponse->successful()) {
            $message['Headers'] = $headersResponse->json();
        }

        return $message;
    }

    /**
     * Search for messages by query.
     *
     * @param string $query Search query
     * @return array
     */
    public function search(string $query): array
    {
        $response = Http::get("{$this->baseUrl}/api/v1/search", [
            'query' => $query,
        ]);

        if ($response->failed()) {
            return [];
        }

        return $response->json('messages', []);
    }

    /**
     * Search for messages by recipient email.
     *
     * @param string $email
     * @return array
     */
    public function searchByRecipient(string $email): array
    {
        return $this->search("to:{$email}");
    }

    /**
     * Search for messages by sender email.
     *
     * @param string $email
     * @return array
     */
    public function searchBySender(string $email): array
    {
        return $this->search("from:{$email}");
    }

    /**
     * Delete all messages from Mailpit.
     *
     * @return bool
     */
    public function deleteAllMessages(): bool
    {
        $response = Http::delete("{$this->baseUrl}/api/v1/messages");
        return $response->successful();
    }

    /**
     * Delete a specific message by ID.
     *
     * @param string $messageId
     * @return bool
     */
    public function deleteMessage(string $messageId): bool
    {
        $response = Http::delete("{$this->baseUrl}/api/v1/messages", [
            'ids' => [$messageId],
        ]);
        return $response->successful();
    }

    /**
     * Get the total count of messages.
     *
     * @return int
     */
    public function getMessageCount(): int
    {
        $response = Http::get("{$this->baseUrl}/api/v1/messages");

        if ($response->failed()) {
            return 0;
        }

        return $response->json('messages_count', 0);
    }

    /**
     * Extract the subject from a message.
     *
     * @param array $message
     * @return string|null
     */
    public function getSubject(array $message): ?string
    {
        return $message['Subject'] ?? null;
    }

    /**
     * Extract the from address from a message.
     *
     * @param array $message
     * @return string|null
     */
    public function getFrom(array $message): ?string
    {
        $from = $message['From'] ?? null;
        if (is_array($from)) {
            return $from['Address'] ?? null;
        }
        return $from;
    }

    /**
     * Extract the to addresses from a message.
     *
     * @param array $message
     * @return array
     */
    public function getTo(array $message): array
    {
        $to = $message['To'] ?? [];
        if (!is_array($to)) {
            return [];
        }

        return array_map(function($recipient) {
            if (is_array($recipient)) {
                return $recipient['Address'] ?? '';
            }
            return $recipient;
        }, $to);
    }

    /**
     * Get a header value from a message.
     *
     * @param array $message Full message (fetched with getMessage())
     * @param string $headerName
     * @return string|null
     */
    public function getHeader(array $message, string $headerName): ?string
    {
        if (!isset($message['Headers']) || !is_array($message['Headers'])) {
            return null;
        }

        // Mailpit uses Title-Case for headers, so do case-insensitive lookup.
        $lowerHeaderName = strtolower($headerName);
        foreach ($message['Headers'] as $key => $value) {
            if (strtolower($key) === $lowerHeaderName) {
                return is_array($value) ? $value[0] : $value;
            }
        }

        return null;
    }

    /**
     * Get SpamAssassin score from email headers.
     *
     * @param array $message Full message (fetched with getMessage())
     * @return float|null
     */
    public function getSpamScoreSA(array $message): ?float
    {
        $score = $this->getHeader($message, 'X-Spam-Score-SA');
        if ($score === null || str_starts_with($score, 'Error')) {
            return null;
        }
        return (float) $score;
    }

    /**
     * Get Rspamd score from email headers.
     *
     * @param array $message Full message (fetched with getMessage())
     * @return float|null
     */
    public function getSpamScoreRspamd(array $message): ?float
    {
        $score = $this->getHeader($message, 'X-Spam-Score-Rspamd');
        if ($score === null || str_starts_with($score, 'Error')) {
            return null;
        }
        return (float) $score;
    }

    /**
     * Get SpamAssassin symbols/rules that matched.
     *
     * @param array $message Full message (fetched with getMessage())
     * @return array
     */
    public function getSpamSymbolsSA(array $message): array
    {
        $symbols = $this->getHeader($message, 'X-Spam-Symbols-SA');
        if ($symbols === null || $symbols === 'Error') {
            return [];
        }
        return array_filter(explode(',', $symbols));
    }

    /**
     * Get Rspamd symbols/rules that matched.
     *
     * @param array $message Full message (fetched with getMessage())
     * @return array
     */
    public function getSpamSymbolsRspamd(array $message): array
    {
        $symbols = $this->getHeader($message, 'X-Spam-Symbols-Rspamd');
        if ($symbols === null || $symbols === 'Error') {
            return [];
        }
        return array_filter(explode(',', $symbols));
    }

    /**
     * Get Mailpit's built-in spam score (via SpamAssassin integration).
     *
     * @param string $messageId
     * @return float|null
     */
    public function getMailpitSpamScore(string $messageId): ?float
    {
        $response = Http::get("{$this->baseUrl}/api/v1/message/{$messageId}/spam");

        if ($response->failed()) {
            return null;
        }

        return $response->json('Score');
    }

    /**
     * Extract the body content from a message.
     *
     * @param array $message
     * @return string|null
     */
    public function getBody(array $message): ?string
    {
        // For full messages fetched with getMessage()
        if (isset($message['Text'])) {
            return $message['Text'];
        }
        if (isset($message['HTML'])) {
            return $message['HTML'];
        }

        return null;
    }

    /**
     * Check if a message contains specific text in the body.
     *
     * @param array $message
     * @param string $text
     * @return bool
     */
    public function bodyContains(array $message, string $text): bool
    {
        $body = $this->getBody($message);
        return $body !== null && str_contains($body, $text);
    }

    /**
     * Wait for a message matching criteria (polling).
     *
     * @param callable $criteria Function that receives message array and returns bool
     * @param int $maxAttempts Maximum number of polling attempts
     * @param int $sleepMs Milliseconds to sleep between attempts
     * @return array|null
     */
    public function waitForMessage(callable $criteria, int $maxAttempts = 10, int $sleepMs = 500): ?array
    {
        for ($i = 0; $i < $maxAttempts; $i++) {
            $messages = $this->getMessages();

            foreach ($messages as $message) {
                if ($criteria($message)) {
                    return $message;
                }
            }

            usleep($sleepMs * 1000);
        }

        return null;
    }

    /**
     * Assert that a message was sent to a specific recipient.
     *
     * Returns the full message with body and headers.
     *
     * @param string $email
     * @param int $maxAttempts
     * @return array
     * @throws \RuntimeException
     */
    public function assertMessageSentTo(string $email, int $maxAttempts = 10): array
    {
        $summaryMessage = $this->waitForMessage(function ($msg) use ($email) {
            $recipients = $this->getTo($msg);
            foreach ($recipients as $recipient) {
                if (str_contains($recipient, $email)) {
                    return true;
                }
            }
            return false;
        }, $maxAttempts);

        if ($summaryMessage === null) {
            throw new \RuntimeException("No message found sent to: {$email}");
        }

        // Get full message with body and headers.
        $messageId = $summaryMessage['ID'] ?? null;
        if ($messageId) {
            return $this->getMessage($messageId) ?? $summaryMessage;
        }

        return $summaryMessage;
    }

    /**
     * Assert that a message with specific subject was sent.
     *
     * @param string $subject
     * @param int $maxAttempts
     * @return array
     * @throws \RuntimeException
     */
    public function assertMessageWithSubject(string $subject, int $maxAttempts = 10): array
    {
        $message = $this->waitForMessage(function ($msg) use ($subject) {
            return $this->getSubject($msg) === $subject;
        }, $maxAttempts);

        if ($message === null) {
            throw new \RuntimeException("No message found with subject: {$subject}");
        }

        return $message;
    }

    /**
     * Assert that a message has a low spam score.
     *
     * @param array $message Full message (fetched with getMessage())
     * @param float $threshold Maximum acceptable spam score
     * @throws \RuntimeException
     */
    public function assertNotSpam(array $message, float $threshold = 5.0): void
    {
        $saScore = $this->getSpamScoreSA($message);
        $rspamdScore = $this->getSpamScoreRspamd($message);

        $errors = [];

        if ($saScore !== null && $saScore >= $threshold) {
            $symbols = $this->getSpamSymbolsSA($message);
            $errors[] = sprintf(
                'SpamAssassin score %.1f exceeds threshold %.1f. Symbols: %s',
                $saScore,
                $threshold,
                implode(', ', $symbols)
            );
        }

        if ($rspamdScore !== null && $rspamdScore >= $threshold) {
            $symbols = $this->getSpamSymbolsRspamd($message);
            $errors[] = sprintf(
                'Rspamd score %.1f exceeds threshold %.1f. Symbols: %s',
                $rspamdScore,
                $threshold,
                implode(', ', $symbols)
            );
        }

        if (!empty($errors)) {
            throw new \RuntimeException(implode("\n", $errors));
        }
    }

    /**
     * Get a spam score report for a message.
     *
     * @param array $message Full message (fetched with getMessage())
     * @return array
     */
    public function getSpamReport(array $message): array
    {
        return [
            'spamassassin' => [
                'score' => $this->getSpamScoreSA($message),
                'symbols' => $this->getSpamSymbolsSA($message),
                'status' => $this->getHeader($message, 'X-Spam-Status-SA'),
            ],
            'rspamd' => [
                'score' => $this->getSpamScoreRspamd($message),
                'symbols' => $this->getSpamSymbolsRspamd($message),
                'status' => $this->getHeader($message, 'X-Spam-Status-Rspamd'),
            ],
        ];
    }

    /**
     * Gmail clips emails larger than ~102KB.
     */
    public const GMAIL_CLIP_THRESHOLD = 102000;

    /**
     * Get the email size in bytes.
     *
     * @param array $message Message from getMessages() or getMessage()
     * @return int
     */
    public function getSize(array $message): int
    {
        return $message['Size'] ?? 0;
    }

    /**
     * Check if email would be clipped by Gmail (over 102KB).
     *
     * @param array $message
     * @return bool
     */
    public function wouldBeClippedByGmail(array $message): bool
    {
        return $this->getSize($message) > self::GMAIL_CLIP_THRESHOLD;
    }

    /**
     * Get Gmail clip percentage (how close to the 102KB limit).
     *
     * @param array $message
     * @return float Percentage (0-100+)
     */
    public function getGmailClipPercentage(array $message): float
    {
        $size = $this->getSize($message);
        return ($size / self::GMAIL_CLIP_THRESHOLD) * 100;
    }

    /**
     * Get HTML compatibility check results from Mailpit.
     *
     * Returns warnings about CSS/HTML features with limited email client support.
     *
     * @param string $messageId
     * @return array
     */
    public function getHtmlCheck(string $messageId): array
    {
        $response = Http::get("{$this->baseUrl}/api/v1/message/{$messageId}/html-check");

        if ($response->failed()) {
            return [];
        }

        return $response->json('Warnings', []);
    }

    /**
     * Get a summary of HTML compatibility issues.
     *
     * @param string $messageId
     * @return array{total: int, critical: array}
     */
    public function getHtmlCheckSummary(string $messageId): array
    {
        $warnings = $this->getHtmlCheck($messageId);

        $critical = [];
        foreach ($warnings as $warning) {
            $results = $warning['Results'] ?? [];
            $noSupport = 0;
            foreach ($results as $result) {
                if (($result['Support'] ?? '') === 'no') {
                    $noSupport++;
                }
            }
            // Flag as critical if more than 30 clients don't support it.
            if ($noSupport > 30) {
                $critical[] = [
                    'feature' => $warning['Title'] ?? 'Unknown',
                    'category' => $warning['Category'] ?? 'unknown',
                    'unsupported_clients' => $noSupport,
                ];
            }
        }

        return [
            'total' => count($warnings),
            'critical' => $critical,
        ];
    }

    /**
     * Get a comprehensive email quality report.
     *
     * @param array $message Full message (fetched with getMessage())
     * @return array
     */
    public function getQualityReport(array $message): array
    {
        $messageId = $message['ID'] ?? null;

        return [
            'spam' => $this->getSpamReport($message),
            'size' => [
                'bytes' => $this->getSize($message),
                'kb' => round($this->getSize($message) / 1024, 1),
                'gmail_clip_percent' => round($this->getGmailClipPercentage($message), 1),
                'would_clip' => $this->wouldBeClippedByGmail($message),
            ],
            'html' => $messageId ? $this->getHtmlCheckSummary($messageId) : null,
        ];
    }
}
