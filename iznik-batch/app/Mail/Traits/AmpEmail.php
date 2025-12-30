<?php

namespace App\Mail\Traits;

use App\Models\AmpWriteToken;
use Symfony\Component\Mime\Email;
use Symfony\Component\Mime\Part\Multipart\AlternativePart;
use Symfony\Component\Mime\Part\TextPart;

/**
 * Trait for adding AMP (Accelerated Mobile Pages) support to emails.
 *
 * AMP emails allow dynamic content (refreshable message lists) and
 * inline actions (replying without leaving the email client).
 *
 * Security model:
 * - READ tokens: HMAC-SHA256 based, reusable within expiry period
 * - WRITE tokens: Database nonce, one-time use only
 *
 * This protects against email forwarding attacks.
 */
trait AmpEmail
{
    protected ?string $ampHtml = null;
    protected array $ampData = [];

    /**
     * Check if AMP email is enabled.
     */
    protected function isAmpEnabled(): bool
    {
        return config('freegle.amp.enabled', true) && !empty(config('freegle.amp.secret'));
    }

    /**
     * Generate an HMAC-based read token for amp-list.
     *
     * Read tokens are reusable within their expiry period and allow
     * fetching dynamic content (like new messages).
     *
     * @param int $userId The user ID
     * @param int $resourceId The resource ID (e.g., chat ID)
     * @return array{token: string, expiry: int}
     */
    protected function generateReadToken(int $userId, int $resourceId): array
    {
        $secret = config('freegle.amp.secret');
        $expiryHours = config('freegle.amp.read_token_expiry_hours', 168);
        $expiry = time() + ($expiryHours * 3600);

        // Format: "read" + user_id + resource_id + expiry
        $message = 'read' . $userId . $resourceId . $expiry;
        $token = hash_hmac('sha256', $message, $secret);

        return [
            'token' => $token,
            'expiry' => $expiry,
        ];
    }

    /**
     * Generate a one-time write token for amp-form.
     *
     * Write tokens are stored in the database and can only be used once.
     * This prevents email forwarding attacks from allowing multiple replies.
     *
     * @param int $userId The user ID
     * @param int $chatId The chat ID
     * @param int|null $emailTrackingId Optional email tracking ID for analytics
     * @return string The write token nonce
     */
    protected function generateWriteToken(int $userId, int $chatId, ?int $emailTrackingId = null): string
    {
        $token = AmpWriteToken::createForChat($userId, $chatId, $emailTrackingId);
        return $token->nonce;
    }

    /**
     * Build the AMP API URL for fetching chat messages.
     *
     * @param int $chatId The chat ID
     * @param int $userId The user ID
     * @param int|null $excludeMessageId Message ID to exclude (shown statically)
     * @param int|null $sinceMessageId Messages newer than this are marked as NEW
     * @return string The full API URL with token parameters
     */
    protected function buildAmpChatUrl(
        int $chatId,
        int $userId,
        ?int $excludeMessageId = null,
        ?int $sinceMessageId = null
    ): string {
        $baseUrl = config('freegle.amp.api_url', 'https://api.ilovefreegle.org/amp');
        $readToken = $this->generateReadToken($userId, $chatId);

        $url = sprintf(
            '%s/chat/%d?rt=%s&uid=%d&exp=%d',
            $baseUrl,
            $chatId,
            $readToken['token'],
            $userId,
            $readToken['expiry']
        );

        if ($excludeMessageId) {
            $url .= '&exclude=' . $excludeMessageId;
        }

        if ($sinceMessageId) {
            $url .= '&since=' . $sinceMessageId;
        }

        return $url;
    }

    /**
     * Build the AMP API URL for posting a reply.
     *
     * @param int $chatId The chat ID
     * @param int $userId The user ID
     * @param int|null $emailTrackingId Optional email tracking ID for analytics
     * @return string The full API URL with write token
     */
    protected function buildAmpReplyUrl(int $chatId, int $userId, ?int $emailTrackingId = null): string
    {
        $baseUrl = config('freegle.amp.api_url', 'https://api.ilovefreegle.org/amp');
        $writeToken = $this->generateWriteToken($userId, $chatId, $emailTrackingId);

        return sprintf('%s/chat/%d/reply?wt=%s', $baseUrl, $chatId, $writeToken);
    }

    /**
     * Set the AMP HTML content for the email.
     *
     * @param string $ampHtml The compiled AMP HTML
     */
    protected function setAmpContent(string $ampHtml): void
    {
        $this->ampHtml = $ampHtml;
    }

    /**
     * Render an AMP template and set as AMP content.
     *
     * @param string $template The Blade template path
     * @param array $data The template data
     */
    protected function renderAmpTemplate(string $template, array $data = []): void
    {
        $this->ampData = array_merge($this->getDefaultAmpData(), $data);
        $this->ampHtml = view($template, $this->ampData)->render();
    }

    /**
     * Get default data for AMP templates.
     */
    protected function getDefaultAmpData(): array
    {
        return [
            'ampApiUrl' => config('freegle.amp.api_url'),
            'siteName' => config('freegle.branding.name', 'Freegle'),
            'userSite' => config('freegle.sites.user'),
        ];
    }

    /**
     * Apply AMP content to the Symfony message.
     *
     * This should be called within withSymfonyMessage() to add the
     * text/x-amp-html MIME part alongside the regular HTML.
     *
     * Email structure becomes:
     * - multipart/alternative
     *   - text/plain
     *   - text/x-amp-html (AMP version)
     *   - text/html (fallback HTML version)
     *
     * @param Email $message The Symfony email message
     */
    protected function applyAmpToMessage(Email $message): void
    {
        if (!$this->ampHtml || !$this->isAmpEnabled()) {
            return;
        }

        $body = $message->getBody();

        // Get existing parts.
        $htmlBody = $message->getHtmlBody();
        $textBody = $message->getTextBody();

        if (!$htmlBody) {
            return;
        }

        // Create the AMP part with correct MIME type.
        $ampPart = new TextPart($this->ampHtml, 'utf-8', 'x-amp-html');

        // Create text part if exists.
        $parts = [];
        if ($textBody) {
            $parts[] = new TextPart($textBody, 'utf-8', 'plain');
        }

        // Add AMP part (must come before HTML for proper fallback).
        $parts[] = $ampPart;

        // Add HTML part.
        $parts[] = new TextPart($htmlBody, 'utf-8', 'html');

        // Create new alternative part with all versions.
        $alternativePart = new AlternativePart(...$parts);

        // Replace the message body.
        $message->setBody($alternativePart);
    }
}
