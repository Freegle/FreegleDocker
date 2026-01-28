<?php

namespace Tests\Unit\Services\Mail\Incoming;

use App\Services\Mail\Incoming\IncomingMailService;
use App\Services\Mail\Incoming\MailParserService;
use App\Services\Mail\Incoming\RoutingResult;
use Illuminate\Support\Facades\DB;
use Tests\Support\EmailFixtures;
use Tests\TestCase;

/**
 * Tests for IncomingMailService routing logic.
 *
 * These tests verify that incoming emails are routed correctly based on
 * the envelope-to address, message content, and sender status.
 */
class IncomingMailServiceTest extends TestCase
{
    use EmailFixtures;

    private IncomingMailService $service;

    private MailParserService $parser;

    protected function setUp(): void
    {
        parent::setUp();
        $this->parser = app(MailParserService::class);
        $this->service = app(IncomingMailService::class);
    }

    // ========================================
    // System Address Routing Tests
    // ========================================

    public function test_routes_digestoff_to_system(): void
    {
        $user = $this->createTestUser(['email_preferred' => $this->uniqueEmail('member')]);
        $group = $this->createTestGroup();
        $this->createMembership($user, $group);
        $userEmail = $user->emails->first()->email;

        $email = $this->createMinimalEmail([
            'From' => $userEmail,
            'To' => "digestoff-{$user->id}-{$group->id}@users.ilovefreegle.org",
            'Subject' => 'Turn off digest',
        ]);

        $parsed = $this->parser->parse(
            $email,
            $userEmail,
            "digestoff-{$user->id}-{$group->id}@users.ilovefreegle.org"
        );

        $result = $this->service->route($parsed);

        $this->assertEquals(RoutingResult::TO_SYSTEM, $result);
    }

    public function test_routes_digestoff_to_dropped_when_user_not_found(): void
    {
        $email = $this->createMinimalEmail([
            'From' => 'user@example.com',
            'To' => 'digestoff-99999999-88888888@users.ilovefreegle.org',
            'Subject' => 'Turn off digest',
        ]);

        $parsed = $this->parser->parse(
            $email,
            'user@example.com',
            'digestoff-99999999-88888888@users.ilovefreegle.org'
        );

        $result = $this->service->route($parsed);

        $this->assertEquals(RoutingResult::DROPPED, $result);
    }

    public function test_routes_readreceipt_to_receipt(): void
    {
        $user1 = $this->createTestUser(['email_preferred' => $this->uniqueEmail('user1')]);
        $user2 = $this->createTestUser(['email_preferred' => $this->uniqueEmail('user2')]);
        $chat = $this->createTestChatRoom($user1, $user2);
        $msg = $this->createTestChatMessage($chat, $user2, ['message' => 'Test message']);
        $user1Email = $user1->emails->first()->email;

        $email = $this->createMinimalEmail([
            'From' => $user1Email,
            'To' => "readreceipt-{$chat->id}-{$user1->id}-{$msg->id}@users.ilovefreegle.org",
            'Subject' => 'Read receipt',
        ]);

        $parsed = $this->parser->parse(
            $email,
            $user1Email,
            "readreceipt-{$chat->id}-{$user1->id}-{$msg->id}@users.ilovefreegle.org"
        );

        $result = $this->service->route($parsed);

        $this->assertEquals(RoutingResult::RECEIPT, $result);
    }

    public function test_routes_readreceipt_to_dropped_when_chat_not_found(): void
    {
        $email = $this->createMinimalEmail([
            'From' => 'user@example.com',
            'To' => 'readreceipt-99999999-88888888-77777777@users.ilovefreegle.org',
            'Subject' => 'Read receipt',
        ]);

        $parsed = $this->parser->parse(
            $email,
            'user@example.com',
            'readreceipt-99999999-88888888-77777777@users.ilovefreegle.org'
        );

        $result = $this->service->route($parsed);

        $this->assertEquals(RoutingResult::DROPPED, $result);
    }

    public function test_routes_subscribe_to_system(): void
    {
        $group = $this->createTestGroup();

        $email = $this->createMinimalEmail([
            'From' => 'newuser@example.com',
            'To' => $group->nameshort.'-subscribe@groups.ilovefreegle.org',
            'Subject' => 'Subscribe',
        ]);

        $parsed = $this->parser->parse(
            $email,
            'newuser@example.com',
            $group->nameshort.'-subscribe@groups.ilovefreegle.org'
        );

        $result = $this->service->route($parsed);

        $this->assertEquals(RoutingResult::TO_SYSTEM, $result);
    }

    public function test_routes_unsubscribe_to_system(): void
    {
        $group = $this->createTestGroup();
        $user = $this->createTestUser(['email_preferred' => $this->uniqueEmail('member')]);
        $this->createMembership($user, $group);
        $memberEmail = $user->emails->first()->email;

        $email = $this->createMinimalEmail([
            'From' => $memberEmail,
            'To' => $group->nameshort.'-unsubscribe@groups.ilovefreegle.org',
            'Subject' => 'Unsubscribe',
        ]);

        $parsed = $this->parser->parse(
            $email,
            $memberEmail,
            $group->nameshort.'-unsubscribe@groups.ilovefreegle.org'
        );

        $result = $this->service->route($parsed);

        $this->assertEquals(RoutingResult::TO_SYSTEM, $result);
    }

    public function test_routes_unsubscribe_to_dropped_when_not_member(): void
    {
        $group = $this->createTestGroup();

        $email = $this->createMinimalEmail([
            'From' => 'unknown@example.com',
            'To' => $group->nameshort.'-unsubscribe@groups.ilovefreegle.org',
            'Subject' => 'Unsubscribe',
        ]);

        $parsed = $this->parser->parse(
            $email,
            'unknown@example.com',
            $group->nameshort.'-unsubscribe@groups.ilovefreegle.org'
        );

        $result = $this->service->route($parsed);

        $this->assertEquals(RoutingResult::DROPPED, $result);
    }

    public function test_routes_oneclick_unsubscribe_to_system(): void
    {
        $user = $this->createTestUser(['email_preferred' => $this->uniqueEmail('unsub')]);
        $userEmail = $user->emails->first()->email;

        // Create a Link login record with a key for the user
        $key = 'validkey123';
        DB::table('users_logins')->insert([
            'userid' => $user->id,
            'type' => 'Link',
            'credentials' => $key,
            'added' => now(),
            'lastaccess' => now(),
        ]);

        $email = $this->createMinimalEmail([
            'From' => $userEmail,
            'To' => "unsubscribe-{$user->id}-{$key}-digest@users.ilovefreegle.org",
            'Subject' => 'One-click unsubscribe',
        ]);

        $parsed = $this->parser->parse(
            $email,
            $userEmail,
            "unsubscribe-{$user->id}-{$key}-digest@users.ilovefreegle.org"
        );

        $result = $this->service->route($parsed);

        $this->assertEquals(RoutingResult::TO_SYSTEM, $result);
    }

