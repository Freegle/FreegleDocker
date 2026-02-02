<?php

namespace App\Console\Commands;

use App\Services\Mail\Incoming\IncomingMailService;
use App\Services\Mail\Incoming\MailParserService;
use App\Services\Mail\Incoming\RoutingResult;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;

/**
 * Replay archived incoming emails through the new Laravel code for shadow testing.
 *
 * This command processes archive files created by the legacy incoming.php script
 * and compares the routing results. It runs in dry-run mode by default - no database
 * changes are made.
 *
 * Archive files are expected to be JSON with this structure:
 * {
 *   "version": 1,
 *   "timestamp": "2026-01-28T12:34:56Z",
 *   "envelope": {
 *     "from": "sender@example.com",
 *     "to": "group@groups.ilovefreegle.org"
 *   },
 *   "raw_email": "... base64 encoded ...",
 *   "legacy_result": {
 *     "routing_outcome": "Approved",
 *     "message_id": 12345,
 *     ...
 *   }
 * }
 */
class ReplayIncomingArchiveCommand extends Command
{
    protected $signature = 'mail:replay-archive
                            {path : Path to archive file or directory}
                            {--limit=0 : Maximum number of files to process (0 = unlimited)}
                            {--stop-on-mismatch : Stop processing when a mismatch is found}
                            {--verbose-match : Show details for matching results too}
                            {--output=table : Output format: table, json, or summary}';

    protected $description = 'Replay archived incoming emails through new Laravel code for shadow testing';

    /**
     * Mapping from legacy routing outcomes to new RoutingResult values.
     */
    private const LEGACY_TO_NEW_MAP = [
        'Failure' => RoutingResult::FAILURE,
        'IncomingSpam' => RoutingResult::INCOMING_SPAM,
        'Approved' => RoutingResult::APPROVED,
        'Pending' => RoutingResult::PENDING,
        'ToUser' => RoutingResult::TO_USER,
        'ToSystem' => RoutingResult::TO_SYSTEM,
        'ReadReceipt' => RoutingResult::RECEIPT,
        'Tryst' => RoutingResult::TRYST,
        'Dropped' => RoutingResult::DROPPED,
        'ToVolunteers' => RoutingResult::TO_VOLUNTEERS,
    ];

    private IncomingMailService $incomingMailService;

    private MailParserService $parserService;

    public function __construct(IncomingMailService $incomingMailService, MailParserService $parserService)
    {
        parent::__construct();
        $this->incomingMailService = $incomingMailService;
        $this->parserService = $parserService;
    }

    public function handle(): int
    {
        $path = $this->argument('path');
        $limit = (int) $this->option('limit');
        $stopOnMismatch = $this->option('stop-on-mismatch');
        $verboseMatch = $this->option('verbose-match');
        $outputFormat = $this->option('output');

        // Collect archive files
        $files = $this->collectArchiveFiles($path);

        if (empty($files)) {
            $this->error("No archive files found at: $path");

            return 1;
        }

        $this->info(sprintf('Found %d archive file(s)', count($files)));

        if ($limit > 0) {
            $files = array_slice($files, 0, $limit);
            $this->info(sprintf('Processing first %d file(s)', $limit));
        }

        // Process files and collect results
        $results = [];
        $stats = [
            'total' => 0,
            'matched' => 0,
            'mismatched' => 0,
            'errors' => 0,
            'skipped' => 0,
        ];

        $progressBar = $this->output->createProgressBar(count($files));
        $progressBar->start();

        foreach ($files as $file) {
            $result = $this->processArchiveFile($file);
            $results[] = $result;

            $stats['total']++;
            if ($result['error']) {
                $stats['errors']++;
            } elseif (isset($result['skipped_reason'])) {
                $stats['skipped']++;
            } elseif ($result['match']) {
                $stats['matched']++;
            } else {
                $stats['mismatched']++;

                if ($stopOnMismatch) {
                    $progressBar->finish();
                    $this->newLine();
                    $this->error('Stopping on first mismatch');
                    $this->displayMismatch($result);

                    return 1;
                }
            }

            $progressBar->advance();
        }

        $progressBar->finish();
        $this->newLine(2);

        // Output results
        $this->outputResults($results, $stats, $outputFormat, $verboseMatch);

        return $stats['mismatched'] > 0 ? 1 : 0;
    }

    /**
     * Collect archive files from path (file or directory).
     */
    private function collectArchiveFiles(string $path): array
    {
        if (is_file($path)) {
            return [$path];
        }

        if (is_dir($path)) {
            $files = [];
            $iterator = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($path, \RecursiveDirectoryIterator::SKIP_DOTS)
            );

            foreach ($iterator as $file) {
                if ($file->isFile() && $file->getExtension() === 'json') {
                    $files[] = $file->getPathname();
                }
            }

            sort($files);

            return $files;
        }

