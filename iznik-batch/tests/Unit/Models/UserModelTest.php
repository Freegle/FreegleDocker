<?php

namespace Tests\Unit\Models;

use App\Models\Membership;
use App\Models\User;
use App\Models\UserEmail;
use Tests\TestCase;

class UserModelTest extends TestCase
{
    public function test_user_has_email_preferred_attribute(): void
    {
        $user = $this->createTestUser();

        $this->assertNotNull($user->email_preferred);
        $this->assertStringContainsString('@test.com', $user->email_preferred);
    }

    public function test_user_can_have_multiple_emails(): void
    {
        $user = User::create([
            'firstname' => 'Test',
            'lastname' => 'User',
            'fullname' => 'Test User',
            'added' => now(),
        ]);

        UserEmail::create([
            'userid' => $user->id,
            'email' => 'primary@test.com',
            'preferred' => 1,
            'added' => now(),
        ]);

        UserEmail::create([
            'userid' => $user->id,
            'email' => 'secondary@test.com',
            'preferred' => 0,
            'added' => now(),
        ]);

        $this->assertEquals(2, $user->emails()->count());
    }

    public function test_user_has_memberships_relationship(): void
    {
        $user = $this->createTestUser();
        $group = $this->createTestGroup();
        $this->createMembership($user, $group);

        $this->assertEquals(1, $user->memberships()->count());
    }

    public function test_user_display_name_returns_fullname(): void
    {
        $user = User::create([
            'firstname' => 'Test',
            'lastname' => 'User',
            'fullname' => 'Test User',
            'added' => now(),
        ]);

        $this->assertEquals('Test User', $user->display_name);
    }

    public function test_user_display_name_falls_back_to_firstname(): void
    {
        $user = User::create([
            'firstname' => 'Test',
            'lastname' => 'User',
            'fullname' => null,
            'added' => now(),
        ]);

        $this->assertEquals('Test User', $user->display_name);
    }

    public function test_user_display_name_falls_back_to_default(): void
    {
        $user = User::create([
            'firstname' => null,
            'lastname' => null,
            'fullname' => null,
            'added' => now(),
        ]);

        $this->assertEquals('Freegle User', $user->display_name);
    }

    public function test_user_is_moderator_returns_false_for_regular_user(): void
    {
        $user = $this->createTestUser();
        $group = $this->createTestGroup();
        $this->createMembership($user, $group, ['role' => Membership::ROLE_MEMBER]);

        $this->assertFalse($user->isModerator());
    }

    public function test_user_is_moderator_returns_true_for_moderator(): void
    {
        $user = $this->createTestUser();
        $group = $this->createTestGroup();
        $this->createMembership($user, $group, ['role' => Membership::ROLE_MODERATOR]);

        $this->assertTrue($user->isModerator());
    }

    public function test_user_is_moderator_returns_true_for_owner(): void
    {
        $user = $this->createTestUser();
        $group = $this->createTestGroup();
        $this->createMembership($user, $group, ['role' => Membership::ROLE_OWNER]);

        $this->assertTrue($user->isModerator());
    }

    public function test_user_is_moderator_of_returns_false_for_other_group(): void
    {
        $user = $this->createTestUser();
        $group1 = $this->createTestGroup();
        $group2 = $this->createTestGroup();
        $this->createMembership($user, $group1, ['role' => Membership::ROLE_MODERATOR]);

        $this->assertTrue($user->isModeratorOf($group1->id));
        $this->assertFalse($user->isModeratorOf($group2->id));
    }

    public function test_user_donations_relationship(): void
    {
        $user = $this->createTestUser();

        $this->assertEquals(0, $user->donations()->count());
    }

    public function test_user_messages_relationship(): void
    {
        $user = $this->createTestUser();
        $group = $this->createTestGroup();
        $this->createMembership($user, $group);
        $this->createTestMessage($user, $group);

        $this->assertEquals(1, $user->messages()->count());
    }

    public function test_user_chat_messages_relationship(): void
    {
        $user = $this->createTestUser();

        $this->assertEquals(0, $user->chatMessages()->count());
    }

    public function test_user_expected_replies_relationship_exists(): void
    {
        $user = $this->createTestUser();

        // Verify the relationship method exists and is callable.
        $this->assertTrue(method_exists($user, 'expectedReplies'));
    }

    public function test_user_engagements_relationship_exists(): void
    {
        $user = $this->createTestUser();

        // Verify the relationship method exists and is callable.
        $this->assertTrue(method_exists($user, 'engagements'));
    }

    public function test_user_notifications_relationship_exists(): void
    {
        $user = $this->createTestUser();

        // Verify the relationship method exists and is callable.
        $this->assertTrue(method_exists($user, 'notifications'));
    }

    public function test_user_chat_rooms_as_user1_relationship(): void
    {
        $user = $this->createTestUser();

        $this->assertEquals(0, $user->chatRoomsAsUser1()->count());
    }

    public function test_user_chat_rooms_as_user2_relationship(): void
    {
        $user = $this->createTestUser();

        $this->assertEquals(0, $user->chatRoomsAsUser2()->count());
    }

    public function test_user_gift_aid_relationship(): void
    {
        $user = $this->createTestUser();

        $this->assertNull($user->giftAid);
    }
}
