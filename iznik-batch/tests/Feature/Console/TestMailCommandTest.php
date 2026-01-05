<?php

namespace Tests\Feature\Console;

use App\Models\UserEmail;
use Tests\TestCase;

class TestMailCommandTest extends TestCase
{
    protected string $spoolDir;

    protected function setUp(): void
    {
        parent::setUp();

        $this->spoolDir = storage_path('spool/mail');

        // Clear spool directories before each test.
        foreach (['pending', 'sending', 'sent', 'failed'] as $subdir) {
            $dir = $this->spoolDir . '/' . $subdir;
            if (is_dir($dir)) {
                array_map('unlink', glob($dir . '/*.json'));
            }
        }

        // Array mail driver (set in phpunit.xml) prevents actual sending.
        // Don't use Mail::fake() here - it interferes with the spooler's Mail::html() call.
    }

    /**
     * Test that --send-to option overrides the delivery address.
     */
    public function test_send_to_option_overrides_delivery_address(): void
    {
        // Create test users using helper methods.
        $recipient = $this->createTestUser(['displayname' => 'Test Recipient']);
        $sender = $this->createTestUser(['displayname' => 'Test Sender']);

        // Update recipient's email to a known address.
        UserEmail::where('userid', $recipient->id)->update(['email' => 'original@example.com']);
        $recipient = $recipient->fresh();

        // Create a chat room.
        $chatRoom = $this->createTestChatRoom($recipient, $sender);

        // Create a chat message from sender to recipient.
        $this->createTestChatMessage($chatRoom, $sender, [
            'message' => 'Hello, this is a test message!',
        ]);

        // Run the command with --send-to override.
        $this->artisan('mail:test', [
            'type' => 'chat:user2user',
            '--to' => 'original@example.com',
            '--send-to' => 'override@example.com',
            '--chat' => $chatRoom->id,
        ])->assertExitCode(0);

        // Check spool file for the delivery address.
        $spoolFiles = glob($this->spoolDir . '/sent/*.json');
        $this->assertNotEmpty($spoolFiles, 'Should have a spool file in sent directory');

        $spoolData = json_decode(file_get_contents($spoolFiles[0]), true);
        $toAddresses = array_column($spoolData['to'], 'address');

        // Verify the override address was used, not the original.
        $this->assertContains('override@example.com', $toAddresses);
        $this->assertNotContains('original@example.com', $toAddresses);
    }

    /**
     * Test that without --send-to, the original address is used.
     */
    public function test_without_send_to_uses_original_address(): void
    {
        // Create test users using helper methods.
        $recipient = $this->createTestUser(['displayname' => 'Test Recipient']);
        $sender = $this->createTestUser(['displayname' => 'Test Sender']);

        // Update recipient's email to a known address.
        UserEmail::where('userid', $recipient->id)->update(['email' => 'original@example.com']);
        $recipient = $recipient->fresh();

        // Create a chat room.
        $chatRoom = $this->createTestChatRoom($recipient, $sender);

        // Create a chat message from sender to recipient.
        $this->createTestChatMessage($chatRoom, $sender, [
            'message' => 'Hello, this is a test message!',
        ]);

        // Run the command WITHOUT --send-to.
        $this->artisan('mail:test', [
            'type' => 'chat:user2user',
            '--to' => 'original@example.com',
            '--chat' => $chatRoom->id,
        ])->assertExitCode(0);

        // Check spool file for the delivery address.
        $spoolFiles = glob($this->spoolDir . '/sent/*.json');
        $this->assertNotEmpty($spoolFiles, 'Should have a spool file in sent directory');

        $spoolData = json_decode(file_get_contents($spoolFiles[0]), true);
        $toAddresses = array_column($spoolData['to'], 'address');

        // Verify the original address was used.
        $this->assertContains('original@example.com', $toAddresses);
    }

    /**
     * Test that --send-to properly clears and replaces the mailable's to address.
     *
     * This is the key regression test - the mailable builds with the recipient's
     * real address in the content, but --send-to should override where it's delivered.
     */
    public function test_send_to_replaces_mailable_to_address(): void
    {
        // Create test users using helper methods.
        $recipient = $this->createTestUser(['displayname' => 'Real User']);
        $sender = $this->createTestUser(['displayname' => 'Test Sender']);

        // Update recipient's email to a known address.
        UserEmail::where('userid', $recipient->id)->update(['email' => 'realuser@example.com']);
        $recipient = $recipient->fresh();

        // Create a chat room.
        $chatRoom = $this->createTestChatRoom($recipient, $sender);

        // Create a chat message from sender to recipient.
        $this->createTestChatMessage($chatRoom, $sender, [
            'message' => 'Test message for address override test',
        ]);

        // Run the command with --send-to to a different address.
        $this->artisan('mail:test', [
            'type' => 'chat:user2user',
            '--to' => 'realuser@example.com',
            '--send-to' => 'testdelivery@example.com',
            '--chat' => $chatRoom->id,
        ])->assertExitCode(0);

        // Check spool file for the delivery address.
        $spoolFiles = glob($this->spoolDir . '/sent/*.json');
        $this->assertNotEmpty($spoolFiles, 'Should have a spool file in sent directory');

        $spoolData = json_decode(file_get_contents($spoolFiles[0]), true);
        $toAddresses = array_column($spoolData['to'], 'address');

        // Verify the override address was used.
        $this->assertContains('testdelivery@example.com', $toAddresses);
        $this->assertNotContains('realuser@example.com', $toAddresses);

        // Verify the email content still references the real user (not the override).
        // The HTML should contain the sender's name (Test User from createTestUser helper).
        $this->assertStringContainsString('Test User', $spoolData['html']);
    }
}
