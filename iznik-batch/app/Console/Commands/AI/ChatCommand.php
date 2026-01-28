<?php

namespace App\Console\Commands\AI;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;

/**
 * AI Support Chat - Interactive CLI chatbot for Freegle support.
 *
 * This command provides a text-based interface to the AI Support Helper,
 * allowing support staff to investigate issues using natural language.
 *
 * The AI has access to:
 * - Loki logs (pseudonymized for privacy)
 * - MySQL database (pseudonymized for privacy)
 * - Freegle codebase for technical questions
 *
 * Example usage:
 *   php artisan ai:chat "When did user 12345 last log in?"
 *   php artisan ai:chat --interactive
 */
class ChatCommand extends Command
{
    /**
     * The name and signature of the console command.
     */
    protected $signature = 'ai:chat
                            {question? : Question to ask the AI (omit for interactive mode)}
                            {--i|interactive : Run in interactive conversation mode}
                            {--user= : User ID to investigate}
                            {--debug : Show debug information including API calls}
                            {--url= : AI Support Helper URL (default: http://ai-support-helper:3000)}';

    /**
     * The console command description.
     */
    protected $description = 'Chat with the AI Support Helper to investigate issues';

    /**
     * AI Support Helper base URL.
     */
    private string $aiUrl;

    /**
     * Current Claude session ID for conversation continuity.
     */
    private ?string $claudeSessionId = null;

    /**
     * Debug mode flag.
     */
    private bool $debug = false;

    /**
     * User ID being investigated.
     */
    private ?int $userId = null;

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $this->aiUrl = $this->option('url') ?: 'http://ai-support-helper:3000';
        $this->debug = $this->option('debug');
        $this->userId = $this->option('user') ? (int) $this->option('user') : null;

        // Check if AI Support Helper is available
        if (!$this->checkHealth()) {
            return Command::FAILURE;
        }

        $question = $this->argument('question');
        $interactive = $this->option('interactive');

        if ($question) {
            // Single question mode
            return $this->askQuestion($question) ? Command::SUCCESS : Command::FAILURE;
        }

        if ($interactive) {
            // Interactive mode
            return $this->runInteractive();
        }

        // No question provided and not interactive - show help
        $this->info('AI Support Chat - Freegle Support Assistant');
        $this->newLine();
        $this->line('Usage:');
        $this->line('  php artisan ai:chat "Your question here"');
        $this->line('  php artisan ai:chat --interactive');
        $this->newLine();
        $this->line('Options:');
        $this->line('  --user=ID       Specify a user ID to investigate');
        $this->line('  --debug         Show debug information');
        $this->line('  --interactive   Run in conversation mode');
        $this->newLine();
        $this->line('Examples:');
        $this->line('  php artisan ai:chat "When did user 12345 last log in?"');
        $this->line('  php artisan ai:chat --user=12345 "Why is this user having problems?"');
        $this->line('  php artisan ai:chat --interactive --user=12345');

        return Command::SUCCESS;
    }

    /**
     * Check if AI Support Helper is available.
     */
    private function checkHealth(): bool
    {
        try {
            $response = Http::timeout(5)->get("{$this->aiUrl}/health");

            if (!$response->successful()) {
                $this->error('AI Support Helper is not available.');
                $this->line("URL: {$this->aiUrl}");
                return false;
            }

            $health = $response->json();

            if (!($health['auth']['valid'] ?? false)) {
                $this->error('AI Support Helper authentication not configured.');
                $this->line($health['auth']['message'] ?? 'Unknown auth error');
                return false;
            }

            if ($this->debug) {
                $this->info('AI Support Helper is healthy');
                $this->line("Last code update: " . ($health['lastCodeUpdate'] ?? 'unknown'));
            }

            return true;
        } catch (\Exception $e) {
            $this->error('Cannot connect to AI Support Helper.');
            $this->line("URL: {$this->aiUrl}");
            $this->line("Error: {$e->getMessage()}");
            return false;
        }
    }

    /**
     * Ask a single question and display the response.
     */
    private function askQuestion(string $question): bool
    {
        // Add user context if specified
        if ($this->userId) {
            $question = "Investigating Freegle user {$this->userId}. " . $question;
        }

        $this->info('Thinking...');

        try {
            $startTime = microtime(true);

            $payload = [
                'query' => $question,
                'userId' => $this->userId ?? 0,
            ];

            // Include Claude session ID for conversation continuity
            if ($this->claudeSessionId) {
                $payload['claudeSessionId'] = $this->claudeSessionId;
            }

            if ($this->debug) {
                $this->line("Request: " . json_encode($payload, JSON_PRETTY_PRINT));
            }

            $response = Http::timeout(300) // 5 minute timeout for complex queries
                ->post("{$this->aiUrl}/api/log-analysis", $payload);

            $elapsed = round(microtime(true) - $startTime, 1);

            if (!$response->successful()) {
                $error = $response->json();
                $this->error("AI request failed: " . ($error['message'] ?? 'Unknown error'));
                return false;
            }

            $result = $response->json();

            // Store session ID for follow-up questions
            if ($result['claudeSessionId'] ?? null) {
                $this->claudeSessionId = $result['claudeSessionId'];
            }

            // Display the response
            $this->newLine();
            $this->displayAnswer($result['analysis']);
            $this->newLine();

            // Show metadata
            $this->line("<fg=gray>Model: {$result['model']} | Time: {$elapsed}s | Cost: \$" . number_format($result['costUsd'] ?? 0, 4) . "</>");

            if ($result['escalated'] ?? false) {
                $this->line("<fg=yellow>Note: Query was escalated to a more capable model</>");
            }

            // Check for PII warnings
            if ($result['piiScanResult'] ?? null) {
                $this->newLine();
                $this->warn('⚠️  Potential PII detected in response - please review');
                foreach ($result['piiScanResult']['findings'] as $finding) {
                    $this->line("  - {$finding['type']}: {$finding['count']} occurrence(s)");
                }
            }

            return true;
        } catch (\Exception $e) {
            $this->error("Request failed: {$e->getMessage()}");
            return false;
        }
    }

    /**
     * Display the AI's answer with markdown formatting.
     */
    private function displayAnswer(string $answer): void
    {
        // Simple markdown rendering for terminal
        // Order matters: process bold before italic (since ** contains *)

        // Convert `code` to cyan text (do first to protect code from other replacements)
        $answer = preg_replace('/`([^`]+)`/', '<fg=cyan>$1</>', $answer);

        // Convert **bold** to highlighted text
        $answer = preg_replace('/\*\*([^*]+)\*\*/', '<options=bold>$1</>', $answer);

        // Convert *italic* to dim text (only single asterisks, not part of **)
        $answer = preg_replace('/(?<!\*)\*([^*]+)\*(?!\*)/', '<fg=gray>$1</>', $answer);

        $this->line($answer);
    }

    /**
     * Run in interactive conversation mode.
     */
    private function runInteractive(): int
    {
        $this->info('AI Support Chat - Interactive Mode');
        $this->line('Type your questions and press Enter. Type "exit" or "quit" to leave.');
        $this->line('Type "new" to start a new conversation. Type "user 12345" to set user context.');
        $this->newLine();

        if ($this->userId) {
            $this->line("<fg=cyan>Investigating user: {$this->userId}</>");
            $this->newLine();
        }

        while (true) {
            $question = $this->ask('<fg=green>You</>');

            if ($question === null || in_array(strtolower(trim($question)), ['exit', 'quit', 'q'])) {
                $this->info('Goodbye!');
                break;
            }

            $question = trim($question);

            if (empty($question)) {
                continue;
            }

            // Handle special commands
            if (strtolower($question) === 'new') {
                $this->claudeSessionId = null;
                $this->info('Started new conversation');
                continue;
            }

            if (preg_match('/^user\s+(\d+)$/i', $question, $matches)) {
                $this->userId = (int) $matches[1];
                $this->info("Now investigating user {$this->userId}");
                continue;
            }

            if (strtolower($question) === 'user') {
                if ($this->userId) {
                    $this->info("Currently investigating user {$this->userId}");
                } else {
                    $this->info('No user selected. Use "user 12345" to set one.');
                }
                continue;
            }

            // Ask the question
            $this->newLine();
            $this->askQuestion($question);
            $this->newLine();
        }

        return Command::SUCCESS;
    }
}
