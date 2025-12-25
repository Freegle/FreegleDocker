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

    public function test_remove_tn_group_strips_suffix(): void
    {
        $this->assertEquals('Alice', User::removeTNGroup('Alice-g298'));
        $this->assertEquals('Bob Smith', User::removeTNGroup('Bob Smith-g12345'));
    }

    public function test_remove_tn_group_preserves_name_without_suffix(): void
    {
        $this->assertEquals('Alice', User::removeTNGroup('Alice'));
        $this->assertEquals('Bob Smith', User::removeTNGroup('Bob Smith'));
    }

    public function test_remove_tn_group_preserves_hyphen_without_g_suffix(): void
    {
        $this->assertEquals('Mary-Jane', User::removeTNGroup('Mary-Jane'));
    }

    public function test_display_name_strips_tn_group_suffix(): void
    {
        $user = User::create([
            'fullname' => 'Alice-g298',
            'added' => now(),
        ]);

        $this->assertEquals('Alice', $user->display_name);
    }

    public function test_is_tn_returns_true_for_trashnothing_user(): void
    {
        $user = User::create([
            'fullname' => 'TN User',
            'added' => now(),
        ]);

        UserEmail::create([
            'userid' => $user->id,
            'email' => 'user123@user.trashnothing.com',
            'preferred' => 1,
            'added' => now(),
        ]);

        $this->assertTrue($user->fresh()->isTN());
    }

    public function test_is_tn_returns_false_for_regular_user(): void
    {
        $user = $this->createTestUser();

        $this->assertFalse($user->isTN());
    }

    public function test_notifs_on_returns_default_true_for_email(): void
    {
        $user = User::create([
            'fullname' => 'Test',
            'added' => now(),
        ]);

        $this->assertTrue($user->notifsOn(User::NOTIFS_EMAIL));
    }

    public function test_notifs_on_returns_default_false_for_emailmine(): void
    {
        $user = User::create([
            'fullname' => 'Test',
            'added' => now(),
        ]);

        $this->assertFalse($user->notifsOn(User::NOTIFS_EMAIL_MINE));
    }

    public function test_notifs_on_returns_default_true_for_push(): void
    {
        $user = User::create([
            'fullname' => 'Test',
            'added' => now(),
        ]);

        $this->assertTrue($user->notifsOn(User::NOTIFS_PUSH));
    }

    public function test_notifs_on_respects_user_settings(): void
    {
        $user = User::create([
            'fullname' => 'Test',
            'added' => now(),
            'settings' => [
                'notifications' => [
                    'email' => false,
                    'emailmine' => true,
                    'push' => false,
                ],
            ],
        ]);

        $this->assertFalse($user->notifsOn(User::NOTIFS_EMAIL));
        $this->assertTrue($user->notifsOn(User::NOTIFS_EMAIL_MINE));
        $this->assertFalse($user->notifsOn(User::NOTIFS_PUSH));
    }

    public function test_notifs_on_with_group_checks_moderator_status(): void
    {
        $user = $this->createTestUser();
        $group = $this->createTestGroup();
        $this->createMembership($user, $group, ['role' => Membership::ROLE_MODERATOR]);

        // Should return true because user is a moderator of the group.
        $this->assertTrue($user->notifsOn(User::NOTIFS_EMAIL, $group->id));
    }

    public function test_notifs_on_with_group_returns_false_for_non_moderator(): void
    {
        $user = $this->createTestUser();
        $group = $this->createTestGroup();
        $this->createMembership($user, $group, ['role' => Membership::ROLE_MEMBER]);

        // Should return false because user is not a moderator.
        $this->assertFalse($user->notifsOn(User::NOTIFS_EMAIL, $group->id));
    }

    public function test_get_lat_lng_returns_null_without_last_location(): void
    {
        $user = User::create([
            'fullname' => 'Test',
            'added' => now(),
            'lastlocation' => null,
        ]);

        [$lat, $lng] = $user->getLatLng();

        $this->assertNull($lat);
        $this->assertNull($lng);
    }

    public function test_get_job_ads_returns_empty_collection_without_location(): void
    {
        $user = User::create([
            'fullname' => 'Test',
            'added' => now(),
            'lastlocation' => null,
        ]);

        $result = $user->getJobAds();

        $this->assertIsArray($result);
        $this->assertArrayHasKey('jobs', $result);
        $this->assertArrayHasKey('location', $result);
        $this->assertTrue($result['jobs']->isEmpty());
        $this->assertNull($result['location']);
    }

    public function test_get_profile_image_url_returns_null_without_image(): void
    {
        $user = $this->createTestUser();

        $result = $user->getProfileImageUrl();

        $this->assertNull($result);
    }

    public function test_get_user_key_creates_new_key(): void
    {
        $user = $this->createTestUser();

        $key = $user->getUserKey();

        $this->assertNotEmpty($key);
        $this->assertEquals(32, strlen($key)); // 16 bytes = 32 hex chars
    }

    public function test_get_user_key_returns_existing_key(): void
    {
        $user = $this->createTestUser();

        $key1 = $user->getUserKey();
        $key2 = $user->getUserKey();

        $this->assertEquals($key1, $key2);
    }

    public function test_list_unsubscribe_returns_formatted_url(): void
    {
        $user = $this->createTestUser();

        $result = $user->listUnsubscribe();

        $this->assertStringStartsWith('<', $result);
        $this->assertStringEndsWith('>', $result);
        $this->assertStringContainsString('/one-click-unsubscribe/', $result);
        $this->assertStringContainsString((string) $user->id, $result);
    }

    public function test_email_tracking_relationship(): void
    {
        $user = $this->createTestUser();

        $this->assertEquals(0, $user->emailTracking()->count());
    }

    public function test_login_link_constant(): void
    {
        $this->assertEquals('Link', User::LOGIN_LINK);
    }
}
