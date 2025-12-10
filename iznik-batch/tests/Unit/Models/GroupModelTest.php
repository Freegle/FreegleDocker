<?php

namespace Tests\Unit\Models;

use App\Models\Group;
use Tests\TestCase;

class GroupModelTest extends TestCase
{
    public function test_freegle_scope_filters_freegle_groups(): void
    {
        // Create Freegle group.
        $freegleGroup = $this->createTestGroup();

        // Create non-Freegle group.
        Group::create([
            'nameshort' => 'NonFreegle',
            'namefull' => 'Non Freegle Group',
            'type' => 'Other',
            'region' => 'TestRegion',
            'lat' => 51.5,
            'lng' => -0.1,
        ]);

        $freegleGroups = Group::freegle()->get();

        $this->assertEquals(1, $freegleGroups->count());
        $this->assertEquals($freegleGroup->id, $freegleGroups->first()->id);
    }

    public function test_is_closed_detects_closed_groups(): void
    {
        $closedGroup = Group::create([
            'nameshort' => 'ClosedGroup',
            'namefull' => 'Closed Group',
            'type' => Group::TYPE_FREEGLE,
            'region' => 'TestRegion',
            'lat' => 51.5,
            'lng' => -0.1,
            'settings' => ['closed' => true],
        ]);

        $openGroup = $this->createTestGroup();

        $this->assertTrue($closedGroup->isClosed());
        $this->assertFalse($openGroup->isClosed());
    }

    public function test_active_freegle_scope_excludes_closed(): void
    {
        // Create open group.
        $openGroup = $this->createTestGroup();

        // Create closed group.
        Group::create([
            'nameshort' => 'ClosedGroup',
            'namefull' => 'Closed Group',
            'type' => Group::TYPE_FREEGLE,
            'region' => 'TestRegion',
            'lat' => 51.5,
            'lng' => -0.1,
            'onhere' => 1,
            'publish' => 1,
            'settings' => ['closed' => true],
        ]);

        $activeGroups = Group::activeFreegle()->get();

        // Only open group should be returned.
        $this->assertEquals(1, $activeGroups->count());
        $this->assertEquals($openGroup->id, $activeGroups->first()->id);
    }

    public function test_group_has_memberships_relationship(): void
    {
        $group = $this->createTestGroup();
        $user = $this->createTestUser();
        $this->createMembership($user, $group);

        $this->assertEquals(1, $group->memberships()->count());
    }

    public function test_group_settings_are_json_cast(): void
    {
        $group = Group::create([
            'nameshort' => 'TestGroup',
            'namefull' => 'Test Group',
            'type' => Group::TYPE_FREEGLE,
            'region' => 'TestRegion',
            'lat' => 51.5,
            'lng' => -0.1,
            'settings' => ['repost' => 7, 'maxage' => 14],
        ]);

        $settings = $group->settings;

        $this->assertIsArray($settings);
        $this->assertEquals(7, $settings['repost']);
        $this->assertEquals(14, $settings['maxage']);
    }
}
