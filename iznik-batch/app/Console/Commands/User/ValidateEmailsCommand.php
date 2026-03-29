<?php

namespace App\Console\Commands\User;

use App\Services\UserManagementService;
use App\Traits\GracefulShutdown;
use App\Traits\LogsBatchJob;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class ValidateEmailsCommand extends Command
{
    use GracefulShutdown, LogsBatchJob;

    protected $signature = 'emails:validate';

    protected $description = 'Validate all non-bouncing emails and delete invalid ones';

    public function handle(UserManagementService $service): int
    {
        $this->registerShutdownHandlers();

        return $this->runWithLogging(function () use ($service) {
            Log::info('Starting email validation');

            $stats = $service->validateEmails();

            $this->info("Validated {$stats['total']} emails, deleted {$stats['invalid']} invalid.");
            Log::info('Email validation complete', $stats);

            return Command::SUCCESS;
        });
    }
}
