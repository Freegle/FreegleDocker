<?php

use Illuminate\Support\Facades\Schedule;

/**
 * Define the application's command schedule.
 *
 * IMPORTANT: Most commands are disabled for now. Only enable when ready to go live.
 * The welcome mail is not scheduled - it's sent in response to user signup via API.
 */

// TODO: Enable these commands when ready to go live with Laravel batch processing.
// For now, all scheduled tasks remain in iznik-server crontab.

/*
// Immediate digests (-1) - run every minute.
Schedule::command('mail:digest -1')
    ->everyMinute()
    ->withoutOverlapping()
    ->runInBackground();

// Hourly digests - run every hour.
Schedule::command('mail:digest 1')
    ->hourly()
    ->withoutOverlapping()
    ->runInBackground();

// Every 2 hours digests.
Schedule::command('mail:digest 2')
    ->everyTwoHours()
    ->withoutOverlapping()
    ->runInBackground();

// Every 4 hours digests.
Schedule::command('mail:digest 4')
    ->everyFourHours()
    ->withoutOverlapping()
    ->runInBackground();

// Every 8 hours digests (3 times per day).
Schedule::command('mail:digest 8')
    ->cron('0 0,8,16 * * *')
    ->withoutOverlapping()
    ->runInBackground();

// Daily digests.
Schedule::command('mail:digest 24')
    ->dailyAt('08:00')
    ->withoutOverlapping()
    ->runInBackground();

// Chat notifications - run continuously with internal looping.
// User2User notifications.
Schedule::command('mail:chat:user2user --max-iterations=60')
    ->everyMinute()
    ->withoutOverlapping()
    ->runInBackground();

// User2Mod notifications.
Schedule::command('mail:chat:user2mod --max-iterations=60')
    ->everyMinute()
    ->withoutOverlapping()
    ->runInBackground();

// Message expiry - run daily.
Schedule::command('messages:process-expired --spatial')
    ->dailyAt('03:00')
    ->withoutOverlapping()
    ->runInBackground();

// Purge operations - run daily at off-peak hours.
Schedule::command('purge:chats')
    ->dailyAt('02:00')
    ->withoutOverlapping()
    ->runInBackground();

Schedule::command('purge:messages')
    ->dailyAt('02:30')
    ->withoutOverlapping()
    ->runInBackground();

// Donation-related commands.
Schedule::command('mail:donations:thank')
    ->dailyAt('09:00')
    ->withoutOverlapping();

Schedule::command('mail:donations:ask')
    ->dailyAt('17:00')
    ->withoutOverlapping();

// User management commands.
Schedule::command('users:update-kudos')
    ->dailyAt('04:00')
    ->withoutOverlapping()
    ->runInBackground();

Schedule::command('mail:bounced')
    ->hourly()
    ->withoutOverlapping();

Schedule::command('users:retention-stats')
    ->weekly()
    ->sundays()
    ->at('06:00');
*/
