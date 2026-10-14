<?php

namespace App\Console\Commands\Ux;

use App\Services\Ux\UxExperimentService;
use App\Services\Ux\UxPromptService;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Console\Attribute\AsCommand;

/**
 * Hourly: read every running UX experiment from Loki by arm, record the read in plain English,
 * move traffic towards the arm that is winning, and decide when the time is up or the answer is
 * already clear. Also lists adopted experiments whose code cleanup is overdue, so a forgotten
 * switch is seen within a day, weighs the problems members ranked in a follow-up survey, and blanks
 * the words of open-question answers 90 days after their prompt closed. docs/developers/reference/ux-telemetry.md section 8.
 */
#[AsCommand(name: 'ux:experiments-tick')]
class ExperimentsTickCommand extends Command
{
    /** Member free text (answers and comments) is kept this long after it was written, for coding, and then blanked. */
    public const KEEP_ANSWERS_DAYS = 90;

    protected $signature = 'ux:experiments-tick';

    protected $description = 'Read, re-weight and decide the running UX experiments';

    public function handle(UxExperimentService $service, UxPromptService $prompts): int
    {
        $now = Carbon::now('UTC');
        $lines = $service->tick($now);
        $full = $prompts->closeFull();
        if ($full) {
            $this->info("Closed $full prompt(s) that had collected their answers.");
        }
        $weighed = $prompts->weighFollowups();
        if ($weighed) {
            $this->info("Weighed $weighed problem(s) from the follow-up rankings.");
        }
        $blanked = $prompts->blankOldAnswers($now, self::KEEP_ANSWERS_DAYS);
        if ($blanked) {
            $this->info("Removed the words of $blanked answer(s) written over ".self::KEEP_ANSWERS_DAYS.' days ago.');
        }
        if (! $lines) {
            $this->info('No running experiments.');
        }
        foreach ($lines as $line) {
            $this->info($line);
        }

        $days = (int) UxExperimentService::settings()['cleanup_days'];
        $overdue = DB::table('ux_experiments')
            ->where('status', UxExperimentService::STATUS_ADOPTED)
            ->where('decided_at', '<', $now->copy()->subDays($days))
            ->orderBy('decided_at')
            ->get();
        foreach ($overdue as $exp) {
            $this->warn("Cleanup overdue: {$exp->name} was adopted on ".Carbon::parse($exp->decided_at)->toDateString()
                .' and its switch is still in the code.');
        }

        return self::SUCCESS;
    }
}