    public function test_routes_oneclick_unsubscribe_to_dropped_with_invalid_key(): void
    {
        $user = $this->createTestUser(['email_preferred' => $this->uniqueEmail('unsub')]);
        $userEmail = $user->emails->first()->email;

        // Create a Link login record with a different key
        DB::table('users_logins')->insert([
            'userid' => $user->id,
            'type' => 'Link',
            'credentials' => 'realkey123',
            'added' => now(),
            'lastaccess' => now(),
        ]);

        $email = $this->createMinimalEmail([
            'From' => $userEmail,
            'To' => "unsubscribe-{$user->id}-wrongkey-digest@users.ilovefreegle.org",
            'Subject' => 'One-click unsubscribe',
        ]);

        $parsed = $this->parser->parse(
            $email,
            $userEmail,
            "unsubscribe-{$user->id}-wrongkey-digest@users.ilovefreegle.org"
        );

        $result = $this->service->route($parsed);

        $this->assertEquals(RoutingResult::DROPPED, $result);
    }

    public function test_routes_eventsoff_to_system(): void
    {
        $user = $this->createTestUser(['email_preferred' => $this->uniqueEmail('member')]);
        $group = $this->createTestGroup();
        $this->createMembership($user, $group);
        $userEmail = $user->emails->first()->email;

        $email = $this->createMinimalEmail([
            'From' => $userEmail,
            'To' => "eventsoff-{$user->id}-{$group->id}@users.ilovefreegle.org",
            'Subject' => 'Turn off events',
        ]);

        $parsed = $this->parser->parse(
            $email,
            $userEmail,
            "eventsoff-{$user->id}-{$group->id}@users.ilovefreegle.org"
        );

        $result = $this->service->route($parsed);

        $this->assertEquals(RoutingResult::TO_SYSTEM, $result);
    }

    public function test_routes_eventsoff_to_dropped_when_not_member(): void
    {
        $email = $this->createMinimalEmail([
            'From' => 'user@example.com',
            'To' => 'eventsoff-99999999-88888888@users.ilovefreegle.org',
            'Subject' => 'Turn off events',
        ]);

        $parsed = $this->parser->parse(
            $email,
            'user@example.com',
            'eventsoff-99999999-88888888@users.ilovefreegle.org'
        );

        $result = $this->service->route($parsed);

        $this->assertEquals(RoutingResult::DROPPED, $result);
    }

    public function test_routes_newslettersoff_to_system(): void
    {
        $user = $this->createTestUser(['email_preferred' => $this->uniqueEmail('member')]);
        $userEmail = $user->emails->first()->email;

        $email = $this->createMinimalEmail([
            'From' => $userEmail,
            'To' => "newslettersoff-{$user->id}@users.ilovefreegle.org",
            'Subject' => 'Turn off newsletters',
        ]);

        $parsed = $this->parser->parse(
            $email,
            $userEmail,
            "newslettersoff-{$user->id}@users.ilovefreegle.org"
        );

        $result = $this->service->route($parsed);

        $this->assertEquals(RoutingResult::TO_SYSTEM, $result);
    }

    public function test_routes_newslettersoff_to_dropped_when_user_not_found(): void
    {
        $email = $this->createMinimalEmail([
            'From' => 'user@example.com',
            'To' => 'newslettersoff-99999999@users.ilovefreegle.org',
            'Subject' => 'Turn off newsletters',
        ]);

        $parsed = $this->parser->parse(
            $email,
            'user@example.com',
            'newslettersoff-99999999@users.ilovefreegle.org'
        );

        $result = $this->service->route($parsed);

        $this->assertEquals(RoutingResult::DROPPED, $result);
    }

    public function test_routes_relevantoff_to_system(): void
    {
        $user = $this->createTestUser(['email_preferred' => $this->uniqueEmail('member')]);
        $userEmail = $user->emails->first()->email;

        $email = $this->createMinimalEmail([
            'From' => $userEmail,
            'To' => "relevantoff-{$user->id}@users.ilovefreegle.org",
            'Subject' => 'Turn off relevant',
        ]);

        $parsed = $this->parser->parse(
            $email,
            $userEmail,
            "relevantoff-{$user->id}@users.ilovefreegle.org"
        );

        $result = $this->service->route($parsed);

        $this->assertEquals(RoutingResult::TO_SYSTEM, $result);
    }

    public function test_routes_relevantoff_to_dropped_when_user_not_found(): void
    {
        $email = $this->createMinimalEmail([
            'From' => 'user@example.com',
            'To' => 'relevantoff-99999999@users.ilovefreegle.org',
            'Subject' => 'Turn off relevant',
        ]);

        $parsed = $this->parser->parse(
            $email,
            'user@example.com',
            'relevantoff-99999999@users.ilovefreegle.org'
        );

        $result = $this->service->route($parsed);

        $this->assertEquals(RoutingResult::DROPPED, $result);
    }

    public function test_routes_volunteeringoff_to_system(): void
    {
        $user = $this->createTestUser(['email_preferred' => $this->uniqueEmail('member')]);
        $group = $this->createTestGroup();
        $this->createMembership($user, $group);
        $userEmail = $user->emails->first()->email;

        $email = $this->createMinimalEmail([
            'From' => $userEmail,
            'To' => "volunteeringoff-{$user->id}-{$group->id}@users.ilovefreegle.org",
            'Subject' => 'Turn off volunteering',
        ]);

        $parsed = $this->parser->parse(
            $email,
            $userEmail,
            "volunteeringoff-{$user->id}-{$group->id}@users.ilovefreegle.org"
        );

        $result = $this->service->route($parsed);

        $this->assertEquals(RoutingResult::TO_SYSTEM, $result);
    }

    public function test_routes_volunteeringoff_to_dropped_when_not_member(): void
    {
        $email = $this->createMinimalEmail([
            'From' => 'user@example.com',
            'To' => 'volunteeringoff-99999999-88888888@users.ilovefreegle.org',
            'Subject' => 'Turn off volunteering',
        ]);

        $parsed = $this->parser->parse(
            $email,
            'user@example.com',
            'volunteeringoff-99999999-88888888@users.ilovefreegle.org'
        );

        $result = $this->service->route($parsed);

        $this->assertEquals(RoutingResult::DROPPED, $result);
    }

    public function test_routes_notificationmailsoff_to_system(): void
    {
        $user = $this->createTestUser(['email_preferred' => $this->uniqueEmail('member')]);
        $userEmail = $user->emails->first()->email;

        $email = $this->createMinimalEmail([
            'From' => $userEmail,
            'To' => "notificationmailsoff-{$user->id}@users.ilovefreegle.org",
            'Subject' => 'Turn off notification mails',
        ]);

        $parsed = $this->parser->parse(
            $email,
            $userEmail,
            "notificationmailsoff-{$user->id}@users.ilovefreegle.org"
        );

        $result = $this->service->route($parsed);

        $this->assertEquals(RoutingResult::TO_SYSTEM, $result);
    }

