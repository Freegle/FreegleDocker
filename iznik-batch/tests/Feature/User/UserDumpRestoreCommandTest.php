<?php

namespace Tests\Feature\User;

use App\Models\Membership;
use App\Models\Message;
use App\Models\MessageGroup;
use App\Models\User;
use App\Models\UserEmail;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class UserDumpRestoreCommandTest extends TestCase
{
    private string $dumpFile;

    protected function setUp(): void
    {
        parent::setUp();
        $this->dumpFile = sys_get_temp_dir().'/test-user-dump-'.uniqid().'.json';
    }

    protected function tearDown(): void
    {
        if (file_exists($this->dumpFile)) {
            unlink($this->dumpFile);
        }
        parent::tearDown();
    }

    // -----------------------------------------------------------------------
    // user:dump tests
    // -----------------------------------------------------------------------

    public function test_dump_requires_email(): void
    {
        $this->artisan('user:dump')
            ->expectsOutputToContain('--email is required')
            ->assertExitCode(1);
    }

    public function test_dump_fails_for_unknown_email(): void
    {
        $this->artisan('user:dump', ['--email' => 'nobody@nowhere.invalid'])
            ->expectsOutputToContain('No user found')
            ->assertExitCode(1);
    }

    public function test_dump_creates_json_file(): void
    {
        $user = $this->createTestUser();
        $email = $user->email_preferred;

        $this->artisan('user:dump', [
            '--email'  => $email,
            '--output' => $this->dumpFile,
        ])->assertExitCode(0);

        $this->assertFileExists($this->dumpFile);
        $dump = json_decode(file_get_contents($this->dumpFile), true);
        $this->assertSame(1, $dump['version']);
        $this->assertSame($email, $dump['email']);
        $this->assertSame($user->id, $dump['source_userid']);
        $this->assertArrayHasKey('tables', $dump);
        $this->assertArrayHasKey('users_emails', $dump['tables']);
    }

    public function test_dump_includes_user_attributes(): void
    {
        $user = $this->createTestUser(['fullname' => 'Dump Test User']);
        $email = $user->email_preferred;

        $this->artisan('user:dump', [
            '--email'  => $email,
            '--output' => $this->dumpFile,
        ])->assertExitCode(0);

        $dump = json_decode(file_get_contents($this->dumpFile), true);
        $this->assertSame('Dump Test User', $dump['user']['fullname']);
        $this->assertSame('User', $dump['user']['systemrole']);
    }

    public function test_dump_includes_memberships(): void
    {
        $user = $this->createTestUser();
        $group = $this->createTestGroup();
        $this->createMembership($user, $group);
        $email = $user->email_preferred;

        $this->artisan('user:dump', [
            '--email'  => $email,
            '--output' => $this->dumpFile,
        ])->assertExitCode(0);

        $dump = json_decode(file_get_contents($this->dumpFile), true);
        $this->assertNotEmpty($dump['tables']['memberships']);
        $this->assertSame($user->id, $dump['tables']['memberships'][0]['userid']);
        $this->assertSame($group->id, $dump['tables']['memberships'][0]['groupid']);
    }

    public function test_dump_includes_messages_and_messages_groups(): void
    {
        $user = $this->createTestUser();
        $group = $this->createTestGroup();
        $message = $this->createTestMessage($user, $group);
        $email = $user->email_preferred;

        $this->artisan('user:dump', [
            '--email'  => $email,
            '--output' => $this->dumpFile,
        ])->assertExitCode(0);

        $dump = json_decode(file_get_contents($this->dumpFile), true);
        $this->assertNotEmpty($dump['tables']['messages']);
        $this->assertNotEmpty($dump['tables']['messages_groups']);
        $this->assertSame($message->id, $dump['tables']['messages'][0]['id']);
        $this->assertSame($message->id, $dump['tables']['messages_groups'][0]['msgid']);
    }

    public function test_dump_omits_empty_tables(): void
    {
        $user = $this->createTestUser();
        $email = $user->email_preferred;

        $this->artisan('user:dump', [
            '--email'  => $email,
            '--output' => $this->dumpFile,
        ])->assertExitCode(0);

        $dump = json_decode(file_get_contents($this->dumpFile), true);
        // memberships should be empty for a user with no groups.
        $this->assertEmpty($dump['tables']['memberships']);
    }

    // -----------------------------------------------------------------------
    // user:restore tests
    // -----------------------------------------------------------------------

    public function test_restore_requires_input(): void
    {
        $this->artisan('user:restore')
            ->expectsOutputToContain('--input is required')
            ->assertExitCode(1);
    }

    public function test_restore_fails_for_missing_file(): void
    {
        $this->artisan('user:restore', ['--input' => '/tmp/nonexistent-'.uniqid().'.json'])
            ->expectsOutputToContain('File not found')
            ->assertExitCode(1);
    }

    public function test_restore_fails_for_invalid_json(): void
    {
        file_put_contents($this->dumpFile, '{"version":99}');

        $this->artisan('user:restore', ['--input' => $this->dumpFile])
            ->expectsOutputToContain('Invalid or incompatible dump file')
            ->assertExitCode(1);
    }

    public function test_restore_undeletes_existing_user(): void
    {
        // Create and soft-delete a user.
        $user = $this->createTestUser(['fullname' => 'Restore Me']);
        $email = $user->email_preferred;
        DB::table('users')->where('id', $user->id)->update(['deleted' => now()]);

        // Dump the user.
        $this->artisan('user:dump', [
            '--email'  => $email,
            '--output' => $this->dumpFile,
        ])->assertExitCode(0);

        // Re-delete (simulating what might happen between dump and restore).
        DB::table('users')->where('id', $user->id)->update(['deleted' => now()]);

        // Restore.
        $this->artisan('user:restore', ['--input' => $this->dumpFile])
            ->assertExitCode(0);

        $restored = DB::table('users')->where('id', $user->id)->first();
        $this->assertNull($restored->deleted, 'User should be undeleted after restore');
        $this->assertNull($restored->forgotten, 'forgotten should be cleared after restore');
    }

    public function test_restore_creates_user_if_not_exists(): void
    {
        // Build a dump file manually for a user that doesn't exist on target.
        $dump = [
            'version'       => 1,
            'email'         => 'newuser_'.uniqid().'@example.com',
            'dumped_at'     => now()->toIso8601String(),
            'source_userid' => 9999999,
            'user'          => [
                'fullname'    => 'Brand New User',
                'firstname'   => 'Brand',
                'lastname'    => 'New',
                'yahooid'     => null,
                'systemrole'  => 'User',
                'permissions' => null,
            ],
            'tables' => array_fill_keys([
                'memberships', 'spam_users', 'users_banned', 'users_donations',
                'microactions', 'giftaid', 'users_logins', 'users_emails',
                'users_comments', 'sessions', 'messages', 'users_push_notifications',
                'users_notifications', 'chat_rooms', 'chat_roster', 'chat_messages',
                'users_searches', 'memberships_history', 'logs', 'logs_sql',
                'newsfeed', 'messages_groups',
            ], []),
        ];

        file_put_contents($this->dumpFile, json_encode($dump));

        $this->artisan('user:restore', ['--input' => $this->dumpFile])
            ->assertExitCode(0);

        $emailRecord = DB::table('users_emails')->where('email', $dump['email'])->first();
        $this->assertNotNull($emailRecord, 'User email should be created');

        $user = DB::table('users')->where('id', $emailRecord->userid)->first();
        $this->assertSame('Brand New User', $user->fullname);
        $this->assertNull($user->deleted);
    }

    public function test_restore_preserves_memberships(): void
    {
        $user = $this->createTestUser();
        $group = $this->createTestGroup();
        $this->createMembership($user, $group);
        $email = $user->email_preferred;

        // Dump.
        $this->artisan('user:dump', [
            '--email'  => $email,
            '--output' => $this->dumpFile,
        ])->assertExitCode(0);

        // Remove membership.
        DB::table('memberships')->where('userid', $user->id)->delete();
        DB::table('users')->where('id', $user->id)->update(['deleted' => now()]);

        // Restore.
        $this->artisan('user:restore', ['--input' => $this->dumpFile])
            ->assertExitCode(0);

        $membership = DB::table('memberships')
            ->where('userid', $user->id)
            ->where('groupid', $group->id)
            ->first();
        $this->assertNotNull($membership, 'Membership should be restored');
    }

    public function test_restore_restores_messages_groups(): void
    {
        $user = $this->createTestUser();
        $group = $this->createTestGroup();
        $message = $this->createTestMessage($user, $group);
        $email = $user->email_preferred;

        // Dump.
        $this->artisan('user:dump', [
            '--email'  => $email,
            '--output' => $this->dumpFile,
        ])->assertExitCode(0);

        // Soft-delete the message group entry.
        DB::table('messages_groups')
            ->where('msgid', $message->id)
            ->update(['deleted' => 1]);

        // Restore.
        $this->artisan('user:restore', ['--input' => $this->dumpFile])
            ->assertExitCode(0);

        $mg = DB::table('messages_groups')->where('msgid', $message->id)->first();
        $this->assertSame(0, (int) $mg->deleted, 'messages_groups deleted flag should be restored');
    }

    public function test_dry_run_makes_no_changes(): void
    {
        $user = $this->createTestUser();
        $email = $user->email_preferred;
        DB::table('users')->where('id', $user->id)->update(['deleted' => now()]);

        // Dump.
        $this->artisan('user:dump', [
            '--email'  => $email,
            '--output' => $this->dumpFile,
        ])->assertExitCode(0);

        // Restore with dry-run.
        $this->artisan('user:restore', [
            '--input'   => $this->dumpFile,
            '--dry-run' => true,
        ])
            ->expectsOutputToContain('[DRY RUN]')
            ->assertExitCode(0);

        // User should still be deleted.
        $u = DB::table('users')->where('id', $user->id)->first();
        $this->assertNotNull($u->deleted, 'Dry run should not undelete user');
    }
}
