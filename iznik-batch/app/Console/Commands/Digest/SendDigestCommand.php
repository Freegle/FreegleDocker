<?php

namespace App\Console\Commands\Digest;

use App\Services\DigestService;
use App\Traits\ChunkedProcessing;
use App\Traits\GracefulShutdown;
use App\Traits\ShardedExecution;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class SendDigestCommand extends Command
{
    use ChunkedProcessing, ShardedExecution, GracefulShutdown;

    /**
     * The name and signature of the console command.
     */
    protected $signature = 'mail:digest
                            {frequency : Digest frequency in hours (-1 for immediate, 1 for hourly, etc.)}
                            {--mod=1 : Modulo divisor for sharding}
                            {--val=0 : Modulo value for this instance}
                            {--group= : Process only a specific group ID}';

    /**
     * The console command description.
     */
    protected $description = 'Send digest emails to members at the specified frequency';

    /**
     * Execute the console command.
     */
    public function handle(DigestService $digestService): int
    {
        $frequency = (int) $this->argument('frequency');
        $this->mod = (int) $this->option('mod');
        $this->val = (int) $this->option('val');
        $groupId = $this->option('group');

        // Validate frequency.
        if (!in_array($frequency, DigestService::getValidFrequencies())) {
            $this->error("Invalid frequency: {$frequency}");
            $this->info('Valid frequencies: ' . implode(', ', DigestService::getValidFrequencies()));
            return Command::FAILURE;
        }

        $this->registerShutdownHandlers();

        Log::info("Starting digest send for frequency {$frequency}", [
            'mod' => $this->mod,
            'val' => $this->val,
            'group' => $groupId,
        ]);

        $this->info("Sending digests for frequency {$frequency}...");

        $totalStats = [
            'groups_processed' => 0,
            'members_processed' => 0,
            'emails_sent' => 0,
            'errors' => 0,
        ];

        // Get groups to process.
        $groupsQuery = $digestService->getActiveGroups();

        // Apply sharding if specified.
        if ($this->mod > 1) {
            $groupsQuery = $groupsQuery->filter(function ($group) {
                return $group->id % $this->mod === $this->val;
            });
        }

        // Filter to specific group if specified.
        if ($groupId) {
            $groupsQuery = $groupsQuery->filter(function ($group) use ($groupId) {
                return $group->id === (int) $groupId;
            });
        }

        foreach ($groupsQuery as $group) {
            if ($this->shouldAbort()) {
                $this->warn('Aborting due to shutdown signal.');
                break;
            }

            try {
                $this->line("Processing group: {$group->nameshort} (ID: {$group->id})");

                $stats = $digestService->sendDigestForGroup($group, $frequency);

                $totalStats['groups_processed']++;
                $totalStats['members_processed'] += $stats['members_processed'];
                $totalStats['emails_sent'] += $stats['emails_sent'];
                $totalStats['errors'] += $stats['errors'];

                if ($stats['emails_sent'] > 0) {
                    $this->info("  Sent {$stats['emails_sent']} emails to {$stats['members_processed']} members");
                }
            } catch (\Exception $e) {
                $this->error("Error processing group {$group->nameshort}: " . $e->getMessage());
                Log::error("Digest error for group {$group->id}: " . $e->getMessage());
                $totalStats['errors']++;
            }
        }

        $this->newLine();
        $this->info('Digest send complete.');
        $this->table(
            ['Metric', 'Value'],
            [
                ['Groups processed', $totalStats['groups_processed']],
                ['Members processed', $totalStats['members_processed']],
                ['Emails sent', $totalStats['emails_sent']],
                ['Errors', $totalStats['errors']],
            ]
        );

        Log::info('Digest send complete', $totalStats);

        return $totalStats['errors'] > 0 ? Command::FAILURE : Command::SUCCESS;
    }
}
