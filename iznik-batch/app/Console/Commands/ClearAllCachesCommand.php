<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;

class ClearAllCachesCommand extends Command
{
    protected $signature = 'clear:all';

    protected $description = 'Clear all Laravel caches, restart queue workers, and restart daemon processes';

    /**
     * Supervisor programs to restart.
     */
    protected array $supervisorPrograms = [
        'mail-spooler',
    ];

    public function handle(): int
    {
        $this->info('Clearing all caches and restarting services...');
        $this->newLine();

        $this->clearCaches();
        $this->restartQueueWorkers();
        $this->restartSupervisorPrograms();

        $this->newLine();
        $this->info('Done!');

        return Command::SUCCESS;
    }

    protected function clearCaches(): void
    {
        $this->info('Clearing caches...');

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
                $this->line("  <info>✓</info> {$description}");
            } catch (\Exception $e) {
                $this->line("  <comment>⚠</comment> {$description}: " . $e->getMessage());
            }
        }

        try {
            Artisan::call('event:clear');
            $this->line('  <info>✓</info> Event cache');
        } catch (\Exception $e) {
            // Silently ignore if command doesn't exist.
        }
    }

    protected function restartQueueWorkers(): void
    {
        $this->newLine();
        $this->info('Restarting queue workers...');

        try {
            Artisan::call('queue:restart');
            $this->line('  <info>✓</info> Queue workers signaled');
        } catch (\Exception $e) {
            $this->line('  <comment>⚠</comment> ' . $e->getMessage());
        }
    }

    protected function restartSupervisorPrograms(): void
    {
        $this->newLine();
        $this->info('Restarting supervisor programs...');

        // Check if supervisorctl is available.
        exec('which supervisorctl 2>/dev/null', $output, $returnCode);
        if ($returnCode !== 0) {
            $this->line('  <comment>⚠</comment> supervisorctl not available');
            return;
        }

        foreach ($this->supervisorPrograms as $program) {
            $this->restartProgram($program);
        }
    }

    protected function restartProgram(string $program): void
    {
        exec("supervisorctl restart {$program} 2>&1", $output, $returnCode);

        if ($returnCode === 0) {
            $this->line("  <info>✓</info> {$program}");
        } else {
            $this->line("  <comment>⚠</comment> {$program}: " . implode(' ', $output));
        }
    }
}
