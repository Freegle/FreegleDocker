<?php

namespace Tests\Unit\Models;

use App\Models\Notification;
use App\Models\User;
use Tests\TestCase;

class NotificationModelTest extends TestCase
{
    public function test_notification_can_be_created(): void
    {
        $user = $this->createTestUser();

        $notification = Notification::create([
            'userid' => $user->id,
            'timestamp' => now(),
        ]);

        $this->assertDatabaseHas('notifications', [
            'id' => $notification->id,
            'userid' => $user->id,
        ]);
    }

    public function test_user_relationship(): void
    {
        $user = $this->createTestUser();

        $notification = Notification::create([
            'userid' => $user->id,
            'timestamp' => now(),
        ]);

        $this->assertInstanceOf(User::class, $notification->user);
        $this->assertEquals($user->id, $notification->user->id);
    }

    public function test_from_user_relationship(): void
    {
        $user = $this->createTestUser();
        $fromUser = $this->createTestUser();

        $notification = Notification::create([
            'userid' => $user->id,
            'fromuser' => $fromUser->id,
            'timestamp' => now(),
        ]);

        $this->assertInstanceOf(User::class, $notification->fromUser);
        $this->assertEquals($fromUser->id, $notification->fromUser->id);
    }

    public function test_unseen_scope(): void
    {
        $user = $this->createTestUser();

        // Create unseen notification.
        $unseenNotification = Notification::create([
            'userid' => $user->id,
            'timestamp' => now(),
            'seen' => null,
        ]);

        // Create seen notification.
        $seenNotification = Notification::create([
            'userid' => $user->id,
            'timestamp' => now(),
            'seen' => now(),
        ]);

        $unseenNotifications = Notification::unseen()->get();

        $this->assertTrue($unseenNotifications->contains('id', $unseenNotification->id));
        $this->assertFalse($unseenNotifications->contains('id', $seenNotification->id));
    }

    public function test_recent_scope(): void
    {
        $user = $this->createTestUser();

        // Create recent notification.
        $recentNotification = Notification::create([
            'userid' => $user->id,
            'timestamp' => now()->subHours(2),
        ]);

        // Create old notification.
        $oldNotification = Notification::create([
            'userid' => $user->id,
            'timestamp' => now()->subDays(2),
        ]);

        $recentNotifications = Notification::recent(24)->get();

        $this->assertTrue($recentNotifications->contains('id', $recentNotification->id));
        $this->assertFalse($recentNotifications->contains('id', $oldNotification->id));
    }

    public function test_recent_scope_with_custom_hours(): void
    {
        $user = $this->createTestUser();

        // Create notification 3 hours ago.
        $notification = Notification::create([
            'userid' => $user->id,
            'timestamp' => now()->subHours(3),
        ]);

        // With 2 hours limit, should not include.
        $recentNotifications = Notification::recent(2)->get();
        $this->assertFalse($recentNotifications->contains('id', $notification->id));

        // With 4 hours limit, should include.
        $recentNotifications = Notification::recent(4)->get();
        $this->assertTrue($recentNotifications->contains('id', $notification->id));
    }

    public function test_in_time_range_scope(): void
    {
        $user = $this->createTestUser();

        // Create notification in range.
        $inRangeNotification = Notification::create([
            'userid' => $user->id,
            'timestamp' => now()->subHours(2),
        ]);

        // Create notification outside range.
        $outOfRangeNotification = Notification::create([
            'userid' => $user->id,
            'timestamp' => now()->subDays(2),
        ]);

        $from = now()->subHours(4)->toDateTimeString();
        $to = now()->toDateTimeString();

        $notifications = Notification::inTimeRange($from, $to)->get();

        $this->assertTrue($notifications->contains('id', $inRangeNotification->id));
        $this->assertFalse($notifications->contains('id', $outOfRangeNotification->id));
    }

    public function test_timestamp_is_cast_to_datetime(): void
    {
        $user = $this->createTestUser();

        $notification = Notification::create([
            'userid' => $user->id,
            'timestamp' => now(),
        ]);

        $notification->refresh();

        $this->assertInstanceOf(\Carbon\Carbon::class, $notification->timestamp);
    }

    public function test_seen_is_cast_to_datetime(): void
    {
        $user = $this->createTestUser();

        $notification = Notification::create([
            'userid' => $user->id,
            'timestamp' => now(),
            'seen' => now(),
        ]);

        $notification->refresh();

        $this->assertInstanceOf(\Carbon\Carbon::class, $notification->seen);
    }

    public function test_seen_can_be_null(): void
    {
        $user = $this->createTestUser();

        $notification = Notification::create([
            'userid' => $user->id,
            'timestamp' => now(),
            'seen' => null,
        ]);

        $notification->refresh();

        $this->assertNull($notification->seen);
    }
}
