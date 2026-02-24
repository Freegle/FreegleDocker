<?php

namespace App\Console\Commands\Pledge;

use App\Mail\Pledge\PledgeThankYou;
use App\Models\User;
use App\Services\EmailSpoolerService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

class SendPledgeThankYouCommand extends Command
{
    protected $signature = 'mail:pledge:thankyou
                            {--dry-run : Show what would be sent without actually sending}
                            {--spool : Spool emails instead of sending directly}
                            {--limit=0 : Maximum emails to send (0 = no limit)}
                            {--to= : Send a test email to this address instead of real users}';

    protected $description = 'Send a final thank-you email to users who took the 2025 Freegle Pledge';

    public function handle(EmailSpoolerService $spooler): int
    {
        $dryRun = $this->option('dry-run');
        $spool = $this->option('spool');
        $limit = (int) $this->option('limit');
        $testTo = $this->option('to');

        // Test mode: send a single test email.
        if ($testTo) {
            return $this->sendTestEmail($testTo, $spooler, $spool);
        }

        Log::info('Starting pledge thank-you emails', ['dry_run' => $dryRun, 'spool' => $spool]);
        $this->info($dryRun ? 'DRY RUN - no emails will be sent' : 'Sending pledge thank-you emails...');

        // Find all users who opted in to the 2025 pledge.
        $query = User::whereNull('deleted')
            ->whereRaw("JSON_EXTRACT(settings, '$.pledge2025') = true")
            ->whereHas('emails', function ($q) {
                $q->whereNull('bounced');
            })
            ->orderBy('id');

        if ($limit > 0) {
            $query->limit($limit);
        }

        $users = $query->get();

        $sent = 0;
        $skipped = 0;
        $errors = 0;

        foreach ($users as $user) {
            $email = $user->email_preferred;

            if (!$email) {
                $skipped++;
                continue;
            }

            // Count how many months they actually freegled during 2025.
            $monthsFreegled = $this->countMonthsFreegled($user);

            if ($dryRun) {
                $this->line("Would send to {$email} (user {$user->id}, {$monthsFreegled} months freegled)");
                $sent++;
                continue;
            }

            try {
                $mailable = new PledgeThankYou($user, $monthsFreegled);

                if ($spool) {
                    $spooler->spool($mailable, $email, 'PledgeThankYou');
                } else {
                    Mail::send($mailable);
                }

                $sent++;

                Log::info('Pledge thank-you sent', [
                    'user_id' => $user->id,
                    'email' => $email,
                    'months_freegled' => $monthsFreegled,
                    'spooled' => $spool,
                ]);
            } catch (\Exception $e) {
                $errors++;
                $this->error("Failed for user {$user->id}: {$e->getMessage()}");
                Log::error('Pledge thank-you failed', [
                    'user_id' => $user->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        $this->table(['Metric', 'Value'], [
            ['Total pledgers found', $users->count()],
            ['Emails ' . ($dryRun ? 'would send' : 'sent'), $sent],
            ['Skipped (no email)', $skipped],
            ['Errors', $errors],
        ]);

        Log::info('Pledge thank-you complete', [
            'total' => $users->count(),
            'sent' => $sent,
            'skipped' => $skipped,
            'errors' => $errors,
        ]);

        return $errors > 0 ? Command::FAILURE : Command::SUCCESS;
    }

    /**
     * Count how many months in 2025 the user actually posted items.
     */
    private function countMonthsFreegled(User $user): int
    {
        $settings = $user->settings ?? [];
        $months = 0;

        // The old pledge code stored monthly flags as pledge2025_freegled_1 through pledge2025_freegled_12.
        for ($month = 1; $month <= 12; $month++) {
            if (!empty($settings["pledge2025_freegled_{$month}"])) {
                $months++;
            }
        }

        return $months;
    }

    private function sendTestEmail(string $to, EmailSpoolerService $spooler, bool $spool): int
    {
        $this->info("Sending test pledge thank-you to {$to}...");

        // Build the mailable directly with test data, bypassing User model relations.
        $mailable = new PledgeThankYou(new User(['fullname' => 'Test Pledger']), 7);

        // Override the to/subject since the fake user has no email_preferred.
        $mailable->to($to)
            ->subject('Thank you for taking the Freegle Pledge!');

        try {
            if ($spool) {
                $spooler->spool($mailable, $to, 'PledgeThankYou');
            } else {
                Mail::send($mailable);
            }

            $this->info('Test email sent successfully!');
            return Command::SUCCESS;
        } catch (\Exception $e) {
            $this->error("Failed: {$e->getMessage()}");
            return Command::FAILURE;
        }
    }
}
