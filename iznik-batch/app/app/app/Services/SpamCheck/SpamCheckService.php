<?php

namespace App\Services\SpamCheck;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Service for checking emails against SpamAssassin and Rspamd.
 */
class SpamCheckService
{
    private string $spamassassinHost;
    private int $spamassassinPort;
    private string $rspamdHost;
    private int $rspamdPort;

    public function __construct()
    {
        $this->spamassassinHost = config('freegle.spam_check.spamassassin_host', 'spamassassin-app');
        $this->spamassassinPort = (int) config('freegle.spam_check.spamassassin_port', 783);
        $this->rspamdHost = config('freegle.spam_check.rspamd_host', 'rspamd');
        $this->rspamdPort = (int) config('freegle.spam_check.rspamd_port', 11334);
    }

    /**
     * Check an email with SpamAssassin.
     */
    public function checkSpamAssassin(string $rawEmail): SpamResult
    {
        try {
            $socket = @fsockopen($this->spamassassinHost, $this->spamassassinPort, $errno, $errstr, 5);

            if (!$socket) {
                return SpamResult::error('SpamAssassin', "Connection failed: {$errstr}");
            }

            // Send SYMBOLS command (returns score and matched rules)
            $command = "SYMBOLS SPAMC/1.5\r\n";
            $command .= "Content-length: " . strlen($rawEmail) . "\r\n";
            $command .= "\r\n";
            $command .= $rawEmail;

            fwrite($socket, $command);

            $response = '';
            while (!feof($socket)) {
                $response .= fgets($socket, 1024);
            }

            fclose($socket);

            return SpamResult::fromSpamAssassin($response);
        } catch (\Exception $e) {
            Log::warning('SpamAssassin check failed', ['error' => $e->getMessage()]);
            return SpamResult::error('SpamAssassin', $e->getMessage());
        }
    }

    /**
     * Check an email with Rspamd.
     */
    public function checkRspamd(string $rawEmail): SpamResult
    {
        try {
            $url = "http://{$this->rspamdHost}:{$this->rspamdPort}/checkv2";

            $response = Http::timeout(10)
                ->withHeaders([
                    'Content-Type' => 'message/rfc822',
                ])
                ->withBody($rawEmail, 'message/rfc822')
                ->post($url);

            if (!$response->successful()) {
                return SpamResult::error('Rspamd', "HTTP {$response->status()}");
            }

            $data = $response->json();
            if (!$data) {
                return SpamResult::error('Rspamd', 'Invalid JSON response');
            }

            return SpamResult::fromRspamd($data);
        } catch (\Exception $e) {
            Log::warning('Rspamd check failed', ['error' => $e->getMessage()]);
            return SpamResult::error('Rspamd', $e->getMessage());
        }
    }

    /**
     * Check an email with all available spam filters.
     *
     * @return array<string, SpamResult>
     */
    public function checkAll(string $rawEmail): array
    {
        return [
            'spamassassin' => $this->checkSpamAssassin($rawEmail),
            'rspamd' => $this->checkRspamd($rawEmail),
        ];
    }

    /**
     * Check if spam checking is enabled.
     */
    public static function isEnabled(): bool
    {
        return (bool) config('freegle.spam_check.enabled', false);
    }
}
