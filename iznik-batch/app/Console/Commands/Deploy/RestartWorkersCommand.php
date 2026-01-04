<?php

namespace App\Console\Commands\Deploy;

use App\Console\Commands\Mail\ProcessSpoolCommand;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;

class RestartWorkersCommand extends Command
{
    /**
     * The name and signature of the console command.
     */
    protected $signature = 'deploy:restart';

    /**
     * The console command description.
     */
    protected $description = 'Restart queue workers and mail spooler after deployment';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $this->info('Signaling workers to restart...');

        // Restart queue workers (Laravel's built-in mechanism).
        $this->call('queue:restart');

        // Signal mail spooler to restart via cache.
        Cache::forever(ProcessSpoolCommand::RESTART_SIGNAL_KEY, time());
        $this->info('Mail spooler restart signal sent.');

        // Clear config cache to pick up any env changes.
        $this->call('config:clear');

        $this->newLine();
        $this->info('All workers will restart after completing their current task.');

        return Command::SUCCESS;
    }
}
