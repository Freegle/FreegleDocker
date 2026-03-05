<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Console\Concerns\PreventsOverlapping;
use App\Traits\GracefulShutdown;

class TNSyncCommand extends Command
{
    use GracefulShutdown;
    use PreventsOverlapping;

    protected $signature = 'tn:sync';

    protected $description = 'Sync data from TrashNothing, including user data updates, user ratings, posts/messages, and chat messages.';

    // TODO: inject any services as needed.
    public function handle(): int
    {
        return Command::SUCCESS;
    }
}
