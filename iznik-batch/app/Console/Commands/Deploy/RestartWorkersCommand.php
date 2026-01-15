<?php

namespace App\Console\Commands\Deploy;

use Illuminate\Console\Command;

class RestartWorkersCommand extends Command
{
    protected $signature = 'deploy:restart';

    protected $description = 'Restart queue workers and daemons after deployment (alias for clear:all)';

    public function handle(): int
    {
        // Just delegate to clear:all which handles everything.
        return $this->call('clear:all');
    }
}
