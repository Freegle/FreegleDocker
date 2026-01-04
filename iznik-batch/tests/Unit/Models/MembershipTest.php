<?php

namespace Tests\Unit\Models;

use App\Models\Group;
use App\Models\Membership;
use App\Models\User;
use Tests\TestCase;

class MembershipTest extends TestCase
{
    public function test_is_active_mod_returns_true_for_members(): void
    {
        $user = $this->createTestUser();
        $group = $this->createTestGroup();

        $membership = Membership::create([
            'userid' => $user->id,
            'groupid' => $group->id,
            'role' => Membership::ROLE_MEMBER,
            'added' => now(),
        ]);

        $this->assertTrue($membership->isActiveMod());
    }

    public function test_is_active_mod_returns_true_for_mod_with_null_settings(): void
    {
        $user = $this->createTestUser();
        $group = $this->createTestGroup();

        $membership = Membership::create([
            'userid' => $user->id,
            'groupid' => $group->id,
            'role' => Membership::ROLE_MODERATOR,
            'added' => now(),
            'settings' => null,
        ]);

        $this->assertTrue($membership->isActiveMod());
    }

    public function test_is_active_mod_returns_true_for_mod_without_active_key(): void
    {
        $user = $this->createTestUser();
        $group = $this->createTestGroup();

        $membership = Membership::create([
            'userid' => $user->id,
            'groupid' => $group->id,
            'role' => Membership::ROLE_MODERATOR,
            'added' => now(),
            'settings' => ['someOtherSetting' => true],
        ]);

        $this->assertTrue($membership->isActiveMod());
    }

    public function test_is_active_mod_returns_true_for_explicitly_active_mod(): void
    {
        $user = $this->createTestUser();
        $group = $this->createTestGroup();

        $membership = Membership::create([
            'userid' => $user->id,
            'groupid' => $group->id,
            'role' => Membership::ROLE_MODERATOR,
            'added' => now(),
            'settings' => ['active' => true],
        ]);

        $this->assertTrue($membership->isActiveMod());
    }

    public function test_is_active_mod_returns_false_for_backup_mod(): void
    {
        $user = $this->createTestUser();
        $group = $this->createTestGroup();

        $membership = Membership::create([
            'userid' => $user->id,
            'groupid' => $group->id,
            'role' => Membership::ROLE_MODERATOR,
            'added' => now(),
            'settings' => ['active' => false],
        ]);

        $this->assertFalse($membership->isActiveMod());
    }

    public function test_is_active_mod_returns_false_for_backup_owner(): void
    {
        $user = $this->createTestUser();
        $group = $this->createTestGroup();

        $membership = Membership::create([
            'userid' => $user->id,
            'groupid' => $group->id,
            'role' => Membership::ROLE_OWNER,
            'added' => now(),
            'settings' => ['active' => false],
        ]);

        $this->assertFalse($membership->isActiveMod());
    }

    public function test_active_moderators_scope_excludes_backup_mods(): void
    {
        $group = $this->createTestGroup();

        // Create an active mod (null settings).
        $activeMod1 = $this->createTestUser();
        Membership::create([
            'userid' => $activeMod1->id,
            'groupid' => $group->id,
            'role' => Membership::ROLE_MODERATOR,
            'added' => now(),
            'settings' => null,
        ]);

        // Create an active mod (explicitly active).
        $activeMod2 = $this->createTestUser();
        Membership::create([
            'userid' => $activeMod2->id,
            'groupid' => $group->id,
            'role' => Membership::ROLE_OWNER,
            'added' => now(),
            'settings' => ['active' => true],
        ]);

        // Create a backup mod.
        $backupMod = $this->createTestUser();
        Membership::create([
            'userid' => $backupMod->id,
            'groupid' => $group->id,
            'role' => Membership::ROLE_MODERATOR,
            'added' => now(),
            'settings' => ['active' => false],
        ]);

        // Create a regular member (should not be included).
        $member = $this->createTestUser();
        Membership::create([
            'userid' => $member->id,
            'groupid' => $group->id,
            'role' => Membership::ROLE_MEMBER,
            'added' => now(),
        ]);

        $activeMods = $group->memberships()->activeModerators()->get();

        $this->assertCount(2, $activeMods);
        $this->assertTrue($activeMods->contains('userid', $activeMod1->id));
        $this->assertTrue($activeMods->contains('userid', $activeMod2->id));
        $this->assertFalse($activeMods->contains('userid', $backupMod->id));
        $this->assertFalse($activeMods->contains('userid', $member->id));
    }
}
