<?php

namespace Tests\Unit\Services\Mail\Incoming;

use App\Models\Group;
use App\Models\User;
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

        // Seed a spam keyword so the spam checker can detect it
        DB::table('spam_keywords')->insert([
            'word' => 'Western Union',
            'action' => 'Spam',
            'type' => 'Literal',
        ]);

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

    public function test_greeting_spam_detected_as_incoming_spam(): void
    {
        [$user, $group, $userEmail] = $this->createPostableUser();

        // Greeting spam pattern: greeting in subject + line1, with HTTP link
        $email = $this->createMinimalEmail([
            'From' => $userEmail,
            'To' => $group->nameshort.'@groups.ilovefreegle.org',
            'Subject' => 'Hello there!',
        ], "Hello my friend\nPlease check this out\nhttp://spammy-link.com/click-here");

        $parsed = $this->parser->parse(
            $email,
            $userEmail,
            $group->nameshort.'@groups.ilovefreegle.org'
        );

        $result = $this->service->route($parsed);

        $this->assertEquals(RoutingResult::INCOMING_SPAM, $result);
    }

    public function test_refer_to_spammer_detected_as_incoming_spam(): void
    {
        [$user, $group, $userEmail] = $this->createPostableUser();

        // Create a known spammer
        $spammer = $this->createTestUser(['email_preferred' => $this->uniqueEmail('spammer')]);
        DB::table('spam_users')->insert([
            'userid' => $spammer->id,
            'collection' => 'Spammer',
            'reason' => 'Known spammer',
            'added' => now(),
        ]);
        $spammerEmail = $spammer->emails->first()->email;

        $email = $this->createMinimalEmail([
            'From' => $userEmail,
            'To' => $group->nameshort.'@groups.ilovefreegle.org',
            'Subject' => 'OFFER: Free Stuff (London)',
        ], "Contact {$spammerEmail} for more details about this offer.");

        $parsed = $this->parser->parse(
            $email,
            $userEmail,
            $group->nameshort.'@groups.ilovefreegle.org'
        );

        $result = $this->service->route($parsed);

        $this->assertEquals(RoutingResult::INCOMING_SPAM, $result);
    }

    public function test_our_domain_in_from_name_detected_as_incoming_spam(): void
    {
        [$user, $group, $userEmail] = $this->createPostableUser();

        $email = $this->createMinimalEmail([
            'From' => 'groups.ilovefreegle.org <'.$userEmail.'>',
            'To' => $group->nameshort.'@groups.ilovefreegle.org',
            'Subject' => 'OFFER: Free Item (London)',
        ], 'A normal looking message body.');

        $parsed = $this->parser->parse(
            $email,
            $userEmail,
            $group->nameshort.'@groups.ilovefreegle.org'
        );

        $result = $this->service->route($parsed);

        $this->assertEquals(RoutingResult::INCOMING_SPAM, $result);
    }

    public function test_subject_reuse_across_groups_detected_as_spam(): void
    {
        [$user, , $userEmail] = $this->createPostableUser();

        // Create many groups and message history with same pruned subject
        // to exceed SUBJECT_THRESHOLD (30)
        for ($i = 0; $i < 31; $i++) {
            $g = $this->createTestGroup();
            DB::table('messages_history')->insert([
                'fromuser' => $user->id,
                'prunedsubject' => 'Test Spammy Subject',
                'groupid' => $g->id,
                'fromip' => '1.2.3.4',
                'fromname' => 'Test User',
                'arrival' => now(),
            ]);
        }

        $group = $this->createTestGroup();
        $this->createMembership($user, $group, ['ourPostingStatus' => 'DEFAULT']);

        $email = $this->createMinimalEmail([
            'From' => $userEmail,
            'To' => $group->nameshort.'@groups.ilovefreegle.org',
            'Subject' => 'OFFER: Test Spammy Subject (London)',
        ], 'Normal body text');

        $parsed = $this->parser->parse(
            $email,
            $userEmail,
            $group->nameshort.'@groups.ilovefreegle.org'
        );

        $result = $this->service->route($parsed);

        $this->assertEquals(RoutingResult::INCOMING_SPAM, $result);
    }

    public function test_ip_user_threshold_detected_as_spam(): void
    {
        [$user, $group, $userEmail] = $this->createPostableUser();

        // Create message history with many users from same IP (> USER_THRESHOLD=5)
        for ($i = 0; $i < 6; $i++) {
            $u = $this->createTestUser(['email_preferred' => $this->uniqueEmail("ipuser{$i}")]);
            DB::table('messages_history')->insert([
                'fromuser' => $u->id,
                'prunedsubject' => "Subject {$i}",
                'groupid' => $group->id,
                'fromip' => '203.0.113.99',
                'fromname' => "User {$i}",
                'arrival' => now(),
            ]);
        }

        // Email with the same suspicious IP
        $rawEmail = "From: {$userEmail}\r\nTo: {$group->nameshort}@groups.ilovefreegle.org\r\n"
            ."Subject: OFFER: Something (London)\r\nX-Freegle-IP: 203.0.113.99\r\n\r\n"
            .'Normal body text';

        $parsed = $this->parser->parse(
            $rawEmail,
            $userEmail,
            $group->nameshort.'@groups.ilovefreegle.org'
        );

        $result = $this->service->route($parsed);

        $this->assertEquals(RoutingResult::INCOMING_SPAM, $result);
    }

    public function test_ip_group_threshold_detected_as_spam(): void
    {
        [$user, , $userEmail] = $this->createPostableUser();

        // Create message history with many groups from same IP (>= GROUP_THRESHOLD=20)
        for ($i = 0; $i < 20; $i++) {
            $g = $this->createTestGroup();
            DB::table('messages_history')->insert([
                'fromuser' => $user->id,
                'prunedsubject' => 'Subject',
                'groupid' => $g->id,
                'fromip' => '203.0.113.88',
                'fromname' => 'Test User',
                'arrival' => now(),
            ]);
        }

        $group = $this->createTestGroup();
        $this->createMembership($user, $group, ['ourPostingStatus' => 'DEFAULT']);

        $rawEmail = "From: {$userEmail}\r\nTo: {$group->nameshort}@groups.ilovefreegle.org\r\n"
            ."Subject: OFFER: Something (London)\r\nX-Freegle-IP: 203.0.113.88\r\n\r\n"
            .'Normal body text';

        $parsed = $this->parser->parse(
            $rawEmail,
            $userEmail,
            $group->nameshort.'@groups.ilovefreegle.org'
        );

        $result = $this->service->route($parsed);

        $this->assertEquals(RoutingResult::INCOMING_SPAM, $result);
    }

    public function test_bulk_volunteer_mail_detected_as_spam(): void
    {
        $user = $this->createTestUser(['email_preferred' => $this->uniqueEmail('bulkmailer')]);
        $userEmail = $user->emails->first()->email;

        // Create history of bulk sends to volunteer addresses
        for ($i = 0; $i < 21; $i++) {
            $g = $this->createTestGroup();
            $this->createMembership($user, $g);
            DB::table('messages')->insert([
                'envelopefrom' => $userEmail,
                'envelopeto' => $g->nameshort.'-volunteers@groups.ilovefreegle.org',
                'arrival' => now(),
            ]);
        }

        // Now send one more volunteer email
        $targetGroup = $this->createTestGroup();
        $this->createMembership($user, $targetGroup);

        $email = $this->createMinimalEmail([
            'From' => $userEmail,
            'To' => $targetGroup->nameshort.'-volunteers@groups.ilovefreegle.org',
            'Subject' => 'Important message for volunteers',
        ], 'Please share this with everyone.');

        $parsed = $this->parser->parse(
            $email,
            $userEmail,
            $targetGroup->nameshort.'-volunteers@groups.ilovefreegle.org'
        );

        $result = $this->service->route($parsed);

        $this->assertEquals(RoutingResult::INCOMING_SPAM, $result);
    }

    public function test_volunteers_with_spam_keyword_detected_as_spam(): void
    {
        DB::table('spam_keywords')->insert([
            'word' => 'Western Union',
            'action' => 'Spam',
            'type' => 'Literal',
        ]);

        $user = $this->createTestUser(['email_preferred' => $this->uniqueEmail('volspammer')]);
        $group = $this->createTestGroup();
        $this->createMembership($user, $group);
        $userEmail = $user->emails->first()->email;

        $email = $this->createMinimalEmail([
            'From' => $userEmail,
            'To' => $group->nameshort.'-volunteers@groups.ilovefreegle.org',
            'Subject' => 'Important notice',
        ], 'Send money via Western Union to claim your prize.');

        $parsed = $this->parser->parse(
            $email,
            $userEmail,
            $group->nameshort.'-volunteers@groups.ilovefreegle.org'
        );

        $result = $this->service->route($parsed);

        // Volunteers messages with spam go to review (not rejected) - users may be reporting spam
        $this->assertEquals(RoutingResult::TO_VOLUNTEERS, $result);

        // The chat message should be flagged for review so volunteers see it was detected
        $lastMessage = DB::table('chat_messages')
            ->orderBy('id', 'desc')
            ->first();

        $this->assertEquals(1, $lastMessage->reviewrequired);
        $this->assertEquals('Spam', $lastMessage->reportreason);
    }

    public function test_banned_user_post_dropped(): void
    {
        $group = $this->createTestGroup();
        $user = $this->createTestUser(['email_preferred' => $this->uniqueEmail('banned')]);
        // Add membership then set posting status to prohibited
        $this->createMembership($user, $group, [
            'ourPostingStatus' => 'PROHIBITED',
        ]);
        DB::table('users')->where('id', $user->id)->update([
            'lastlocation' => $this->createLocation(51.5, -0.1),
        ]);

        $userEmail = $user->emails->first()->email;

        $email = $this->createMinimalEmail([
            'From' => $userEmail,
            'To' => $group->nameshort.'@groups.ilovefreegle.org',
            'Subject' => 'OFFER: Something (London)',
        ], 'Normal message body.');

        $parsed = $this->parser->parse(
            $email,
            $userEmail,
            $group->nameshort.'@groups.ilovefreegle.org'
        );

        $result = $this->service->route($parsed);

        $this->assertEquals(RoutingResult::DROPPED, $result);
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
    // Chat Spam/Review Tests
    // ========================================

    public function test_chat_reply_with_spam_keyword_flagged_for_review(): void
    {
        // Seed a spam keyword
        DB::table('spam_keywords')->insert([
            'word' => 'Western Union',
            'action' => 'Spam',
            'type' => 'Literal',
        ]);

        $user1 = $this->createTestUser(['email_preferred' => $this->uniqueEmail('sender')]);
        $user2 = $this->createTestUser(['email_preferred' => $this->uniqueEmail('recipient')]);
        $chat = $this->createTestChatRoom($user1, $user2);

        $user1Email = $user1->emails->first()->email;

        $email = $this->createMinimalEmail([
            'From' => $user1Email,
            'To' => "notify-{$chat->id}-{$user1->id}@users.ilovefreegle.org",
            'Subject' => 'Re: About the item',
        ], 'Please send payment via Western Union to claim your item.');

        $parsed = $this->parser->parse(
            $email,
            $user1Email,
            "notify-{$chat->id}-{$user1->id}@users.ilovefreegle.org"
        );

        $result = $this->service->route($parsed);

        // Chat-bound spam is NOT rejected - it goes to review
        $this->assertEquals(RoutingResult::TO_USER, $result);

        // The chat message should be flagged for review
        $lastMessage = DB::table('chat_messages')
            ->where('chatid', $chat->id)
            ->orderBy('id', 'desc')
            ->first();

        $this->assertEquals(1, $lastMessage->reviewrequired);
        $this->assertEquals('Spam', $lastMessage->reportreason);
    }

    public function test_chat_reply_with_money_symbol_flagged_for_review(): void
    {
        $user1 = $this->createTestUser(['email_preferred' => $this->uniqueEmail('sender')]);
        $user2 = $this->createTestUser(['email_preferred' => $this->uniqueEmail('recipient')]);
        $chat = $this->createTestChatRoom($user1, $user2);

        $user1Email = $user1->emails->first()->email;

        $email = $this->createMinimalEmail([
            'From' => $user1Email,
            'To' => "notify-{$chat->id}-{$user1->id}@users.ilovefreegle.org",
            'Subject' => 'Re: About the item',
        ], 'I will sell this for £50, bargain price.');

        $parsed = $this->parser->parse(
            $email,
            $user1Email,
            "notify-{$chat->id}-{$user1->id}@users.ilovefreegle.org"
        );

        $result = $this->service->route($parsed);

        $this->assertEquals(RoutingResult::TO_USER, $result);

        // checkReview detects money symbols
        $lastMessage = DB::table('chat_messages')
            ->where('chatid', $chat->id)
            ->orderBy('id', 'desc')
            ->first();

        $this->assertEquals(1, $lastMessage->reviewrequired);
        $this->assertEquals('Spam', $lastMessage->reportreason);
    }

    public function test_chat_reply_with_script_tag_flagged_for_review(): void
    {
        $user1 = $this->createTestUser(['email_preferred' => $this->uniqueEmail('sender')]);
        $user2 = $this->createTestUser(['email_preferred' => $this->uniqueEmail('recipient')]);
        $chat = $this->createTestChatRoom($user1, $user2);

        $user1Email = $user1->emails->first()->email;

        $email = $this->createMinimalEmail([
            'From' => $user1Email,
            'To' => "notify-{$chat->id}-{$user1->id}@users.ilovefreegle.org",
            'Subject' => 'Re: About the item',
        ], 'Hello <script>alert("xss")</script> world');

        $parsed = $this->parser->parse(
            $email,
            $user1Email,
            "notify-{$chat->id}-{$user1->id}@users.ilovefreegle.org"
        );

        $result = $this->service->route($parsed);

        $this->assertEquals(RoutingResult::TO_USER, $result);

        $lastMessage = DB::table('chat_messages')
            ->where('chatid', $chat->id)
            ->orderBy('id', 'desc')
            ->first();

        $this->assertEquals(1, $lastMessage->reviewrequired);
        $this->assertEquals('Spam', $lastMessage->reportreason);
    }

    public function test_clean_chat_reply_not_flagged_for_review(): void
    {
        $user1 = $this->createTestUser(['email_preferred' => $this->uniqueEmail('sender')]);
        $user2 = $this->createTestUser(['email_preferred' => $this->uniqueEmail('recipient')]);
        $chat = $this->createTestChatRoom($user1, $user2);

        $user1Email = $user1->emails->first()->email;

        $email = $this->createMinimalEmail([
            'From' => $user1Email,
            'To' => "notify-{$chat->id}-{$user1->id}@users.ilovefreegle.org",
            'Subject' => 'Re: About the item',
        ], 'Yes, it is still available! When would you like to collect?');

        $parsed = $this->parser->parse(
            $email,
            $user1Email,
            "notify-{$chat->id}-{$user1->id}@users.ilovefreegle.org"
        );

        $result = $this->service->route($parsed);

        $this->assertEquals(RoutingResult::TO_USER, $result);

        $lastMessage = DB::table('chat_messages')
            ->where('chatid', $chat->id)
            ->orderBy('id', 'desc')
            ->first();

        $this->assertEquals(0, $lastMessage->reviewrequired);
        $this->assertNull($lastMessage->reportreason);
    }

    public function test_direct_mail_with_spam_flagged_for_review(): void
    {
        // Seed a spam keyword
        DB::table('spam_keywords')->insert([
            'word' => 'Western Union',
            'action' => 'Spam',
            'type' => 'Literal',
        ]);

        $sender = $this->createTestUser(['email_preferred' => $this->uniqueEmail('sender')]);
        $recipient = $this->createTestUser(['email_preferred' => $this->uniqueEmail('recipient')]);
        $senderEmail = $sender->emails->first()->email;
        $recipientEmail = $recipient->emails->first()->email;

        $email = $this->createMinimalEmail([
            'From' => $senderEmail,
            'To' => $recipientEmail,
            'Subject' => 'About the item',
        ], 'Send money via Western Union to receive your prize.');

        $parsed = $this->parser->parse(
            $email,
            $senderEmail,
            $recipientEmail
        );

        $result = $this->service->route($parsed);

        // Direct mail spam goes to review, not rejection
        $this->assertEquals(RoutingResult::TO_USER, $result);

        // Find the chat that was created
        $chat = DB::table('chat_rooms')
            ->where(function ($q) use ($sender, $recipient) {
                $q->where('user1', $sender->id)->where('user2', $recipient->id);
            })
            ->orWhere(function ($q) use ($sender, $recipient) {
                $q->where('user1', $recipient->id)->where('user2', $sender->id);
            })
            ->first();

        $this->assertNotNull($chat);

        $lastMessage = DB::table('chat_messages')
            ->where('chatid', $chat->id)
            ->orderBy('id', 'desc')
            ->first();

        $this->assertEquals(1, $lastMessage->reviewrequired);
    }

    public function test_chat_reply_with_external_email_flagged_for_review(): void
    {
        $user1 = $this->createTestUser(['email_preferred' => $this->uniqueEmail('sender')]);
        $user2 = $this->createTestUser(['email_preferred' => $this->uniqueEmail('recipient')]);
        $chat = $this->createTestChatRoom($user1, $user2);

        $user1Email = $user1->emails->first()->email;

        $email = $this->createMinimalEmail([
            'From' => $user1Email,
            'To' => "notify-{$chat->id}-{$user1->id}@users.ilovefreegle.org",
            'Subject' => 'Re: About the item',
        ], 'Contact me at myemail@externaldomain.com for more details about this offer.');

        $parsed = $this->parser->parse(
            $email,
            $user1Email,
            "notify-{$chat->id}-{$user1->id}@users.ilovefreegle.org"
        );

        $result = $this->service->route($parsed);

        $this->assertEquals(RoutingResult::TO_USER, $result);

        $lastMessage = DB::table('chat_messages')
            ->where('chatid', $chat->id)
            ->orderBy('id', 'desc')
            ->first();

        $this->assertEquals(1, $lastMessage->reviewrequired);
        $this->assertEquals('Spam', $lastMessage->reportreason);
    }

    // ========================================
    // Volunteers Spam Check Tests
    // ========================================

    public function test_volunteers_message_from_spammer_flagged_for_review(): void
    {
        $group = $this->createTestGroup();
        $user = $this->createTestUser(['email_preferred' => $this->uniqueEmail('vol-spammer')]);
        $this->createMembership($user, $group);

        // Mark user as spammer
        DB::table('spam_users')->insert([
            'userid' => $user->id,
            'collection' => 'Spammer',
            'added' => now(),
        ]);

        $userEmail = $user->emails->first()->email;
        $groupName = $group->nameshort;

        $email = $this->createMinimalEmail([
            'From' => $userEmail,
            'To' => "{$groupName}-volunteers@groups.ilovefreegle.org",
            'Subject' => 'Help please',
        ], 'I need help with my item.');

        $parsed = $this->parser->parse(
            $email,
            $userEmail,
            "{$groupName}-volunteers@groups.ilovefreegle.org"
        );

        $result = $this->service->route($parsed);

        // Known spammer to volunteers: goes to review, not rejected
        $this->assertEquals(RoutingResult::TO_VOLUNTEERS, $result);

        // Chat message should be flagged for review
        $lastMessage = DB::table('chat_messages')
            ->orderBy('id', 'desc')
            ->first();

        $this->assertEquals(1, $lastMessage->reviewrequired);
        $this->assertEquals('Spam', $lastMessage->reportreason);
    }

    public function test_volunteers_spam_keyword_flagged_for_review(): void
    {
        $group = $this->createTestGroup();
        $user = $this->createTestUser(['email_preferred' => $this->uniqueEmail('vol-spam')]);
        $this->createMembership($user, $group);

        // Seed spam keyword
        DB::table('spam_keywords')->insert([
            'word' => 'Western Union',
            'action' => 'Spam',
            'type' => 'Literal',
        ]);

        $userEmail = $user->emails->first()->email;
        $groupName = $group->nameshort;

        $email = $this->createMinimalEmail([
            'From' => $userEmail,
            'To' => "{$groupName}-volunteers@groups.ilovefreegle.org",
            'Subject' => 'Help please',
        ], 'Please send money via Western Union right away.');

        $parsed = $this->parser->parse(
            $email,
            $userEmail,
            "{$groupName}-volunteers@groups.ilovefreegle.org"
        );

        $result = $this->service->route($parsed);

        // Volunteers spam goes to review, not rejected
        $this->assertEquals(RoutingResult::TO_VOLUNTEERS, $result);

        // Chat message should be flagged
        $lastMessage = DB::table('chat_messages')
            ->orderBy('id', 'desc')
            ->first();

        $this->assertEquals(1, $lastMessage->reviewrequired);
        $this->assertEquals('Spam', $lastMessage->reportreason);
    }

    // ========================================
    // TAKEN/RECEIVED Swallowing Tests
    // ========================================

    public function test_taken_subject_post_routes_to_system(): void
    {
        [$user, $group, $userEmail] = $this->createPostableUser();

        $email = $this->createMinimalEmail([
            'From' => $userEmail,
            'To' => "{$group->nameshort}@groups.ilovefreegle.org",
            'Subject' => 'TAKEN: Dining table (London)',
        ], 'This item has been taken.');

        $parsed = $this->parser->parse(
            $email,
            $userEmail,
            "{$group->nameshort}@groups.ilovefreegle.org"
        );

        $result = $this->service->route($parsed);

        $this->assertEquals(RoutingResult::TO_SYSTEM, $result);
    }

    public function test_received_subject_post_routes_to_system(): void
    {
        [$user, $group, $userEmail] = $this->createPostableUser();

        $email = $this->createMinimalEmail([
            'From' => $userEmail,
            'To' => "{$group->nameshort}@groups.ilovefreegle.org",
            'Subject' => 'RECEIVED: Bookshelf (Acton)',
        ], 'Got this, thanks!');

        $parsed = $this->parser->parse(
            $email,
            $userEmail,
            "{$group->nameshort}@groups.ilovefreegle.org"
        );

        $result = $this->service->route($parsed);

        $this->assertEquals(RoutingResult::TO_SYSTEM, $result);
    }

    // ========================================
    // Closed Group Reply Test
    // ========================================

    public function test_reply_to_message_on_closed_group_returns_to_system(): void
    {
        $user1 = $this->createTestUser(['email_preferred' => $this->uniqueEmail('closed-sender')]);
        $user2 = $this->createTestUser(['email_preferred' => $this->uniqueEmail('closed-recipient')]);

        // Create a group with closed setting
        $group = $this->createTestGroup(['settings' => json_encode(['closed' => true])]);

        // Create a message on that group
        $messageId = DB::table('messages')->insertGetId([
            'arrival' => now()->subDays(5),
            'date' => now()->subDays(5),
            'fromuser' => $user2->id,
            'subject' => 'OFFER: Something',
            'type' => 'Offer',
        ]);

        DB::table('messages_groups')->insert([
            'msgid' => $messageId,
            'groupid' => $group->id,
            'collection' => 'Approved',
            'arrival' => now()->subDays(5),
        ]);

        $user1Email = $user1->emails->first()->email;

        $email = $this->createMinimalEmail([
            'From' => $user1Email,
            'To' => "replyto-{$messageId}-{$user1->id}@users.ilovefreegle.org",
            'Subject' => 'Re: OFFER: Something',
        ], 'I would like this please.');

        $parsed = $this->parser->parse(
            $email,
            $user1Email,
            "replyto-{$messageId}-{$user1->id}@users.ilovefreegle.org"
        );

        $result = $this->service->route($parsed);

        $this->assertEquals(RoutingResult::TO_SYSTEM, $result);
    }

    // ========================================
    // Read Receipt in Chat Reply Test
    // ========================================

    public function test_read_receipt_in_chat_reply_is_dropped(): void
    {
        $user1 = $this->createTestUser(['email_preferred' => $this->uniqueEmail('receipt-sender')]);
        $user2 = $this->createTestUser(['email_preferred' => $this->uniqueEmail('receipt-recipient')]);
        $chat = $this->createTestChatRoom($user1, $user2);

        $user1Email = $user1->emails->first()->email;

        $email = $this->createMinimalEmail([
            'From' => $user1Email,
            'To' => "notify-{$chat->id}-{$user1->id}@users.ilovefreegle.org",
            'Subject' => 'Re: About the item',
            'Content-Type' => 'multipart/report; report-type=disposition-notification',
        ], '');

        $parsed = $this->parser->parse(
            $email,
            $user1Email,
            "notify-{$chat->id}-{$user1->id}@users.ilovefreegle.org"
        );

        $result = $this->service->route($parsed);

        $this->assertEquals(RoutingResult::DROPPED, $result);
    }

    // ========================================
    // Prohibited Posting Status Test
    // ========================================

    public function test_prohibited_posting_status_routes_to_dropped(): void
    {
        $group = $this->createTestGroup();
        $user = $this->createTestUser(['email_preferred' => $this->uniqueEmail('prohibited')]);
        $this->createMembership($user, $group, [
            'ourPostingStatus' => 'PROHIBITED',
        ]);
        DB::table('users')->where('id', $user->id)->update([
            'lastlocation' => $this->createLocation(51.5, -0.1),
        ]);

        $userEmail = $user->emails->first()->email;

        $email = $this->createMinimalEmail([
            'From' => $userEmail,
            'To' => "{$group->nameshort}@groups.ilovefreegle.org",
            'Subject' => 'OFFER: Banned item',
        ], 'This is a test post.');

        $parsed = $this->parser->parse(
            $email,
            $userEmail,
            "{$group->nameshort}@groups.ilovefreegle.org"
        );

        $result = $this->service->route($parsed);

        $this->assertEquals(RoutingResult::DROPPED, $result);
    }

    // ========================================
    // Direct Mail Self-to-Self Test
    // ========================================

    public function test_direct_mail_to_self_is_dropped(): void
    {
        $user = $this->createTestUser(['email_preferred' => $this->uniqueEmail('selfmail')]);
        $userEmail = $user->emails->first()->email;

        $email = $this->createMinimalEmail([
            'From' => $userEmail,
            'To' => $userEmail,
            'Subject' => 'Testing',
        ], 'Hello me.');

        $parsed = $this->parser->parse(
            $email,
            $userEmail,
            $userEmail
        );

        $result = $this->service->route($parsed);

        $this->assertEquals(RoutingResult::DROPPED, $result);
    }

    // ========================================
    // Helper Methods
    // ========================================

    /**
     * Create a user with group membership, location, and DEFAULT posting status.
     *
     * @return array{User, Group, string} [user, group, userEmail]
     */
    protected function createPostableUser(): array
    {
        $group = $this->createTestGroup();
        $user = $this->createTestUser(['email_preferred' => $this->uniqueEmail('postable')]);
        $this->createMembership($user, $group, [
            'ourPostingStatus' => 'DEFAULT',
        ]);
        DB::table('users')->where('id', $user->id)->update([
            'lastlocation' => $this->createLocation(51.5, -0.1),
        ]);
        $userEmail = $user->emails->first()->email;

        return [$user, $group, $userEmail];
    }

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