    public function test_routes_notificationmailsoff_to_dropped_when_user_not_found(): void
    {
        $email = $this->createMinimalEmail([
            'From' => 'user@example.com',
            'To' => 'notificationmailsoff-99999999@users.ilovefreegle.org',
            'Subject' => 'Turn off notification mails',
        ]);

        $parsed = $this->parser->parse(
            $email,
            'user@example.com',
            'notificationmailsoff-99999999@users.ilovefreegle.org'
        );

        $result = $this->service->route($parsed);

        $this->assertEquals(RoutingResult::DROPPED, $result);
    }

    public function test_routes_fbl_to_system(): void
    {
        $email = $this->createMinimalEmail([
            'From' => 'fbl@hotmail.com',
            'To' => 'fbl@ilovefreegle.org',
            'Subject' => 'Feedback Loop Report',
        ]);

        $parsed = $this->parser->parse(
            $email,
            'fbl@hotmail.com',
            'fbl@ilovefreegle.org'
        );

        $result = $this->service->route($parsed);

        $this->assertEquals(RoutingResult::TO_SYSTEM, $result);
    }

    // ========================================
    // Volunteer/Auto Address Routing Tests
    // ========================================

    public function test_routes_volunteers_address_to_volunteers(): void
    {
        $group = $this->createTestGroup();
        $user = $this->createTestUser(['email_preferred' => $this->uniqueEmail('member')]);
        $this->createMembership($user, $group);

        $memberEmail = $user->emails->first()->email;

        $email = $this->createMinimalEmail([
            'From' => $memberEmail,
            'To' => $group->nameshort.'-volunteers@groups.ilovefreegle.org',
            'Subject' => 'Question for volunteers',
        ], 'Hi, I have a question about posting...');

        $parsed = $this->parser->parse(
            $email,
            $memberEmail,
            $group->nameshort.'-volunteers@groups.ilovefreegle.org'
        );

        $result = $this->service->route($parsed);

        $this->assertEquals(RoutingResult::TO_VOLUNTEERS, $result);
    }

    public function test_routes_auto_address_to_volunteers(): void
    {
        $group = $this->createTestGroup();
        $user = $this->createTestUser(['email_preferred' => $this->uniqueEmail('member')]);
        $this->createMembership($user, $group);

        $memberEmail = $user->emails->first()->email;

        $email = $this->createMinimalEmail([
            'From' => $memberEmail,
            'To' => $group->nameshort.'-auto@groups.ilovefreegle.org',
            'Subject' => 'Auto message',
        ], 'This is an automated message...');

        $parsed = $this->parser->parse(
            $email,
            $memberEmail,
            $group->nameshort.'-auto@groups.ilovefreegle.org'
        );

        $result = $this->service->route($parsed);

        $this->assertEquals(RoutingResult::TO_VOLUNTEERS, $result);
    }

    // ========================================
    // Bounce Handling Tests
    // ========================================

    public function test_routes_bounce_to_dropped(): void
    {
        $bounceEmail = $this->createBounceEmail(
            'recipient@example.com',
            '5.1.1',
            'User unknown'
        );

        $parsed = $this->parser->parse(
            $bounceEmail,
            'MAILER-DAEMON@ilovefreegle.org',
            'bounce-12345-67890@users.ilovefreegle.org'
        );

        $result = $this->service->route($parsed);

        // Bounces are processed (user may be suspended) but message is dropped
        $this->assertEquals(RoutingResult::DROPPED, $result);
    }

    public function test_permanent_bounce_suspends_user_email(): void
    {
        $user = $this->createTestUser(['email_preferred' => $this->uniqueEmail('bounced')]);
        $userEmail = $user->emails->first();

        $bounceEmail = $this->createBounceEmail(
            $userEmail->email,
            '5.1.1',
            'User unknown'
        );

        $parsed = $this->parser->parse(
            $bounceEmail,
            'MAILER-DAEMON@ilovefreegle.org',
            'bounce-'.$user->id.'-12345@users.ilovefreegle.org'
        );

        $this->service->route($parsed);

        // Refresh and check the user's email is now marked as bounced
        $userEmail->refresh();
        $this->assertNotNull($userEmail->bounced, 'Email should be marked as bounced');
    }

    // ========================================
    // Chat Reply Routing Tests
    // ========================================

    public function test_routes_chat_notification_reply_to_user(): void
    {
        $user1 = $this->createTestUser(['email_preferred' => $this->uniqueEmail('user1')]);
        $user2 = $this->createTestUser(['email_preferred' => $this->uniqueEmail('user2')]);
        $chat = $this->createTestChatRoom($user1, $user2);

        $user1Email = $user1->emails->first()->email;

        $email = $this->createMinimalEmail([
            'From' => $user1Email,
            'To' => "notify-{$chat->id}-{$user1->id}@users.ilovefreegle.org",
            'Subject' => 'Re: About the item',
        ], 'Yes, it is still available!');

        $parsed = $this->parser->parse(
            $email,
            $user1Email,
            "notify-{$chat->id}-{$user1->id}@users.ilovefreegle.org"
        );

        $result = $this->service->route($parsed);

        $this->assertEquals(RoutingResult::TO_USER, $result);
    }

    public function test_routes_chat_reply_with_msgid_to_user(): void
    {
        $user1 = $this->createTestUser(['email_preferred' => $this->uniqueEmail('user1')]);
        $user2 = $this->createTestUser(['email_preferred' => $this->uniqueEmail('user2')]);
        $chat = $this->createTestChatRoom($user1, $user2);
        $msg = $this->createTestChatMessage($chat, $user2, ['message' => 'Is this available?']);

        $user1Email = $user1->emails->first()->email;

        $email = $this->createMinimalEmail([
            'From' => $user1Email,
            'To' => "notify-{$chat->id}-{$user1->id}-{$msg->id}@users.ilovefreegle.org",
            'Subject' => 'Re: About the item',
        ], 'Yes, still available!');

        $parsed = $this->parser->parse(
            $email,
            $user1Email,
            "notify-{$chat->id}-{$user1->id}-{$msg->id}@users.ilovefreegle.org"
        );

        $result = $this->service->route($parsed);

        $this->assertEquals(RoutingResult::TO_USER, $result);
    }

    public function test_routes_replyto_address_to_user(): void
    {
        $poster = $this->createTestUser(['email_preferred' => $this->uniqueEmail('poster')]);
        $replier = $this->createTestUser(['email_preferred' => $this->uniqueEmail('replier')]);
        $group = $this->createTestGroup();
        $this->createMembership($poster, $group);
        $message = $this->createTestMessage($poster, $group);

        $replierEmail = $replier->emails->first()->email;

        $email = $this->createMinimalEmail([
            'From' => $replierEmail,
            'To' => "replyto-{$message->id}-{$replier->id}@users.ilovefreegle.org",
            'Subject' => 'Re: '.$message->subject,
        ], 'Is this still available?');

        $parsed = $this->parser->parse(
            $email,
            $replierEmail,
            "replyto-{$message->id}-{$replier->id}@users.ilovefreegle.org"
        );

        $result = $this->service->route($parsed);

        $this->assertEquals(RoutingResult::TO_USER, $result);
    }

