<?php

namespace App\Console\Commands\Deploy;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;

class RefreshCommand extends Command
{
    protected $signature = 'deploy:refresh';

    protected $description = 'Refresh application after deployment: clear caches, optimize, restart workers and daemons';

    /**
     * Cache key for storing last deployed version.
     */
    public const VERSION_CACHE_KEY = 'deploy:last_version';

    /**
     * Supervisor programs to restart.
     */
    protected array $supervisorPrograms = [
        'mail-spooler',
    ];

    public function handle(): int
    {
        $this->info('Refreshing application after deployment...');
        $this->newLine();

        $this->clearAndOptimize();
        $this->restartQueueWorkers();
        $this->restartSupervisorPrograms();
        $this->updateDeployedVersion();

        $this->newLine();
        $this->info('Done!');

        return Command::SUCCESS;
    }

    protected function clearAndOptimize(): void
    {
        $this->info('Clearing and rebuilding caches...');

        // Clear all optimization caches first.
        try {
            Artisan::call('optimize:clear');
            $this->line('  <info>✓</info> Cleared optimization caches');
        } catch (\Exception $e) {
            $this->line('  <comment>⚠</comment> Clear failed: ' . $e->getMessage());
        }

        // Rebuild optimized caches for production.
        try {
            Artisan::call('optimize');
            $this->line('  <info>✓</info> Rebuilt optimization caches');
        } catch (\Exception $e) {
            $this->line('  <comment>⚠</comment> Optimize failed: ' . $e->getMessage());
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

    protected function updateDeployedVersion(): void
    {
        $versionFile = base_path('version.txt');

        if (file_exists($versionFile)) {
            $version = trim(file_get_contents($versionFile));
            Cache::forever(self::VERSION_CACHE_KEY, $version);
            $this->newLine();
            $this->line('  <info>✓</info> Recorded deployed version');
        }
    }

    /**
     * Get the current version from the version file.
     */
    public static function getCurrentVersion(): ?string
    {
        $versionFile = base_path('version.txt');

        if (! file_exists($versionFile)) {
            return null;
        }

        return trim(file_get_contents($versionFile));
    }

    /**
     * Get the last deployed version from cache.
     */
    public static function getDeployedVersion(): ?string
    {
        return Cache::get(self::VERSION_CACHE_KEY);
    }
}
