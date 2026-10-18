<?php

namespace App\Console\Commands\Discourse;

use App\Services\DiscourseNotSignedUpService;
use Illuminate\Console\Command;
use Symfony\Component\Console\Attribute\AsCommand;

/**
 * Daily check for Freegle groups not represented by an active mod on Discourse,
 * active mods not signed up, and TrashNothing accounts with mod roles (V1
 * cron/discourse_not_signed_up.php).
 */
#[AsCommand(name: 'discourse:not-signed-up')]
class NotSignedUpCommand extends Command
{
    protected $signature = 'discourse:not-signed-up';

    protected $description = 'Report mods not signed up to Discourse + TrashNothing accounts with mod roles';

    public function handle(DiscourseNotSignedUpService $service): int
    {
        $result = $service->run();

        if ($result['skipped'] ?? false) {
            $this->warn('Discourse API key not configured — skipped.');

            return self::SUCCESS;
        }

        $this->info(sprintf(
            'Done. volunteers not signed up=%d, TN accounts with mod roles=%d.',
            $result['notondiscourse'], $result['tnmods']
        ));

        return self::SUCCESS;
    }
}