    public function test_chat_reply_creates_chat_message(): void
    {
        $user1 = $this->createTestUser(['email_preferred' => $this->uniqueEmail('user1')]);
        $user2 = $this->createTestUser(['email_preferred' => $this->uniqueEmail('user2')]);
        $chat = $this->createTestChatRoom($user1, $user2);

        $user1Email = $user1->emails->first()->email;

        $email = $this->createMinimalEmail([
            'From' => $user1Email,
            'To' => "notify-{$chat->id}-{$user1->id}@users.ilovefreegle.org",
            'Subject' => 'Re: About the item',
        ], 'The test reply message content');

        $parsed = $this->parser->parse(
            $email,
            $user1Email,
            "notify-{$chat->id}-{$user1->id}@users.ilovefreegle.org"
        );

        $initialCount = DB::table('chat_messages')
            ->where('chatid', $chat->id)
            ->count();

        $this->service->route($parsed);

        $finalCount = DB::table('chat_messages')
            ->where('chatid', $chat->id)
            ->count();

        $this->assertEquals($initialCount + 1, $finalCount);

        // Verify the message content
        $lastMessage = DB::table('chat_messages')
            ->where('chatid', $chat->id)
            ->orderBy('id', 'desc')
            ->first();

        $this->assertStringContainsString('The test reply message content', $lastMessage->message);
    }

    // ========================================
    // Auto-Reply Handling Tests
    // ========================================

    public function test_drops_auto_reply_messages(): void
    {
        $email = $this->createMinimalEmail([
            'From' => 'user@example.com',
            'To' => 'someone@users.ilovefreegle.org',
            'Subject' => 'Out of Office: Re: Your message',
            'Auto-Submitted' => 'auto-replied',
        ], 'I am out of the office until...');

        $parsed = $this->parser->parse(
            $email,
            'user@example.com',
            'someone@users.ilovefreegle.org'
        );

        $result = $this->service->route($parsed);

        $this->assertEquals(RoutingResult::DROPPED, $result);
    }

    public function test_drops_vacation_auto_reply(): void
    {
        $email = $this->createMinimalEmail([
            'From' => 'user@example.com',
            'To' => 'someone@users.ilovefreegle.org',
            'Subject' => 'Automatic reply: Your message',
            'Auto-Submitted' => 'auto-generated',
            'X-Auto-Response-Suppress' => 'All',
        ], 'Thank you for your message...');

        $parsed = $this->parser->parse(
            $email,
            'user@example.com',
            'someone@users.ilovefreegle.org'
        );

        $result = $this->service->route($parsed);

        $this->assertEquals(RoutingResult::DROPPED, $result);
    }

    // ========================================
    // Group Post Routing Tests
    // ========================================

    public function test_routes_approved_member_post_to_approved(): void
    {
        $group = $this->createTestGroup();
        $user = $this->createTestUser(['email_preferred' => $this->uniqueEmail('member')]);
        $this->createMembership($user, $group, [
            'ourPostingStatus' => 'DEFAULT',
        ]);
        // Set user location
        DB::table('users')->where('id', $user->id)->update([
            'lastlocation' => $this->createLocation(51.5, -0.1),
        ]);

        $userEmail = $user->emails->first()->email;

        $email = $this->createMinimalEmail([
            'From' => $userEmail,
            'To' => $group->nameshort.'@groups.ilovefreegle.org',
            'Subject' => 'OFFER: Test Item (London)',
        ], 'Free test item, collection only.');

        $parsed = $this->parser->parse(
            $email,
            $userEmail,
            $group->nameshort.'@groups.ilovefreegle.org'
        );

        $result = $this->service->route($parsed);

        $this->assertEquals(RoutingResult::APPROVED, $result);
    }

    public function test_routes_moderated_member_post_to_pending(): void
    {
        $group = $this->createTestGroup();
        $user = $this->createTestUser(['email_preferred' => $this->uniqueEmail('moderated')]);
        $this->createMembership($user, $group, [
            'ourPostingStatus' => 'MODERATED',
        ]);
        // Set user location
        DB::table('users')->where('id', $user->id)->update([
            'lastlocation' => $this->createLocation(51.5, -0.1),
        ]);

        $userEmail = $user->emails->first()->email;

        $email = $this->createMinimalEmail([
            'From' => $userEmail,
            'To' => $group->nameshort.'@groups.ilovefreegle.org',
            'Subject' => 'OFFER: Test Item (London)',
        ], 'Free test item, collection only.');

        $parsed = $this->parser->parse(
            $email,
            $userEmail,
            $group->nameshort.'@groups.ilovefreegle.org'
        );

        $result = $this->service->route($parsed);

        $this->assertEquals(RoutingResult::PENDING, $result);
    }

    public function test_routes_non_member_post_to_dropped(): void
    {
        $group = $this->createTestGroup();
        $user = $this->createTestUser(['email_preferred' => $this->uniqueEmail('nonmember')]);
        // Don't add user to group

        $userEmail = $user->emails->first()->email;

        $email = $this->createMinimalEmail([
            'From' => $userEmail,
            'To' => $group->nameshort.'@groups.ilovefreegle.org',
            'Subject' => 'OFFER: Test Item (London)',
        ], 'Free test item, collection only.');

        $parsed = $this->parser->parse(
            $email,
            $userEmail,
            $group->nameshort.'@groups.ilovefreegle.org'
        );

        $result = $this->service->route($parsed);

        // Non-members get rejection email and message is dropped
        $this->assertEquals(RoutingResult::DROPPED, $result);
    }

    public function test_routes_unmapped_user_post_to_pending(): void
    {
        $group = $this->createTestGroup();
        $user = $this->createTestUser(['email_preferred' => $this->uniqueEmail('unmapped')]);
        $this->createMembership($user, $group, [
            'ourPostingStatus' => 'DEFAULT',
        ]);
        // Don't set location - user is unmapped

        $userEmail = $user->emails->first()->email;

        $email = $this->createMinimalEmail([
            'From' => $userEmail,
            'To' => $group->nameshort.'@groups.ilovefreegle.org',
            'Subject' => 'OFFER: Test Item (London)',
        ], 'Free test item, collection only.');

        $parsed = $this->parser->parse(
            $email,
            $userEmail,
            $group->nameshort.'@groups.ilovefreegle.org'
        );

        $result = $this->service->route($parsed);

        $this->assertEquals(RoutingResult::PENDING, $result);
    }

    public function test_routes_worry_word_post_to_pending(): void
    {
        $group = $this->createTestGroup();
        $user = $this->createTestUser(['email_preferred' => $this->uniqueEmail('member')]);
        $this->createMembership($user, $group, [
            'ourPostingStatus' => 'DEFAULT',
        ]);
        // Set user location
        DB::table('users')->where('id', $user->id)->update([
            'lastlocation' => $this->createLocation(51.5, -0.1),
        ]);

        // Add worry word to database
        DB::table('worrywords')->insert([
            'keyword' => 'kitten',
            'type' => 'Review',
        ]);

        $userEmail = $user->emails->first()->email;

        // Use a worry word in the subject
        $email = $this->createMinimalEmail([
            'From' => $userEmail,
            'To' => $group->nameshort.'@groups.ilovefreegle.org',
            'Subject' => 'OFFER: Free kitten (London)',
        ], 'Adorable kitten needs a good home.');

        $parsed = $this->parser->parse(
            $email,
            $userEmail,
            $group->nameshort.'@groups.ilovefreegle.org'
        );

        $result = $this->service->route($parsed);

        // Worry words cause posts to be held for review
        $this->assertEquals(RoutingResult::PENDING, $result);
    }

