<?php

namespace Tests\Feature\Digest;

use App\Models\Membership;
use App\Services\DigestService;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class SendDigestCommandTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
    }

    public function test_command_with_invalid_frequency_fails(): void
    {
        $this->artisan('mail:digest', ['frequency' => 99])
            ->expectsOutput('Invalid frequency: 99')
            ->assertExitCode(1);
    }

    public function test_command_with_valid_frequency_succeeds(): void
    {
        // Create group and member for digest.
        $group = $this->createTestGroup();
        $user = $this->createTestUser();
        $this->createMembership($user, $group, [
            'emailfrequency' => Membership::EMAIL_DIGEST_IMMEDIATE,
        ]);

        $this->artisan('mail:digest', ['frequency' => -1])
            ->assertExitCode(0);
    }

    public function test_command_processes_specific_group(): void
    {
        $group = $this->createTestGroup();
        $poster = $this->createTestUser();
        $recipient = $this->createTestUser();

        $this->createMembership($poster, $group);
        $this->createMembership($recipient, $group, [
            'emailfrequency' => Membership::EMAIL_DIGEST_HOURLY,
        ]);

        $this->createTestMessage($poster, $group);

        $this->artisan('mail:digest', [
            'frequency' => 1,
            '--group' => $group->id,
        ])->assertExitCode(0);
    }

    public function test_command_supports_sharding(): void
    {
        // Create multiple groups.
        $group1 = $this->createTestGroup();
        $group2 = $this->createTestGroup();

        $user = $this->createTestUser();
        $this->createMembership($user, $group1, [
            'emailfrequency' => Membership::EMAIL_DIGEST_IMMEDIATE,
        ]);
        $this->createMembership($user, $group2, [
            'emailfrequency' => Membership::EMAIL_DIGEST_IMMEDIATE,
        ]);

        // Run with modulo sharding.
        $this->artisan('mail:digest', [
            'frequency' => -1,
            '--mod' => 2,
            '--val' => 0,
        ])->assertExitCode(0);
    }

    public function test_command_displays_statistics(): void
    {
        $group = $this->createTestGroup();

        $this->artisan('mail:digest', ['frequency' => 1])
            ->expectsTable(['Metric', 'Value'], [
                ['Groups processed', '1'],
                ['Members processed', '0'],
                ['Emails sent', '0'],
                ['Errors', '0'],
            ])
            ->assertExitCode(0);
    }
}
