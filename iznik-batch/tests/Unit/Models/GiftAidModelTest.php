<?php

namespace Tests\Unit\Models;

use App\Models\GiftAid;
use Tests\TestCase;

class GiftAidModelTest extends TestCase
{
    public function test_gift_aid_can_be_created(): void
    {
        $user = $this->createTestUser();

        $giftAid = GiftAid::create([
            'userid' => $user->id,
            'period' => GiftAid::PERIOD_FUTURE,
            'timestamp' => now(),
            'fullname' => 'Test User',
            'homeaddress' => '123 Test St',
        ]);

        $this->assertDatabaseHas('giftaid', ['id' => $giftAid->id]);
    }

    public function test_user_relationship(): void
    {
        $user = $this->createTestUser();

        $giftAid = GiftAid::create([
            'userid' => $user->id,
            'period' => GiftAid::PERIOD_FUTURE,
            'timestamp' => now(),
            'fullname' => 'Test User',
            'homeaddress' => '123 Test St',
        ]);

        $this->assertEquals($user->id, $giftAid->user->id);
    }

    public function test_is_declined(): void
    {
        $user1 = $this->createTestUser();
        $user2 = $this->createTestUser();

        $declined = GiftAid::create([
            'userid' => $user1->id,
            'period' => GiftAid::PERIOD_DECLINED,
            'timestamp' => now(),
            'fullname' => 'Test User',
            'homeaddress' => '123 Test St',
        ]);

        $active = GiftAid::create([
            'userid' => $user2->id,
            'period' => GiftAid::PERIOD_FUTURE,
            'timestamp' => now(),
            'fullname' => 'Test User',
            'homeaddress' => '123 Test St',
        ]);

        $this->assertTrue($declined->isDeclined());
        $this->assertFalse($active->isDeclined());
    }

    public function test_is_active(): void
    {
        $user1 = $this->createTestUser();
        $user2 = $this->createTestUser();
        $user3 = $this->createTestUser();

        $active = GiftAid::create([
            'userid' => $user1->id,
            'period' => GiftAid::PERIOD_FUTURE,
            'timestamp' => now(),
            'fullname' => 'Test User',
            'homeaddress' => '123 Test St',
            'deleted' => null,
        ]);

        $declined = GiftAid::create([
            'userid' => $user2->id,
            'period' => GiftAid::PERIOD_DECLINED,
            'timestamp' => now(),
            'fullname' => 'Test User',
            'homeaddress' => '123 Test St',
            'deleted' => null,
        ]);

        $deleted = GiftAid::create([
            'userid' => $user3->id,
            'period' => GiftAid::PERIOD_FUTURE,
            'timestamp' => now(),
            'fullname' => 'Test User',
            'homeaddress' => '123 Test St',
            'deleted' => now(),
        ]);

        $this->assertTrue($active->isActive());
        $this->assertFalse($declined->isActive());
        $this->assertFalse($deleted->isActive());
    }

    public function test_period_constants(): void
    {
        $this->assertEquals('This', GiftAid::PERIOD_THIS);
        $this->assertEquals('Since', GiftAid::PERIOD_SINCE);
        $this->assertEquals('Future', GiftAid::PERIOD_FUTURE);
        $this->assertEquals('Declined', GiftAid::PERIOD_DECLINED);
        $this->assertEquals('Past4YearsAndFuture', GiftAid::PERIOD_PAST4_YEARS_AND_FUTURE);
    }
}