    // ========================================
    // Spam Detection Tests
    // ========================================

    public function test_routes_spam_to_incoming_spam(): void
    {
        $group = $this->createTestGroup();
        $user = $this->createTestUser(['email_preferred' => $this->uniqueEmail('spammer')]);
        $this->createMembership($user, $group, [
            'ourPostingStatus' => 'DEFAULT',
        ]);
        // Set user location
        DB::table('users')->where('id', $user->id)->update([
            'lastlocation' => $this->createLocation(51.5, -0.1),
        ]);

        $userEmail = $user->emails->first()->email;

        // Use known spam patterns
        $email = $this->createMinimalEmail([
            'From' => $userEmail,
            'To' => $group->nameshort.'@groups.ilovefreegle.org',
            'Subject' => 'OFFER: Make money fast! (London)',
        ], 'Send money to this account for guaranteed returns! Western Union accepted.');

        $parsed = $this->parser->parse(
            $email,
            $userEmail,
            $group->nameshort.'@groups.ilovefreegle.org'
        );

        $result = $this->service->route($parsed);

        $this->assertEquals(RoutingResult::INCOMING_SPAM, $result);
    }

    public function test_routes_spam_keyword_from_database_to_incoming_spam(): void
    {
        $group = $this->createTestGroup();
        $user = $this->createTestUser(['email_preferred' => $this->uniqueEmail('spamkw')]);
        $this->createMembership($user, $group, [
            'ourPostingStatus' => 'DEFAULT',
        ]);
        DB::table('users')->where('id', $user->id)->update([
            'lastlocation' => $this->createLocation(51.5, -0.1),
        ]);

        // Insert a spam keyword into the database (matching legacy behavior)
        DB::table('spam_keywords')->insert([
            'word' => 'weight loss',
            'action' => 'Spam',
            'type' => 'Literal',
            'exclude' => NULL,
        ]);

        $userEmail = $user->emails->first()->email;

        $email = $this->createMinimalEmail([
            'From' => $userEmail,
            'To' => $group->nameshort.'@groups.ilovefreegle.org',
            'Subject' => 'OFFER: Supplements (London)',
        ], 'Amazing weight loss results with this product!');

        $parsed = $this->parser->parse(
            $email,
            $userEmail,
            $group->nameshort.'@groups.ilovefreegle.org'
        );

        $result = $this->service->route($parsed);

        $this->assertEquals(RoutingResult::INCOMING_SPAM, $result);
    }

    public function test_spam_keyword_with_exclude_pattern_does_not_match(): void
    {
        $group = $this->createTestGroup();
        $user = $this->createTestUser(['email_preferred' => $this->uniqueEmail('spamexcl')]);
        $this->createMembership($user, $group, [
            'ourPostingStatus' => 'DEFAULT',
        ]);
        DB::table('users')->where('id', $user->id)->update([
            'lastlocation' => $this->createLocation(51.5, -0.1),
        ]);

        // Insert keyword with exclude pattern
        DB::table('spam_keywords')->insert([
            'word' => 'courier',
            'action' => 'Spam',
            'type' => 'Literal',
            'exclude' => 'Never agree to pay courier fees',
        ]);

        $userEmail = $user->emails->first()->email;

        // Message contains the keyword BUT also the exclude pattern
        $email = $this->createMinimalEmail([
            'From' => $userEmail,
            'To' => $group->nameshort.'@groups.ilovefreegle.org',
            'Subject' => 'OFFER: Furniture (London)',
        ], 'I can arrange a courier. Never agree to pay courier fees for delivery.');

        $parsed = $this->parser->parse(
            $email,
            $userEmail,
            $group->nameshort.'@groups.ilovefreegle.org'
        );

        $result = $this->service->route($parsed);

        // Should NOT be spam because exclude pattern matches
        $this->assertNotEquals(RoutingResult::INCOMING_SPAM, $result);
    }

    public function test_drops_known_spammer(): void
    {
        $user = $this->createTestUser(['email_preferred' => $this->uniqueEmail('badactor')]);
        // Mark user as spammer
        DB::table('spam_users')->insert([
            'userid' => $user->id,
            'collection' => 'Spammer',
            'reason' => 'Test spammer',
            'added' => now(),
        ]);
        $group = $this->createTestGroup();

        $userEmail = $user->emails->first()->email;

        $email = $this->createMinimalEmail([
            'From' => $userEmail,
            'To' => $group->nameshort.'@groups.ilovefreegle.org',
            'Subject' => 'OFFER: Normal item (London)',
        ], 'This looks normal but sender is banned.');

        $parsed = $this->parser->parse(
            $email,
            $userEmail,
            $group->nameshort.'@groups.ilovefreegle.org'
        );

        $result = $this->service->route($parsed);

        $this->assertEquals(RoutingResult::DROPPED, $result);
    }

    // ========================================
    // Trash Nothing Routing Tests
    // ========================================

    public function test_routes_trash_nothing_post(): void
    {
        $group = $this->createTestGroup();

        $email = $this->loadEmailFixture('tn_post');
        // Modify fixture to target our test group
        $email = str_replace(
            'testgroup@groups.ilovefreegle.org',
            $group->nameshort.'@groups.ilovefreegle.org',
            $email
        );

        $parsed = $this->parser->parse(
            $email,
            'user@trashnothing.com',
            $group->nameshort.'@groups.ilovefreegle.org'
        );

        $result = $this->service->route($parsed);

        // TN posts from authenticated source should route to appropriate collection
        // This test verifies TN secret header is respected
        $this->assertNotEquals(RoutingResult::INCOMING_SPAM, $result);
    }

    public function test_skips_spam_check_for_trash_nothing_with_secret(): void
    {
        $group = $this->createTestGroup();

        // Create email with TN secret header but spam-like content
        $email = $this->createMinimalEmail([
            'From' => 'user@trashnothing.com',
            'To' => $group->nameshort.'@groups.ilovefreegle.org',
            'Subject' => 'OFFER: Make money (Bristol)',
            'X-Trash-Nothing-Secret' => 'valid-secret-here',
        ], 'Content that might otherwise trigger spam filters.');

        $parsed = $this->parser->parse(
            $email,
            'user@trashnothing.com',
            $group->nameshort.'@groups.ilovefreegle.org'
        );

        // If secret is valid, spam check should be skipped
        $result = $this->service->route($parsed);

        $this->assertNotEquals(RoutingResult::INCOMING_SPAM, $result);
    }

    // ========================================
    // Tryst (Calendar) Response Tests
    // ========================================

