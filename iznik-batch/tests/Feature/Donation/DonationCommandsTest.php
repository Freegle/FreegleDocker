<?php

namespace Tests\Feature\Donation;

use App\Models\User;
use App\Models\UserDonation;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class DonationCommandsTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
    }

    public function test_thank_donors_command_runs_successfully(): void
    {
        $this->artisan('mail:donations:thank')
            ->assertExitCode(0);
    }

    public function test_thank_donors_command_displays_stats(): void
    {
        $this->artisan('mail:donations:thank')
            ->expectsOutputToContain('Thanking donors')
            ->expectsOutputToContain('Processed:')
            ->expectsOutputToContain('Emails sent:')
            ->assertExitCode(0);
    }

    public function test_thank_donors_with_donation(): void
    {
        $user = $this->createTestUser();

        UserDonation::create([
            'userid' => $user->id,
            'Payer' => 'test@example.com',
            'PayerDisplayName' => 'Test Donor',
            'timestamp' => now(),
            'TransactionType' => 'Donation',
            'GrossAmount' => 10.00,
            'source' => 'PayPal',
            'thanked' => 0,
        ]);

        $this->artisan('mail:donations:thank')
            ->assertExitCode(0);
    }

    public function test_ask_donations_command_runs_successfully(): void
    {
        $this->artisan('mail:donations:ask')
            ->assertExitCode(0);
    }

    public function test_ask_donations_command_displays_table(): void
    {
        $this->artisan('mail:donations:ask')
            ->expectsOutputToContain('Asking for donations')
            ->expectsTable(
                ['Metric', 'Value'],
                [
                    ['Processed', '0'],
                    ['Emails sent', '0'],
                    ['Skipped (recent ask)', '0'],
                    ['Errors', '0'],
                ]
            )
            ->assertExitCode(0);
    }
}
