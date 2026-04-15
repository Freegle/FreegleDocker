<?php

namespace App\Console\Commands\Monitor;

use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Monitors incoming and outgoing email health.
 *
 * Returns FAILURE if:
 * - Zero incoming emails (platform=0 chat messages) in the last N hours during daytime (07:00-22:00)
 * - Fewer than N outgoing emails (email_tracking sent) in the last hour during daytime (07:00-22:00)
 *
 * Thresholds are configurable via .env. Failures show as red badges in the
 * cron jobs sysadmin tab.
 */
class EmailHealthCommand extends Command
{
    protected $signature = 'monitor:email-health';

    protected $description = 'Checks incoming and outgoing email flow and fails if thresholds are breached';

    public function handle(): int
    {
        $now = Carbon::now();
        $hour = $now->hour;

        $dayStart = (int) config('freegle.email_health.daytime_start', 7);
        $dayEnd = (int) config('freegle.email_health.daytime_end', 22);

        // Only check during daytime hours
        if ($hour < $dayStart || $hour >= $dayEnd) {
            $this->info("Outside monitoring window ({$dayStart}:00-{$dayEnd}:00). Skipping.");

            return Command::SUCCESS;
        }

        $failures = [];

        // --- Incoming email check ---
        $incomingWindowHours = (int) config('freegle.email_health.incoming_window_hours', 2);

        $incomingCount = DB::table('chat_messages')
            ->where('platform', 0)
            ->where('date', '>=', $now->copy()->subHours($incomingWindowHours))
            ->count();

        if ($incomingCount === 0) {
            $failures[] = "INCOMING: 0 emails received in the last {$incomingWindowHours} hours";
        }

        $this->info("Incoming: {$incomingCount} emails in last {$incomingWindowHours}h");

        // --- Outgoing email check ---
        $outgoingMinPerHour = (int) config('freegle.email_health.outgoing_min_per_hour', 10);

        $outgoingCount = DB::table('email_tracking')
            ->where('sent_at', '>=', $now->copy()->subHour())
            ->count();

        if ($outgoingCount < $outgoingMinPerHour) {
            $failures[] = "OUTGOING: {$outgoingCount} emails sent in last hour (threshold: {$outgoingMinPerHour})";
        }

        $this->info("Outgoing: {$outgoingCount} emails in last 1h (min: {$outgoingMinPerHour})");

        // --- Result ---
        if (! empty($failures)) {
            foreach ($failures as $f) {
                $this->error($f);
                Log::warning("[EmailHealth] {$f}");
            }

            return Command::FAILURE;
        }

        $this->info('Email health OK.');

        return Command::SUCCESS;
    }
}