    public function test_routes_tryst_response_to_tryst(): void
    {
        $user = $this->createTestUser(['email_preferred' => $this->uniqueEmail('trystuser')]);
        $userEmail = $user->emails->first()->email;

        // Create a second user for the tryst
        $user2 = $this->createTestUser(['email_preferred' => $this->uniqueEmail('trystuser2')]);

        // Create a tryst record
        $trystId = DB::table('trysts')->insertGetId([
            'user1' => $user->id,
            'user2' => $user2->id,
            'arrangedfor' => now()->addDay(),
        ]);

        $email = $this->createMinimalEmail([
            'From' => $userEmail,
            'To' => "handover-{$trystId}-{$user->id}@users.ilovefreegle.org",
            'Subject' => 'Accepted: Pickup at 2pm',
            'Content-Type' => 'text/calendar; method=REPLY',
        ], "BEGIN:VCALENDAR\nMETHOD:REPLY\nEND:VCALENDAR");

        $parsed = $this->parser->parse(
            $email,
            $userEmail,
            "handover-{$trystId}-{$user->id}@users.ilovefreegle.org"
        );

        $result = $this->service->route($parsed);

        $this->assertEquals(RoutingResult::TRYST, $result);
    }

    public function test_routes_tryst_response_to_dropped_when_not_found(): void
    {
        $email = $this->createMinimalEmail([
            'From' => 'user@example.com',
            'To' => 'handover-99999999-88888888@users.ilovefreegle.org',
            'Subject' => 'Accepted: Pickup at 2pm',
            'Content-Type' => 'text/calendar; method=REPLY',
        ], "BEGIN:VCALENDAR\nMETHOD:REPLY\nEND:VCALENDAR");

        $parsed = $this->parser->parse(
            $email,
            'user@example.com',
            'handover-99999999-88888888@users.ilovefreegle.org'
        );

        $result = $this->service->route($parsed);

        $this->assertEquals(RoutingResult::DROPPED, $result);
    }

    // ========================================
    // Edge Case Tests
    // ========================================

    public function test_drops_twitter_notifications(): void
    {
        $group = $this->createTestGroup();

        $email = $this->createMinimalEmail([
            'From' => 'info@twitter.com',
            'To' => $group->nameshort.'@groups.ilovefreegle.org',
            'Subject' => 'You have a new follower!',
        ], 'Someone followed you on Twitter.');

        $parsed = $this->parser->parse(
            $email,
            'info@twitter.com',
            $group->nameshort.'@groups.ilovefreegle.org'
        );

        $result = $this->service->route($parsed);

        $this->assertEquals(RoutingResult::DROPPED, $result);
    }

    public function test_drops_self_sent_messages(): void
    {
        $user = $this->createTestUser(['email_preferred' => $this->uniqueEmail('user')]);
        $userEmail = $user->emails->first()->email;

        $email = $this->createMinimalEmail([
            'From' => $userEmail,
            'To' => $userEmail,
            'Subject' => 'Note to self',
        ], 'Testing.');

        $parsed = $this->parser->parse(
            $email,
            $userEmail,
            $userEmail
        );

        $result = $this->service->route($parsed);

        $this->assertEquals(RoutingResult::DROPPED, $result);
    }

    public function test_drops_chat_reply_to_stale_chat(): void
    {
        $user1 = $this->createTestUser(['email_preferred' => $this->uniqueEmail('user1')]);
        $user2 = $this->createTestUser(['email_preferred' => $this->uniqueEmail('user2')]);
        $chat = $this->createTestChatRoom($user1, $user2);

        // Make chat stale (90 days old)
        DB::table('chat_rooms')
            ->where('id', $chat->id)
            ->update(['latestmessage' => now()->subDays(90)]);

        // Try to reply from unfamiliar email
        $email = $this->createMinimalEmail([
            'From' => 'unknown@otherdomain.com',
            'To' => "notify-{$chat->id}-{$user1->id}@users.ilovefreegle.org",
            'Subject' => 'Re: Old conversation',
        ], 'Reply to very old chat.');

        $parsed = $this->parser->parse(
            $email,
            'unknown@otherdomain.com',
            "notify-{$chat->id}-{$user1->id}@users.ilovefreegle.org"
        );

        $result = $this->service->route($parsed);

        $this->assertEquals(RoutingResult::DROPPED, $result);
    }

    public function test_drops_expired_message_reply(): void
    {
        $poster = $this->createTestUser(['email_preferred' => $this->uniqueEmail('poster')]);
        $replier = $this->createTestUser(['email_preferred' => $this->uniqueEmail('replier')]);
        $group = $this->createTestGroup();
        $this->createMembership($poster, $group);
        $message = $this->createTestMessage($poster, $group);

        // Make message expired (45 days old)
        DB::table('messages')
            ->where('id', $message->id)
            ->update([
                'arrival' => now()->subDays(45),
                'date' => now()->subDays(45),
            ]);

        $replierEmail = $replier->emails->first()->email;

        $email = $this->createMinimalEmail([
            'From' => $replierEmail,
            'To' => "replyto-{$message->id}-{$replier->id}@users.ilovefreegle.org",
            'Subject' => 'Re: '.$message->subject,
        ], 'Is this still available?');

        $parsed = $this->parser->parse(
            $email,
            $replierEmail,
            "replyto-{$message->id}-{$replier->id}@users.ilovefreegle.org"
        );

        $result = $this->service->route($parsed);

        $this->assertEquals(RoutingResult::DROPPED, $result);
    }

    // ========================================
    // Freegle-Formatted Address Tests (user ID in address)
    // ========================================

    /**
     * Test that direct mail to Freegle-formatted address extracts user ID.
     * Address format: *-{userid}@users.ilovefreegle.org
     */
    public function test_routes_direct_mail_to_freegle_address_to_user(): void
    {
        // Create recipient user
        $recipient = $this->createTestUser(['email_preferred' => $this->uniqueEmail('recipient')]);
        // Create sender user with email in the database
        $sender = $this->createTestUser(['email_preferred' => $this->uniqueEmail('sender')]);
        $senderEmail = $sender->emails->first()->email;

        // Create location so user isn't "unmapped"
        $locationId = $this->createLocation(51.5, -0.1);
        DB::table('users')->where('id', $sender->id)->update(['lastlocation' => $locationId]);

        // Use Freegle-formatted address: anything-{userid}@users.ilovefreegle.org
        $freegleAddress = "somename-{$recipient->id}@users.ilovefreegle.org";

        $email = $this->createMinimalEmail([
            'From' => $senderEmail,
            'To' => $freegleAddress,
            'Subject' => 'Re: Your offer',
        ], 'Is this still available?');

        $parsed = $this->parser->parse($email, $senderEmail, $freegleAddress);

        $result = $this->service->route($parsed);

        $this->assertEquals(RoutingResult::TO_USER, $result);
    }

    /**
     * Test that direct mail to Freegle-formatted address with invalid user ID is dropped.
     */
    public function test_routes_direct_mail_to_invalid_freegle_address_to_dropped(): void
    {
        $sender = $this->createTestUser(['email_preferred' => $this->uniqueEmail('sender')]);
        $senderEmail = $sender->emails->first()->email;

        // User ID 99999999 doesn't exist
        $freegleAddress = 'somename-99999999@users.ilovefreegle.org';

        $email = $this->createMinimalEmail([
            'From' => $senderEmail,
            'To' => $freegleAddress,
            'Subject' => 'Re: Your offer',
        ], 'Is this still available?');

        $parsed = $this->parser->parse($email, $senderEmail, $freegleAddress);

        $result = $this->service->route($parsed);

        $this->assertEquals(RoutingResult::DROPPED, $result);
    }

