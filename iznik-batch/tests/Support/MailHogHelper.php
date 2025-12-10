<?php

namespace Tests\Support;

use Illuminate\Support\Facades\Http;

/**
 * Helper class for interacting with MailHog API during testing.
 *
 * MailHog API documentation: https://github.com/mailhog/MailHog/blob/master/docs/APIv2.md
 */
class MailHogHelper
{
    protected string $baseUrl;

    public function __construct(?string $baseUrl = NULL)
    {
        $this->baseUrl = $baseUrl ?? env('MAILHOG_API_URL', 'http://mailhog:8025');
    }

    /**
     * Get all messages from MailHog.
     *
     * @return array
     */
    public function getMessages(): array
    {
        $response = Http::get("{$this->baseUrl}/api/v2/messages");

        if ($response->failed()) {
            return [];
        }

        return $response->json('items', []);
    }

    /**
     * Get the most recent message.
     *
     * @return array|null
     */
    public function getLatestMessage(): ?array
    {
        $messages = $this->getMessages();
        return $messages[0] ?? NULL;
    }

    /**
     * Search for messages by recipient email.
     *
     * @param string $email
     * @return array
     */
    public function searchByRecipient(string $email): array
    {
        $response = Http::get("{$this->baseUrl}/api/v2/search", [
            'kind' => 'to',
            'query' => $email,
        ]);

        if ($response->failed()) {
            return [];
        }

        return $response->json('items', []);
    }

    /**
     * Search for messages by sender email.
     *
     * @param string $email
     * @return array
     */
    public function searchBySender(string $email): array
    {
        $response = Http::get("{$this->baseUrl}/api/v2/search", [
            'kind' => 'from',
            'query' => $email,
        ]);

        if ($response->failed()) {
            return [];
        }

        return $response->json('items', []);
    }

    /**
     * Search for messages containing specific text.
     *
     * @param string $query
     * @return array
     */
    public function searchByContent(string $query): array
    {
        $response = Http::get("{$this->baseUrl}/api/v2/search", [
            'kind' => 'containing',
            'query' => $query,
        ]);

        if ($response->failed()) {
            return [];
        }

        return $response->json('items', []);
    }

    /**
     * Delete all messages from MailHog.
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
        $response = Http::delete("{$this->baseUrl}/api/v1/messages/{$messageId}");
        return $response->successful();
    }

    /**
     * Get the total count of messages.
     *
     * @return int
     */
    public function getMessageCount(): int
    {
        $response = Http::get("{$this->baseUrl}/api/v2/messages");

        if ($response->failed()) {
            return 0;
        }

        return $response->json('total', 0);
    }

    /**
     * Extract the subject from a message.
     *
     * @param array $message
     * @return string|null
     */
    public function getSubject(array $message): ?string
    {
        return $message['Content']['Headers']['Subject'][0] ?? NULL;
    }

    /**
     * Extract the from address from a message.
     *
     * @param array $message
     * @return string|null
     */
    public function getFrom(array $message): ?string
    {
        return $message['Content']['Headers']['From'][0] ?? NULL;
    }

    /**
     * Extract the to addresses from a message.
     *
     * @param array $message
     * @return array
     */
    public function getTo(array $message): array
    {
        return $message['Content']['Headers']['To'] ?? [];
    }

    /**
     * Extract the body content from a message.
     *
     * @param array $message
     * @return string|null
     */
    public function getBody(array $message): ?string
    {
        return $message['Content']['Body'] ?? NULL;
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
        return $body !== NULL && str_contains($body, $text);
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

        return NULL;
    }

    /**
     * Assert that a message was sent to a specific recipient.
     *
     * @param string $email
     * @param int $maxAttempts
     * @return array
     * @throws \RuntimeException
     */
    public function assertMessageSentTo(string $email, int $maxAttempts = 10): array
    {
        $message = $this->waitForMessage(function ($msg) use ($email) {
            $recipients = $this->getTo($msg);
            foreach ($recipients as $recipient) {
                if (str_contains($recipient, $email)) {
                    return TRUE;
                }
            }
            return FALSE;
        }, $maxAttempts);

        if ($message === NULL) {
            throw new \RuntimeException("No message found sent to: {$email}");
        }

        return $message;
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

        if ($message === NULL) {
            throw new \RuntimeException("No message found with subject: {$subject}");
        }

        return $message;
    }
}
