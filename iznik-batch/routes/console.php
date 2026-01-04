<?php

use Illuminate\Support\Facades\Schedule;

/**
 * Define the application's command schedule.
 *
 * IMPORTANT: Most commands are disabled for now. Only enable when ready to go live.
 * Commands are gradually being enabled as we migrate from iznik-server crontab.
 */

// =============================================================================
// ACTIVE SCHEDULED COMMANDS
// =============================================================================

// Welcome mail processing - check for pending welcome mails every minute.
// Uses withoutOverlapping() to prevent duplicate runs if processing is slow.
// Uses runInBackground() so it doesn't block other scheduled commands.
// Uses --spool to write to file for resilient async processing.
Schedule::command('mail:welcome:send --limit=100 --spool')
    ->everyMinute()
    ->withoutOverlapping()
    ->runInBackground();

// Chat notifications - run continuously with internal looping.
// User2User notifications.
Schedule::command('mail:chat:user2user --max-iterations=60 --spool')
    ->everyMinute()
    ->withoutOverlapping()
    ->runInBackground();

// Fetch UK CPI inflation data from ONS - runs monthly.
// Used to inflation-adjust the "benefit of reuse" value from the 2011 WRAP report.
// Sends alert email to GeekAlerts if fetch fails.
Schedule::command('data:update-cpi')
    ->monthly()
    ->withoutOverlapping()
    ->runInBackground();

// =============================================================================
// DISABLED COMMANDS (to be enabled when ready)
// =============================================================================

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

// User2Mod notifications.
Schedule::command('mail:chat:user2mod --max-iterations=60 --spool')
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
    ->withoutOverlapping()
    ->runInBackground();

Schedule::command('mail:donations:ask')
    ->dailyAt('17:00')
    ->withoutOverlapping()
    ->runInBackground();

// User management commands.
Schedule::command('users:update-kudos')
    ->dailyAt('04:00')
    ->withoutOverlapping()
    ->runInBackground();

Schedule::command('mail:bounced')
    ->hourly()
    ->withoutOverlapping()
    ->runInBackground();

Schedule::command('users:retention-stats')
    ->weekly()
    ->sundays()
    ->at('06:00')
    ->withoutOverlapping()
    ->runInBackground();

// Email spool processing - runs continuously in daemon mode via supervisor.
// See docker/supervisor.conf for the mail-spooler program.
*/

// Clean up old sent emails - run daily.
Schedule::command('mail:spool:process --cleanup --cleanup-days=7')
    ->dailyAt('04:00')
    ->withoutOverlapping()
    ->runInBackground();

// =============================================================================
// GIT SUMMARY
// =============================================================================

// Git summary - weekly on Wednesday at 6pm UTC.
// Sends AI-powered summary of code changes to Discourse.
Schedule::command('data:git-summary')
    ->weeklyOn(3, '18:00')  // Wednesday at 6pm UTC
    ->withoutOverlapping()
    ->runInBackground();

// Note: App release classification is now handled directly in CircleCI.
// The check-hotfix-promote job runs after beta builds and triggers
// immediate promotion if the commit message has hotfix: prefix.
// See iznik-nuxt3/.circleci/config.yml