    // ========================================
    // Group Post Moderation Tests
    // ========================================

    /**
     * Test that posts from moderators go to pending (per volunteer request to avoid accidents).
     */
    public function test_group_post_from_moderator_goes_to_pending(): void
    {
        $mod = $this->createTestUser(['email_preferred' => $this->uniqueEmail('mod')]);
        $group = $this->createTestGroup(['nameshort' => 'testgroup-'.uniqid()]);

        // Give user a location and make them a moderator
        $locationId = $this->createLocation(51.5, -0.1);
        DB::table('users')->where('id', $mod->id)->update(['lastlocation' => $locationId]);
        $this->createMembership($mod, $group, 'Approved', 'Moderator');

        $modEmail = $mod->emails->first()->email;
        $groupAddress = $group->nameshort.'@groups.ilovefreegle.org';

        $email = $this->createMinimalEmail([
            'From' => $modEmail,
            'To' => $groupAddress,
            'Subject' => 'OFFER: Test item (London)',
        ], 'A test offer from moderator');

        $parsed = $this->parser->parse($email, $modEmail, $groupAddress);

        $result = $this->service->route($parsed);

        $this->assertEquals(RoutingResult::PENDING, $result);
    }

    /**
     * Test that posts to group with moderated=1 setting go to pending.
     */
    public function test_group_post_to_moderated_group_goes_to_pending(): void
    {
        $user = $this->createTestUser(['email_preferred' => $this->uniqueEmail('user')]);
        $group = $this->createTestGroup([
            'nameshort' => 'testgroup-'.uniqid(),
            'settings' => json_encode(['moderated' => 1]),
        ]);

        // Give user a location and make them a member
        $locationId = $this->createLocation(51.5, -0.1);
        DB::table('users')->where('id', $user->id)->update(['lastlocation' => $locationId]);
        $this->createMembership($user, $group);

        $userEmail = $user->emails->first()->email;
        $groupAddress = $group->nameshort.'@groups.ilovefreegle.org';

        $email = $this->createMinimalEmail([
            'From' => $userEmail,
            'To' => $groupAddress,
            'Subject' => 'OFFER: Test item (London)',
        ], 'A test offer');

        $parsed = $this->parser->parse($email, $userEmail, $groupAddress);

        $result = $this->service->route($parsed);

        $this->assertEquals(RoutingResult::PENDING, $result);
    }

    /**
     * Test that posts to group with overridemoderation=ModerateAll (Big Switch) go to pending.
     */
    public function test_group_post_with_override_moderation_goes_to_pending(): void
    {
        $user = $this->createTestUser(['email_preferred' => $this->uniqueEmail('user')]);
        $group = $this->createTestGroup([
            'nameshort' => 'testgroup-'.uniqid(),
            'overridemoderation' => 'ModerateAll',  // The "Big Switch"
        ]);

        // Give user a location and make them a member
        $locationId = $this->createLocation(51.5, -0.1);
        DB::table('users')->where('id', $user->id)->update(['lastlocation' => $locationId]);
        $this->createMembership($user, $group);

        $userEmail = $user->emails->first()->email;
        $groupAddress = $group->nameshort.'@groups.ilovefreegle.org';

        $email = $this->createMinimalEmail([
            'From' => $userEmail,
            'To' => $groupAddress,
            'Subject' => 'OFFER: Test item (London)',
        ], 'A test offer');

        $parsed = $this->parser->parse($email, $userEmail, $groupAddress);

        $result = $this->service->route($parsed);

        $this->assertEquals(RoutingResult::PENDING, $result);
    }

    /**
     * Test that posts from regular member to unmoderated group are approved.
     */
    public function test_group_post_from_member_to_unmoderated_group_is_approved(): void
    {
        $user = $this->createTestUser(['email_preferred' => $this->uniqueEmail('user')]);
        $group = $this->createTestGroup([
            'nameshort' => 'testgroup-'.uniqid(),
            'settings' => json_encode(['moderated' => 0]),
        ]);

        // Give user a location and make them a member
        $locationId = $this->createLocation(51.5, -0.1);
        DB::table('users')->where('id', $user->id)->update(['lastlocation' => $locationId]);
        $this->createMembership($user, $group);

        $userEmail = $user->emails->first()->email;
        $groupAddress = $group->nameshort.'@groups.ilovefreegle.org';

        $email = $this->createMinimalEmail([
            'From' => $userEmail,
            'To' => $groupAddress,
            'Subject' => 'OFFER: Test item (London)',
        ], 'A test offer');

        $parsed = $this->parser->parse($email, $userEmail, $groupAddress);

        $result = $this->service->route($parsed);

        $this->assertEquals(RoutingResult::APPROVED, $result);
    }

    // ========================================
    // Chat Notification Self-Send Tests
    // ========================================

    /**
     * Test that replying to a chat notification where sender is the notify address user works.
     *
     * This is the NORMAL chat reply flow:
     * 1. User1 receives a notification about a message in chat with User2
     * 2. The reply-to address is notify-{chatid}-{user1id}@...
     * 3. User1 replies, and the message is added to the chat
     */
    public function test_chat_reply_where_sender_is_notify_user_is_accepted(): void
    {
        // Create two users and a chat between them
        $user1 = $this->createTestUser(['email_preferred' => $this->uniqueEmail('user1')]);
        $user2 = $this->createTestUser(['email_preferred' => $this->uniqueEmail('user2')]);
        $chat = $this->createTestChatRoom($user1, $user2);

        // User1's registered email
        $user1Email = $user1->emails->first()->email;

        // User1 replies to a notify address for user1 - this is the normal reply flow
        $email = $this->createMinimalEmail([
            'From' => $user1Email,
            'To' => "notify-{$chat->id}-{$user1->id}@users.ilovefreegle.org",
            'Subject' => 'Re: Chat message',
        ], 'This is a reply');

        $parsed = $this->parser->parse(
            $email,
            $user1Email,
            "notify-{$chat->id}-{$user1->id}@users.ilovefreegle.org"
        );

        $result = $this->service->route($parsed);

        // This is a valid chat reply and should be accepted
        $this->assertEquals(RoutingResult::TO_USER, $result);
    }

    /**
     * Test that a valid chat reply from the OTHER user in the chat is accepted.
     */
    public function test_chat_reply_from_other_user_is_accepted(): void
    {
        $user1 = $this->createTestUser(['email_preferred' => $this->uniqueEmail('user1')]);
        $user2 = $this->createTestUser(['email_preferred' => $this->uniqueEmail('user2')]);
        $chat = $this->createTestChatRoom($user1, $user2);

        // User2 replies to a notification that was sent to user1
        $user2Email = $user2->emails->first()->email;

        $email = $this->createMinimalEmail([
            'From' => $user2Email,
            'To' => "notify-{$chat->id}-{$user1->id}@users.ilovefreegle.org",
            'Subject' => 'Re: Chat message',
        ], 'Reply from other user');

        $parsed = $this->parser->parse(
            $email,
            $user2Email,
            "notify-{$chat->id}-{$user1->id}@users.ilovefreegle.org"
        );

        $result = $this->service->route($parsed);

        $this->assertEquals(RoutingResult::TO_USER, $result);
    }

