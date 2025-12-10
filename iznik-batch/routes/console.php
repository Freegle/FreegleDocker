<?php

use Illuminate\Support\Facades\Schedule;

/**
 * Define the application's command schedule.
 *
 * Digest commands run at different intervals based on frequency.
 * Chat notification commands run continuously with internal timing.
 */

// Immediate digests (-1) - run every minute.
Schedule::command('freegle:digest:send -1')
    ->everyMinute()
    ->withoutOverlapping()
    ->runInBackground();

// Hourly digests - run every hour.
Schedule::command('freegle:digest:send 1')
    ->hourly()
    ->withoutOverlapping()
    ->runInBackground();

// Every 2 hours digests.
Schedule::command('freegle:digest:send 2')
    ->everyTwoHours()
    ->withoutOverlapping()
    ->runInBackground();

// Every 4 hours digests.
Schedule::command('freegle:digest:send 4')
    ->everyFourHours()
    ->withoutOverlapping()
    ->runInBackground();

// Every 8 hours digests (3 times per day).
Schedule::command('freegle:digest:send 8')
    ->cron('0 0,8,16 * * *')
    ->withoutOverlapping()
    ->runInBackground();

// Daily digests.
Schedule::command('freegle:digest:send 24')
    ->dailyAt('08:00')
    ->withoutOverlapping()
    ->runInBackground();

// Chat notifications - run continuously with internal looping.
// User2User notifications.
Schedule::command('freegle:chat:notify-user2user --max-iterations=60')
    ->everyMinute()
    ->withoutOverlapping()
    ->runInBackground();

// User2Mod notifications.
Schedule::command('freegle:chat:notify-user2mod --max-iterations=60')
    ->everyMinute()
    ->withoutOverlapping()
    ->runInBackground();

// Message expiry - run daily.
Schedule::command('freegle:messages:process-expired --spatial')
    ->dailyAt('03:00')
    ->withoutOverlapping()
    ->runInBackground();

// Purge operations - run daily at off-peak hours.
Schedule::command('freegle:purge:chats')
    ->dailyAt('02:00')
    ->withoutOverlapping()
    ->runInBackground();

Schedule::command('freegle:purge:messages')
    ->dailyAt('02:30')
    ->withoutOverlapping()
    ->runInBackground();

// Donation-related commands.
Schedule::command('freegle:donations:thank')
    ->dailyAt('09:00')
    ->withoutOverlapping();

Schedule::command('freegle:donations:ask')
    ->dailyAt('17:00')
    ->withoutOverlapping();

// User management commands.
Schedule::command('freegle:users:update-kudos')
    ->dailyAt('04:00')
    ->withoutOverlapping()
    ->runInBackground();

Schedule::command('freegle:users:process-bounced')
    ->hourly()
    ->withoutOverlapping();

Schedule::command('freegle:users:retention-stats')
    ->weekly()
    ->sundays()
    ->at('06:00');
