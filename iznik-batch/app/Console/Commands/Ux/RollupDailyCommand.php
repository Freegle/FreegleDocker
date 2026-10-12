<?php

namespace App\Console\Commands\Ux;

use App\Services\Ux\UxRollupService;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Console\Attribute\AsCommand;

/**
 * Nightly: roll one UTC day of client telemetry (Loki) and regret signals (MySQL) into the ux_*
 * tables that the ModTools UX tab reads. Rerunning a day replaces it.
 *
 * With no --date it does yesterday, and first fills any of the six days before that which have no
 * ux_telemetry_health_daily rows, so a missed night is not a hole. Loki keeps seven days, so the
 * oldest of those may already be gone; that shows as a day of zeros on the health panel, not as
 * a silent gap.
 */
#[AsCommand(name: 'ux:rollup-daily')]
class RollupDailyCommand extends Command
{
    protected $signature = 'ux:rollup-daily {--date= : UTC day to roll up, YYYY-MM-DD (default yesterday, plus any missing day of the six before)}';

    protected $description = 'Roll a day of UX telemetry from Loki and MySQL into the ux_* tables';

    public function handle(UxRollupService $service): int
    {
        $given = $this->option('date');
        if ($given !== null) {
            if (! preg_match('/^\d{4}-\d{2}-\d{2}$/', $given) || Carbon::createFromFormat('Y-m-d', $given) === false) {
                $this->error("--date must be YYYY-MM-DD, got '$given'.");

                return self::FAILURE;
            }
            $days = [Carbon::parse($given, 'UTC')->startOfDay()];
        } else {
            $yesterday = Carbon::now('UTC')->startOfDay()->subDay();
            $days = [];
            // Oldest first: the health check's trailing median reads the days before it.
            for ($back = 6; $back >= 1; $back--) {
                $day = $yesterday->copy()->subDays($back);
                if (! DB::table('ux_telemetry_health_daily')->where('date', $day->toDateString())->exists()) {
                    $days[] = $day;
                }
            }
            $days[] = $yesterday;
        }

        $failed = 0;
        foreach ($days as $day) {
            $service->rollupRegret($day);
            if ($service->rollupLokiDay($day)) {
                $this->info('Rolled up '.$day->toDateString().'.');
            } else {
                $failed++;
                $this->error('Loki was unreachable for '.$day->toDateString().'; its Loki tables were left as they were.');
            }
        }

        return $failed ? self::FAILURE : self::SUCCESS;
    }
}