    // ========================================
    // Chat Stale Reply Tests
    // ========================================

    /**
     * Test that replies to stale chats (>90 days) from unfamiliar sender are dropped.
     * Matches legacy User::OPEN_AGE = 90 days.
     */
    public function test_chat_reply_to_stale_chat_from_unfamiliar_sender_is_dropped(): void
    {
        $user1 = $this->createTestUser(['email_preferred' => $this->uniqueEmail('user1')]);
        $user2 = $this->createTestUser(['email_preferred' => $this->uniqueEmail('user2')]);
        $chat = $this->createTestChatRoom($user1, $user2);

        // Make the chat stale (91 days ago - beyond the 90 day threshold)
        DB::table('chat_rooms')->where('id', $chat->id)->update([
            'latestmessage' => now()->subDays(91),
        ]);

        // Send from an unfamiliar email (not associated with user1)
        $unfamiliarEmail = 'unknown-'.uniqid().'@example.com';

        $email = $this->createMinimalEmail([
            'From' => $unfamiliarEmail,
            'To' => "notify-{$chat->id}-{$user1->id}@users.ilovefreegle.org",
            'Subject' => 'Re: Old conversation',
        ], 'A reply to a very old chat');

        $parsed = $this->parser->parse(
            $email,
            $unfamiliarEmail,
            "notify-{$chat->id}-{$user1->id}@users.ilovefreegle.org"
        );

        $result = $this->service->route($parsed);

        $this->assertEquals(RoutingResult::DROPPED, $result);
    }

    /**
     * Test that replies to fresh chats from unfamiliar sender are accepted.
     */
    public function test_chat_reply_to_fresh_chat_from_unfamiliar_sender_is_accepted(): void
    {
        $user1 = $this->createTestUser(['email_preferred' => $this->uniqueEmail('user1')]);
        $user2 = $this->createTestUser(['email_preferred' => $this->uniqueEmail('user2')]);
        $chat = $this->createTestChatRoom($user1, $user2);

        // Make the chat fresh (30 days ago - within the 90 day threshold)
        DB::table('chat_rooms')->where('id', $chat->id)->update([
            'latestmessage' => now()->subDays(30),
        ]);

        // Send from an unfamiliar email (not associated with user1)
        $unfamiliarEmail = 'unknown-'.uniqid().'@example.com';

        $email = $this->createMinimalEmail([
            'From' => $unfamiliarEmail,
            'To' => "notify-{$chat->id}-{$user1->id}@users.ilovefreegle.org",
            'Subject' => 'Re: Recent conversation',
        ], 'A reply to a recent chat');

        $parsed = $this->parser->parse(
            $email,
            $unfamiliarEmail,
            "notify-{$chat->id}-{$user1->id}@users.ilovefreegle.org"
        );

        $result = $this->service->route($parsed);

        $this->assertEquals(RoutingResult::TO_USER, $result);
    }

    // ========================================
    // TN Email Canonicalization Tests
    // ========================================

    /**
     * Test that TN emails with group suffix are canonicalized correctly.
     * e.g., alice-g123@user.trashnothing.com should match alice-g456@user.trashnothing.com
     */
    public function test_tn_emails_with_different_group_suffixes_match_same_user(): void
    {
        // Create user with one TN email
        $user = $this->createTestUser(['email_preferred' => 'testuser-g123@user.trashnothing.com']);

        // Create another TN email for the same user with different group suffix
        DB::table('users_emails')->insert([
            'userid' => $user->id,
            'email' => 'testuser-g456@user.trashnothing.com',
            'canon' => 'testuser@usertrashnothingcom',
            'preferred' => 0,
            'added' => now(),
        ]);

        // Create recipient user
        $recipient = $this->createTestUser(['email_preferred' => $this->uniqueEmail('recipient')]);

        // Create location so sender isn't "unmapped"
        $locationId = $this->createLocation(51.5, -0.1);
        DB::table('users')->where('id', $user->id)->update(['lastlocation' => $locationId]);

        // Use Freegle-formatted address for recipient
        $freegleAddress = "somename-{$recipient->id}@users.ilovefreegle.org";

        // Send from the second TN email (g456)
        $email = $this->createMinimalEmail([
            'From' => 'testuser-g456@user.trashnothing.com',
            'To' => $freegleAddress,
            'Subject' => 'Re: Your offer',
        ], 'Is this still available?');

        $parsed = $this->parser->parse(
            $email,
            'testuser-g456@user.trashnothing.com',
            $freegleAddress
        );

        $result = $this->service->route($parsed);

        // Should find the sender via canon lookup and route successfully
        $this->assertEquals(RoutingResult::TO_USER, $result);
    }

    /**
     * Test that posts from member with NULL ourPostingStatus go to pending.
     *
     * Legacy behavior: when ourPostingStatus is null, it defaults to MODERATED
     * which means the message goes to pending for moderator review.
     */
    public function test_group_post_with_null_posting_status_goes_to_pending(): void
    {
        $user = $this->createTestUser(['email_preferred' => $this->uniqueEmail('user')]);
        $group = $this->createTestGroup([
            'nameshort' => 'testgroup-'.uniqid(),
            'settings' => json_encode(['moderated' => 0]),
        ]);

        // Give user a location
        $locationId = $this->createLocation(51.5, -0.1);
        DB::table('users')->where('id', $user->id)->update(['lastlocation' => $locationId]);

        // Create membership with NULL ourPostingStatus (the default)
        $this->createMembership($user, $group);
        // Explicitly set ourPostingStatus to NULL
        DB::table('memberships')
            ->where('userid', $user->id)
            ->where('groupid', $group->id)
            ->update(['ourPostingStatus' => null]);

        $userEmail = $user->emails->first()->email;
        $groupAddress = $group->nameshort.'@groups.ilovefreegle.org';

        $email = $this->createMinimalEmail([
            'From' => $userEmail,
            'To' => $groupAddress,
            'Subject' => 'OFFER: Test item (London)',
        ], 'A test offer');

        $parsed = $this->parser->parse($email, $userEmail, $groupAddress);

        $result = $this->service->route($parsed);

        // NULL posting status defaults to MODERATED per legacy behavior
        $this->assertEquals(RoutingResult::PENDING, $result);
    }

    // ========================================
    // Helper Methods
    // ========================================

    /**
     * Create a location for testing.
     */
    protected function createLocation(float $lat, float $lng): int
    {
        return DB::table('locations')->insertGetId([
            'name' => 'Test Location '.uniqid(),
            'type' => 'Polygon',
            'lat' => $lat,
            'lng' => $lng,
        ]);
    }
}
