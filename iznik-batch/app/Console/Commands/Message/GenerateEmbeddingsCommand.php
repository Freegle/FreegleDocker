<?php

namespace App\Console\Commands\Message;

use App\Traits\GracefulShutdown;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Symfony\Component\Process\Process;

class GenerateEmbeddingsCommand extends Command
{
    use GracefulShutdown;

    protected $signature = 'embeddings:generate
                            {--backfill : Process all messages without embeddings}
                            {--limit=500 : Maximum messages to process per run}';

    protected $description = 'Generate vector embeddings for messages in messages_spatial';

    public function handle(): int
    {
        $this->registerShutdownHandlers();

        $limit = (int) $this->option('limit');

        if ($this->option('backfill')) {
            $limit = 50000;
        }

        // Find messages in messages_spatial that don't have embeddings yet
        $messages = DB::select("
            SELECT ms.msgid, m.subject, LEFT(m.textbody, 500) as body
            FROM messages_spatial ms
            JOIN messages m ON m.id = ms.msgid
            LEFT JOIN messages_embeddings me ON me.msgid = ms.msgid
            WHERE me.msgid IS NULL
            AND ms.successful = 0
            AND ms.promised = 0
            ORDER BY ms.arrival DESC
            LIMIT ?
        ", [$limit]);

        if (empty($messages)) {
            $this->info('No messages need embedding.');

            return Command::SUCCESS;
        }

        $this->info(sprintf('Generating embeddings for %d messages...', count($messages)));

        // Prepare NDJSON input for the Node script
        $input = '';
        foreach ($messages as $msg) {
            $subject = $msg->subject ?? '';
            // Strip OFFER:/WANTED: prefix
            $subject = preg_replace('/^(OFFER|WANTED|Offered|Requested):\s*/i', '', $subject);
            $body = $msg->body ?? '';
            $text = trim($subject.'. '.$body);

            $input .= json_encode([
                'msgid' => $msg->msgid,
                'text' => $text,
            ])."\n";
        }

        // Shell out to Node.js embed script
        $scriptPath = base_path('resources/js/embed.mjs');
        $process = new Process(['node', $scriptPath]);
        $process->setInput($input);
        $process->setTimeout(300); // 5 min timeout

        $process->run();

        if (! $process->isSuccessful()) {
            $this->error('Embedding script failed: '.$process->getErrorOutput());
            Log::error('Embedding generation failed', [
                'stderr' => $process->getErrorOutput(),
            ]);

            return Command::FAILURE;
        }

        // Parse output and insert into DB
        $count = 0;
        $lines = explode("\n", trim($process->getOutput()));

        foreach ($lines as $line) {
            if ($this->shouldAbort()) {
                $this->warn('Aborting due to shutdown signal.');
                break;
            }

            $result = json_decode($line, true);
            if (! $result || ! isset($result['msgid'], $result['embedding'])) {
                continue;
            }

            // Pack float32 array into little-endian binary blob
            $binary = pack('g*', ...$result['embedding']);

            DB::statement(
                'INSERT IGNORE INTO messages_embeddings (msgid, embedding, model_version) VALUES (?, ?, ?)',
                [$result['msgid'], $binary, 'nomic-embed-text-v1.5-dim256']
            );

            $count++;
        }

        $this->info("Generated {$count} embeddings.");
        Log::info('Embedding generation complete', ['count' => $count]);

        return Command::SUCCESS;
    }
}
