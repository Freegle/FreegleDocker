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

// Deployment watch - detect code updates and auto-refresh application.
// Checks version.txt every minute; triggers deploy:refresh when version changes
// and file is at least 5 minutes old (to ensure upload is complete).
Schedule::command('deploy:watch')
    ->everyMinute()
    ->withoutOverlapping()
    ->runInBackground();

// Welcome mail processing - check for pending welcome mails every minute.
// Uses PreventsOverlapping trait for flock-based locking (released on process death).
// Uses runInBackground() so it doesn't block other scheduled commands.
// Uses --spool to write to file for resilient async processing.
Schedule::command('mail:welcome:send --limit=100 --spool')
    ->everyMinute()
    ->runInBackground();

// Chat notifications - run continuously with internal looping.
// Uses PreventsOverlapping trait for flock-based locking (released on process death).
// User2User notifications.
Schedule::command('mail:chat:user2user --max-iterations=60 --spool')
    ->everyMinute()
    ->runInBackground();

// Mod2Mod notifications.
Schedule::command('mail:chat:mod2mod --max-iterations=60 --spool')
    ->everyMinute()
    ->runInBackground();

// User2Mod notifications.
Schedule::command('mail:chat:user2mod --max-iterations=60 --spool')
    ->everyMinute()
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

// Unified digest - replaces per-group digests.
// Daily mode - sends one digest per user with posts from all their communities.
Schedule::command('mail:digest:unified --mode=daily')
    ->dailyAt('08:00')
    ->withoutOverlapping()
    ->runInBackground();

// Immediate mode - sends notifications for users who want instant alerts.
Schedule::command('mail:digest:unified --mode=immediate')
    ->everyMinute()
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

// Background task queue - processes tasks queued by Go API server.
// Runs continuously with internal looping. Handles push notifications and emails.
Schedule::command('queue:background-tasks --max-iterations=60 --spool')
    ->everyMinute()
    ->runInBackground();

// Clean up old sent emails - run daily.
Schedule::command('mail:spool:process --cleanup --cleanup-days=7')
    ->dailyAt('04:00')
    ->withoutOverlapping()
    ->runInBackground();

// Clean up incoming email archives older than 48 hours.
Schedule::command('mail:cleanup-archive')
    ->hourly()
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
