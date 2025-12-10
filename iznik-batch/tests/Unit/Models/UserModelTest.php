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
}
