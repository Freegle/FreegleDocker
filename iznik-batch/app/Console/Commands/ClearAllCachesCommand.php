<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;

class ClearAllCachesCommand extends Command
{
    protected $signature = 'clear:all';

    protected $description = 'Clear all Laravel caches (application, config, routes, views, compiled)';

    public function handle(): int
    {
        $this->info('Clearing all caches...');
        $this->newLine();

        $commands = [
            'cache:clear' => 'Application cache',
            'config:clear' => 'Config cache',
            'route:clear' => 'Route cache',
            'view:clear' => 'View cache',
            'clear-compiled' => 'Compiled classes',
        ];

        foreach ($commands as $command => $description) {
            try {
                Artisan::call($command);
                $this->line("  <info>✓</info> {$description} cleared");
            } catch (\Exception $e) {
                $this->line("  <comment>⚠</comment> {$description}: " . $e->getMessage());
            }
        }

        // Event cache (may not exist in all Laravel versions).
        try {
            Artisan::call('event:clear');
            $this->line('  <info>✓</info> Event cache cleared');
        } catch (\Exception $e) {
            // Silently ignore if command doesn't exist.
        }

        $this->newLine();
        $this->info('All caches cleared!');

        return Command::SUCCESS;
    }
}
