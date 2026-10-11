<?php

namespace App\Console\Commands\Ripple;

use App\Services\Ripple\RippleEngagementService;
use App\Traits\GracefulShutdown;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Symfony\Component\Console\Attribute\AsCommand;

/**
 * ripple:clear-engaged - makes a ripple-created membership ordinary once the member has
 * posted on the community, replied to one of its posts, or written back to one of its
 * members about their rippled post, so its moderators can write to them (Discourse 10239).
 * See RippleEngagementService.
 */
#[AsCommand(name: 'ripple:clear-engaged')]
class ClearEngagedMembershipsCommand extends Command
{
    use GracefulShutdown;

    protected $signature = 'ripple:clear-engaged
                            {--days=120 : How far back a first run looks, when no position is saved}';

    protected $description = 'Make ripple-created memberships ordinary once the member has engaged with the community';

    public function handle(RippleEngagementService $svc): int
    {
        $this->registerShutdownHandlers();

        $stats = $svc->run((int) $this->option('days'));

        $this->info("Made ordinary: {$stats['posted']} posted, {$stats['replied']} replied, "
            ."{$stats['wrote_back']} wrote back.");

        if (array_sum($stats) > 0) {
            Log::info('ripple:clear-engaged', $stats);
        }

        return self::SUCCESS;
    }
}