        return [];
    }

    /**
     * Process a single archive file.
     */
    private function processArchiveFile(string $file): array
    {
        $result = [
            'file' => basename($file),
            'path' => $file,
            'match' => false,
            'error' => null,
            'legacy_outcome' => null,
            'new_outcome' => null,
            'envelope_from' => null,
            'envelope_to' => null,
            'subject' => null,
            'routing_context' => null,
            'new_user_id' => null,
            'new_group_id' => null,
            'new_chat_id' => null,
            'user_id_mismatch' => false,
            'legacy_user_id' => null,
        ];

        try {
            // Read and parse archive
            $content = File::get($file);
            $archive = json_decode($content, true);

            if (json_last_error() !== JSON_ERROR_NONE) {
                throw new \RuntimeException('Invalid JSON: '.json_last_error_msg());
            }

            if (! isset($archive['version']) || ! in_array($archive['version'], [1, 2])) {
                throw new \RuntimeException('Unsupported archive version');
            }

            // Extract data
            $rawEmail = base64_decode($archive['raw_email']);
            $envelopeFrom = $archive['envelope']['from'] ?? '';
            $envelopeTo = $archive['envelope']['to'] ?? '';
            $legacyOutcome = $archive['legacy_result']['routing_outcome'] ?? null;

            $result['envelope_from'] = $envelopeFrom;
            $result['envelope_to'] = $envelopeTo;
            $result['legacy_outcome'] = $legacyOutcome;
            $result['subject'] = $archive['legacy_result']['subject'] ?? null;

            // Capture routing context from v2 archives for mismatch diagnostics
            if ($archive['version'] >= 2) {
                $result['routing_context'] = [
                    'our_posting_status' => $archive['legacy_result']['our_posting_status'] ?? null,
                    'membership_role' => $archive['legacy_result']['membership_role'] ?? null,
                    'group_moderated' => $archive['legacy_result']['group_moderated'] ?? null,
                    'override_moderation' => $archive['legacy_result']['override_moderation'] ?? null,
                ];
            }

            // Check if legacy didn't save the message (message_id is null)
            // This happens when:
            // 1. Message was a duplicate (same message-id already in DB) - failok=true
            // 2. Message failed to parse/save for other reasons - failok=false
            // In both cases, legacy never called route(), so we shouldn't route either.
            // We just accept the legacy outcome as correct for these cases.
            $legacyMessageId = $archive['legacy_result']['message_id'] ?? null;
            if ($legacyMessageId === null) {
                // Legacy didn't save this message, so don't try to route it
                $result['new_outcome'] = $legacyOutcome;
                $result['match'] = true;  // Accept legacy outcome as authoritative
                $result['skipped_reason'] = 'Legacy did not save message (duplicate or parse failure)';

                return $result;
            }

            // Parse email through new code
            $parsed = $this->parserService->parse($rawEmail, $envelopeFrom, $envelopeTo);

            // Route through new code in dry-run mode (no DB writes)
            $outcome = $this->incomingMailService->routeDryRun($parsed);
            $newOutcome = $outcome->result;
            $result['new_outcome'] = $newOutcome->value;
            $result['new_user_id'] = $outcome->userId;
            $result['new_group_id'] = $outcome->groupId;
            $result['new_chat_id'] = $outcome->chatId;

            // Compare outcomes
            $expectedNewOutcome = self::LEGACY_TO_NEW_MAP[$legacyOutcome] ?? null;
            $result['match'] = ($newOutcome === $expectedNewOutcome);

            // If outcomes match, also compare user_id if both are available
            if ($result['match'] && $outcome->userId !== null) {
                $legacyUserId = $archive['legacy_result']['user_id'] ?? null;
                if ($legacyUserId !== null && (int) $legacyUserId !== $outcome->userId) {
                    $result['match'] = false;
                    $result['user_id_mismatch'] = true;
                    $result['legacy_user_id'] = (int) $legacyUserId;
                }
            }

        } catch (\Throwable $e) {
            $result['error'] = $e->getMessage();
            Log::warning('Archive replay error', [
                'file' => $file,
                'error' => $e->getMessage(),
            ]);
        }

        return $result;
    }

    /**
     * Output results based on format.
     */
    private function outputResults(array $results, array $stats, string $format, bool $verboseMatch): void
    {
        // Summary stats
        $this->info('=== Summary ===');
        $this->table(
            ['Metric', 'Count', 'Percentage'],
            [
                ['Total', $stats['total'], '100%'],
                ['Matched', $stats['matched'], $this->percentage($stats['matched'], $stats['total'])],
                ['Mismatched', $stats['mismatched'], $this->percentage($stats['mismatched'], $stats['total'])],
                ['Skipped (duplicates)', $stats['skipped'], $this->percentage($stats['skipped'], $stats['total'])],
                ['Errors', $stats['errors'], $this->percentage($stats['errors'], $stats['total'])],
            ]
        );

        if ($format === 'json') {
            $this->newLine();
            $this->line(json_encode([
                'stats' => $stats,
                'results' => $results,
            ], JSON_PRETTY_PRINT));

            return;
        }

        if ($format === 'summary') {
            // Just show summary, no details
            return;
        }

        // Table format - show mismatches and errors
        $this->newLine();

        // Show mismatches
        $mismatches = array_filter($results, fn ($r) => ! $r['match'] && ! $r['error']);
        if (! empty($mismatches)) {
            $this->error('=== Mismatches ===');
            foreach ($mismatches as $mismatch) {
                $this->displayMismatch($mismatch);
            }
        }

        // Show errors
        $errors = array_filter($results, fn ($r) => $r['error']);
        if (! empty($errors)) {
            $this->warn('=== Errors ===');
            foreach ($errors as $error) {
                $this->line(sprintf('  %s: %s', $error['file'], $error['error']));
            }
        }

        // Show matches if verbose
        if ($verboseMatch) {
            $matches = array_filter($results, fn ($r) => $r['match']);
            if (! empty($matches)) {
                $this->info('=== Matches ===');
                $this->table(
                    ['File', 'Envelope To', 'Outcome'],
                    array_map(fn ($r) => [$r['file'], $r['envelope_to'], $r['legacy_outcome']], $matches)
                );
            }
        }
    }

    /**
     * Display details of a mismatch.
     */
    private function displayMismatch(array $result): void
    {
        $this->newLine();
        $this->line(sprintf('  <fg=red>File:</> %s', $result['file']));
        $this->line(sprintf('  <fg=yellow>From:</> %s', $result['envelope_from']));
        $this->line(sprintf('  <fg=yellow>To:</> %s', $result['envelope_to']));
        if ($result['subject']) {
            $this->line(sprintf('  <fg=yellow>Subject:</> %s', $result['subject']));
        }
        $this->line(sprintf('  <fg=green>Legacy:</> %s', $result['legacy_outcome']));
        $this->line(sprintf('  <fg=red>New:</> %s', $result['new_outcome']));

        // Show routing context from v2 archives to aid diagnosis
        if (! empty($result['routing_context'])) {
            $ctx = $result['routing_context'];
            $this->line(sprintf(
                '  <fg=cyan>Context:</> postingStatus=%s role=%s groupModerated=%s override=%s',
                $ctx['our_posting_status'] ?? 'NULL',
                $ctx['membership_role'] ?? 'NULL',
                $ctx['group_moderated'] === null ? 'NULL' : ($ctx['group_moderated'] ? 'yes' : 'no'),
                $ctx['override_moderation'] ?? 'NULL'
            ));
        }

        // Show user ID mismatch if outcomes matched but user IDs differ
        if (! empty($result['user_id_mismatch'])) {
            $this->line(sprintf(
                '  <fg=magenta>User ID mismatch:</> legacy=%d new=%d',
                $result['legacy_user_id'],
                $result['new_user_id']
            ));
        }

        // Show new routing context (user/group/chat)
        $contextParts = [];
        if (! empty($result['new_user_id'])) {
            $contextParts[] = 'user=' . $result['new_user_id'];
        }
        if (! empty($result['new_group_id'])) {
            $contextParts[] = 'group=' . $result['new_group_id'];
        }
        if (! empty($result['new_chat_id'])) {
            $contextParts[] = 'chat=' . $result['new_chat_id'];
        }
        if (! empty($contextParts)) {
            $this->line(sprintf('  <fg=blue>New routing:</> %s', implode(' ', $contextParts)));
        }
    }

    /**
     * Calculate percentage string.
     */
    private function percentage(int $count, int $total): string
    {
        if ($total === 0) {
            return '0%';
        }

        return sprintf('%.1f%%', ($count / $total) * 100);
    }

    /**
     * Extract Message-Id from raw email.
     */
    private function extractMessageId(string $rawEmail): ?string
    {
        // Match Message-Id header (case insensitive)
        if (preg_match('/^Message-I[dD]:\s*<?([^>\s]+)>?\s*$/mi', $rawEmail, $matches)) {
            return $matches[1];
        }

        return null;
    }

    /**
     * Check if message is a duplicate (already exists in database).
     *
     * Legacy behavior: message-id has group suffix appended, so we check with LIKE.
     * This matches both exact message-id and message-id-{groupid} variants.
     */
    private function isDuplicateMessage(string $messageId): bool
    {
        // The legacy code appends "-{groupid}" to message-ids, so we need to check
        // for both the exact message-id and any with a suffix
        return DB::table('messages')
            ->where('messageid', $messageId)
            ->orWhere('messageid', 'LIKE', $messageId.'-%')
            ->exists();
    }
}
