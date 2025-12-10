# Migration Status

This document tracks progress migrating cron scripts from `iznik-server/scripts/cron/` to Laravel services in this application.

## Status Legend

- **Done** - Fully migrated and tested
- **In Progress** - Partially implemented
- **Not Started** - Not yet begun
- **Skip** - Not needed in Laravel (external tool, deprecated, etc.)

## Scripts Currently In Progress

| Original Script | Frequency | Laravel Service | Status | Notes |
|-----------------|-----------|-----------------|--------|-------|
| `digest.php` | Every 1-5 min (varies by -i flag) | `DigestService` | In Progress | Core functionality implemented |
| `chat_notifyemail_user2user.php` | Every 1 min (0-3,5-23h) | `ChatNotificationService` | In Progress | User-to-user notifications |
| `chat_notifyemail_user2mod.php` | Every 1 min (0-3,5-23h) | `ChatNotificationService` | In Progress | User-to-mod notifications |
| `messages_expired.php` | Every 60 min | `MessageExpiryService` | In Progress | Deadline expiry handling |
| `purge_messages.php` | Daily 03:00 | `PurgeService` | In Progress | Message purging |
| `purge_chats.php` | Daily 01:00 | `PurgeService` | In Progress | Chat purging |
| `purge_logs.php` | Daily 04:00 | `PurgeService` | In Progress | Log purging |
| `donations_email.php` | Hourly 06:00-22:00 | `DonationService` | In Progress | Donation reminders |
| `bounce.php` | Every 2 hours | `UserManagementService` | In Progress | Bounced email handling |

---

## PHPUnit Test Cases for Cron Jobs (iznik-server)

This section documents all PHPUnit test cases in iznik-server that test cron job functionality. Use this as a reference when implementing Laravel equivalents.

<details>
<summary><strong>📧 DigestTest.php - Email Digest Sending</strong></summary>

**Original File:** `iznik-server/test/ut/php/include/DigestTest.php`
**Related Cron:** `digest.php`
**Laravel Service:** `DigestService`

| PHPUnit Test Method | Description | Laravel Equivalent | Status |
|---------------------|-------------|-------------------|--------|
| `testImmediate()` | Tests immediate delivery of digests (mocked sendOne) | `test_send_single_message_digest` | ✅ Covered |
| `testSend()` | Tests digest sending with actual mail generation | `test_send_multiple_message_digest` | ✅ Covered |
| `testTN()` | Tests that TrashNothing users don't receive digests | - | ❌ Not Started |
| `testError()` | Tests error handling when digest sending fails | - | ❌ Not Started |
| `testMultipleMails()` | Tests digest with multiple messages on a group | `test_send_multiple_message_digest` | ✅ Covered |
| `testNearby($withdraw)` | Data provider test for nearby groups (with withdrawn messages) | - | ❌ Not Started |
| `testLongItem()` | Tests digest formatting with long item descriptions | - | ❌ Not Started |

**Laravel Tests Implemented:**
- `test_send_digest_for_closed_group_does_nothing`
- `test_send_digest_with_no_new_messages_does_nothing`
- `test_send_single_message_digest`
- `test_send_multiple_message_digest`
- `test_digest_only_sends_to_members_with_matching_frequency`
- `test_digest_updates_record_with_last_message`
- `test_get_active_groups_returns_freegle_groups`
- `test_get_valid_frequencies`

</details>

<details>
<summary><strong>💬 NotificationsTest.php - User Notifications for Comments/Loves</strong></summary>

**Original File:** `iznik-server/test/ut/php/include/NotificationsTest.php`
**Related Cron:** `notification_chaseup.php`, various notification scripts
**Laravel Service:** Not yet created

| PHPUnit Test Method | Description | Laravel Equivalent | Status |
|---------------------|-------------|-------------------|--------|
| `testEmail()` | Tests notification emails when loved/commented, including about-me notifications | - | ❌ Not Started |
| `testDeleted1()` | Tests notifications disappear when comments are deleted | - | ❌ Not Started |
| `testDeleted2()` | Tests notifications disappear when entire threads are deleted | - | ❌ Not Started |
| `testOff()` | Tests sending notification turn-off emails | - | ❌ Not Started |

</details>

<details>
<summary><strong>📮 BounceTest.php - Email Bounce Processing</strong></summary>

**Original File:** `iznik-server/test/ut/php/include/BounceTest.php`
**Related Cron:** `bounce.php`, `bounce_users.php`
**Laravel Service:** `UserManagementService`

| PHPUnit Test Method | Description | Laravel Equivalent | Status |
|---------------------|-------------|-------------------|--------|
| `testBasic()` | Tests bounce processing: saves bounce, processes it, verifies user logs and mail suspension | `test_process_bounced_emails_marks_invalid` | ✅ Covered |

**Laravel Tests Implemented:**
- `test_process_bounced_emails_marks_invalid`
- `test_process_bounced_emails_ignores_non_bounced`

</details>

<details>
<summary><strong>📊 StatsTest.php - Daily Stats Generation</strong></summary>

**Original File:** `iznik-server/test/ut/php/include/StatsTest.php`
**Related Cron:** `group_stats.php`
**Laravel Service:** Not yet created

| PHPUnit Test Method | Description | Laravel Equivalent | Status |
|---------------------|-------------|-------------------|--------|
| `testBasic()` | Tests daily stats generation for a group (approved message count, member count, breakdowns) | - | ❌ Not Started |
| `testHeatmap()` | Tests generating heatmaps for message/user flow analysis | - | ❌ Not Started |

</details>

<details>
<summary><strong>🤝 VolunteeringDigestTest.php - Volunteering Opportunities Digest</strong></summary>

**Original File:** `iznik-server/test/ut/php/include/VolunteeringDigestTest.php`
**Related Cron:** `volunteering.php`
**Laravel Service:** Not yet created

| PHPUnit Test Method | Description | Laravel Equivalent | Status |
|---------------------|-------------|-------------------|--------|
| `testEvents()` | Tests sending volunteering opportunity digests, handles exceptions, turns off subscriptions, validates email addresses | - | ❌ Not Started |

</details>

<details>
<summary><strong>📅 EventDigestTest.php - Community Events Digest</strong></summary>

**Original File:** `iznik-server/test/ut/php/include/EventDigestTest.php`
**Related Cron:** `events.php`
**Laravel Service:** Not yet created

| PHPUnit Test Method | Description | Laravel Equivalent | Status |
|---------------------|-------------|-------------------|--------|
| `testEvents()` | Tests sending community event digests, exception handling, subscription turn-off, invalid email handling | - | ❌ Not Started |

</details>

<details>
<summary><strong>🚨 AlertsTest.php - Alert Processing</strong></summary>

**Original File:** `iznik-server/test/ut/php/include/AlertsTest.php`
**Related Cron:** `alerts.php`
**Laravel Service:** Not yet created

| PHPUnit Test Method | Description | Laravel Equivalent | Status |
|---------------------|-------------|-------------------|--------|
| `testMultiple()` | Tests alert processing across groups, verifies completion state | - | ❌ Not Started |
| `testErrors()` | Tests error handling when mailing mods and database errors | - | ❌ Not Started |

</details>

<details>
<summary><strong>📱 PostNotificationsTest.php - Post Notifications via Push</strong></summary>

**Original File:** `iznik-server/test/ut/php/include/PostNotificationsTest.php`
**Related Cron:** Background job processing
**Laravel Service:** Not yet created

| PHPUnit Test Method | Description | Laravel Equivalent | Status |
|---------------------|-------------|-------------------|--------|
| `testNoPostsNoNotifications()` | Verifies no notifications when group has no posts | - | ❌ Not Started |
| `testNoAppSubscriptionNoNotification()` | Tests no notifications without app subscriptions | - | ❌ Not Started |
| `testSinglePostNotification()` | Tests notification for single post with message details | - | ❌ Not Started |
| `testMultiplePostsSummaryNotification()` | Tests summary notification for multiple posts | - | ❌ Not Started |
| `testTakenPostsExcluded()` | Tests taken/received posts excluded from notifications | - | ❌ Not Started |
| `testNoNotificationForOwnPosts()` | Tests users don't get notified about their own posts | - | ❌ Not Started |
| `testTrackingSamePostsNotResent()` | Tests frequency tracking prevents duplicate notifications | - | ❌ Not Started |
| `testClosedGroupNoNotifications()` | Tests closed groups don't get notifications | - | ❌ Not Started |
| `testIOSNotification()` | Tests iOS push subscriptions receive notifications | - | ❌ Not Started |
| `testMixedOffersAndWanteds()` | Tests mixed message types in summary notification | - | ❌ Not Started |
| `testDailyFrequencyTiming()` | Tests daily frequency respects timing (24-hour minimum) | - | ❌ Not Started |
| `testRegularUsersReceiveNotifications()` | Tests non-admin users receive notifications | - | ❌ Not Started |

</details>

<details>
<summary><strong>🔔 PushNotificationsTest.php - Push Notification System</strong></summary>

**Original File:** `iznik-server/test/ut/php/include/PushNotificationsTest.php`
**Related Cron:** Background job processing for push notifications
**Laravel Service:** Not yet created

| PHPUnit Test Method | Description | Laravel Equivalent | Status |
|---------------------|-------------|-------------------|--------|
| `testBasic()` | Tests adding push subscriptions (FCM Android, Firefox, Google), notifying users and group mods | - | ❌ Not Started |
| `testExecuteOld()` | Tests executing old-style (non-FCM) push notifications | - | ❌ Not Started |
| `testExecuteFCM()` | Tests executing FCM Android and iOS push notifications | - | ❌ Not Started |
| `testPoke()` | Tests poking users with app notifications | - | ❌ Not Started |
| `testErrors()` | Tests error handling in push notification system | - | ❌ Not Started |
| `testCategoryConstants()` | Tests push notification category constants are defined correctly | - | ❌ Not Started |
| `testNotificationPayloadCategory()` | Tests chat message category with threadId and image | - | ❌ Not Started |
| `testNotificationPayloadChitChatCategory()` | Tests newsfeed notification categories (comment, reply, loved) | - | ❌ Not Started |
| `testExecuteSendWithCategory()` | Tests push sending with category, threadId, and image | - | ❌ Not Started |
| `testDualNotificationSystem()` | Tests dual notification system (legacy + new) for categorized pushes | - | ❌ Not Started |

</details>

<details>
<summary><strong>📬 MailTest.php - Mail Seed List Processing</strong></summary>

**Original File:** `iznik-server/test/ut/php/include/MailTest.php`
**Related Cron:** Mail deliverability monitoring
**Laravel Service:** Not yet created

| PHPUnit Test Method | Description | Laravel Equivalent | Status |
|---------------------|-------------|-------------------|--------|
| `testBasic()` | Tests retrieving seed list entries for mail deliverability monitoring | - | ❌ Not Started |

</details>

---

## Laravel Test Coverage Summary

### Services with Tests Implemented

<details>
<summary><strong>DigestService (8 tests)</strong></summary>

| Test Method | What it Tests |
|-------------|---------------|
| `test_send_digest_for_closed_group_does_nothing` | Closed groups are skipped |
| `test_send_digest_with_no_new_messages_does_nothing` | No messages = no digest |
| `test_send_single_message_digest` | Single message uses SingleDigest mailable |
| `test_send_multiple_message_digest` | Multiple messages use MultipleDigest mailable |
| `test_digest_only_sends_to_members_with_matching_frequency` | Frequency filtering |
| `test_digest_updates_record_with_last_message` | GroupDigest record updated |
| `test_get_active_groups_returns_freegle_groups` | activeFreegle scope |
| `test_get_valid_frequencies` | Valid frequency values |

</details>

<details>
<summary><strong>ChatNotificationService (9 tests)</strong></summary>

| Test Method | What it Tests |
|-------------|---------------|
| `test_notify_sends_email_for_unmailed_message` | Basic email sending |
| `test_notify_skips_already_mailed_messages` | Skip already mailed |
| `test_notify_skips_rejected_messages` | Skip rejected messages |
| `test_notify_with_no_messages_returns_zero` | Empty returns 0 |
| `test_notify_respects_delay` | Delay parameter works |
| `test_force_all_sends_regardless_of_flags` | Force option |
| `test_notify_specific_chat_room` | Process single chat |
| `test_notify_user2mod_type` | User-to-mod notifications |
| `test_updates_roster_last_message_emailed` | Roster record updated |

</details>

<details>
<summary><strong>MessageExpiryService (6 tests)</strong></summary>

| Test Method | What it Tests |
|-------------|---------------|
| `test_process_deadline_expired_marks_message` | Message marked expired |
| `test_process_deadline_expired_sends_email` | Expiry email sent |
| `test_process_deadline_expired_with_no_messages` | Empty returns 0 |
| `test_skips_messages_with_existing_outcome` | Skip completed messages |
| `test_skips_messages_with_future_deadline` | Future deadlines ignored |
| `test_skips_messages_without_deadline` | No deadline = skipped |

</details>

<details>
<summary><strong>PurgeService (8 tests)</strong></summary>

| Test Method | What it Tests |
|-------------|---------------|
| `test_purge_deleted_messages` | Delete old deleted messages |
| `test_purge_pending_messages` | Delete old pending messages |
| `test_purge_spam_chat_messages` | Delete spam chat messages |
| `test_purge_empty_chat_rooms` | Delete empty chat rooms |
| `test_purge_orphaned_chat_images` | Clean up orphaned images |
| `test_purge_unvalidated_emails` | Delete unvalidated emails |
| `test_purge_html_body` | Purge HTML body data |
| `test_run_all_returns_results` | Full purge run |

</details>

<details>
<summary><strong>DonationService (7 tests)</strong></summary>

| Test Method | What it Tests |
|-------------|---------------|
| `test_thank_donors_sends_email` | Thank you email sent |
| `test_thank_donors_skips_already_thanked` | Skip already thanked |
| `test_thank_donors_skips_old_donations` | Skip old donations |
| `test_thank_donors_with_no_donations` | Empty returns 0 |
| `test_ask_for_donations_respects_interval` | Donation ask interval |
| `test_ask_for_donations_with_no_recipients` | No eligible recipients |
| `test_get_stats_returns_monthly_totals` | Stats calculation |

</details>

<details>
<summary><strong>UserManagementService (7 tests)</strong></summary>

| Test Method | What it Tests |
|-------------|---------------|
| `test_process_bounced_emails_marks_invalid` | Bounce processing |
| `test_process_bounced_emails_ignores_non_bounced` | Skip non-bounced |
| `test_cleanup_inactive_users_anonymizes_old_users` | User anonymization |
| `test_cleanup_inactive_users_ignores_recent_users` | Skip recent users |
| `test_merge_duplicates_with_no_duplicates` | Merge with no dups |
| `test_retention_stats_counts_active_users` | Active user counting |
| `test_retention_stats_counts_new_users` | New user counting |

</details>

---

## High Frequency Scripts (Every 1-5 min) - Not Started

| Script | Frequency | Priority | Description |
|--------|-----------|----------|-------------|
| `background.php` | Every 1 min | High | Background job processor |
| `spool.php` | Every 5 min (x20 instances) | High | Outgoing email spool processing |
| `chat_process.php` | Every 1 min | High | Chat message processing |
| `admins.php` | Every 1 min | Medium | Admin notifications |
| `tryst.php` | Every 1 min | Medium | Meeting coordination |
| `memberships_processing.php` | Every 1 min | Medium | Membership processing |
| `donations_ads_target.php` | Every 1 min | Medium | Donation ad targeting |
| `user_exhort.php` | Every 1 min | Medium | User encouragement |
| `lovejunk.php` | Every 1 min | Medium | LoveJunk integration |
| `exports.php` | Every 1 min | Low | Data exports |
| `notification_chaseup.php` | Every 5 min | Medium | Notification reminders |
| `donations_thank.php` | Every 5 min | Medium | Donation thank-you emails |
| `previews.php` | Every 5 min | Medium | Link preview generation |
| `check_cgas.php` | Every 5 min | Low | CGA checking |
| `message_spatial.php` | Every 5 min | Medium | Spatial index updates |
| `messages_illustrations.php` | Every 1 min | Medium | Message illustrations |
| `messages_remap.php` | Every 5 min | Low | Message remapping |
| `chat_expected.php` | Every 5 min | Medium | Expected chat responses |
| `chat_spam.php` | Every 5 min | Medium | Chat spam detection |
| `check_spammers.php` | Every 5 min | Medium | Spam detection |
| `users_modmails.php` | Every 5 min | Medium | Mod mail processing |
| `visualise.php` | Every 5 min | Low | Data visualisation |
| `microvolunteering.php` | Every 5 min | Low | Micro-volunteering |
| `newsfeed_link_previews.php` | Every 1 min | Low | Newsfeed link previews |
| `tn_sync.php` | Every 1 min | Medium | Trash Nothing sync |

## Medium Frequency Scripts (Every 10-60 min) - Not Started

| Script | Frequency | Priority | Description |
|--------|-----------|----------|-------------|
| `donations_giftaid.php` | Every 10 min | Medium | Gift Aid processing |
| `alerts.php` | Every 10 min | Medium | System alerts |
| `user_ratings.php` | Every 10 min | Low | User ratings |
| `eximlogs.php` | Every 10 min | Low | Exim mail logs |
| `whatjobs_spam.php` | Every 10 min | Low | WhatJobs spam |
| `jobs_illustrations.php` | Every 30 min | Low | Job illustrations |
| `message_unindexed.php` | Every 30 min | Low | Unindexed messages |
| `chat_latestmessage.php` | Every 60 min | Low | Chat latest message |
| `pledge.php` | Every 60 min | Low | Pledges |
| `lastacces.php` | Every 59 min | Low | Last access tracking |
| `mod_notifs.php` | Every 60 min | Medium | Moderator notifications |
| `supporttools.php` | Every 60 min | Low | Support tools |
| `membercounts.php` | Every 60 min | Low | Member counts |
| `autorepost.php` | Every 60 min | Medium | Auto-repost messages |
| `chaseup.php` | Every 60 min | Medium | Message chase-up |
| `searchdups.php` | Every 60 min | Low | Search duplicates |
| `autoapprove.php` | Every 60 min | Medium | Auto-approve messages |
| `bounce_users.php` | Every 60 min | Medium | User bounce processing |
| `chatdups.php` | Every 120 min | Low | Chat duplicates |
| `get_app_release_versions.php` | Every 60 min | Low | App versions |

## Daily Scripts - Not Started

| Script | Time | Priority | Description |
|--------|------|----------|-------------|
| `chat_chaseup_expected.php` | 06:00 | Medium | Chat expected response chase-up |
| `birthday.php` | 12:00 | Low | Birthday notifications |
| `relevant.php` | 14:30 | Medium | Relevant message matching |
| `chat_chaseupmods.php` | 15:30 | Medium | Moderator chat chase-up |
| `newsfeed_digest.php` | 15:30 | Low | Newsfeed digest |
| `newsfeed_modnotif.php` | 13:30 | Low | Newsfeed mod notifications |
| `noticeboards.php` | 15:30 | Low | Noticeboards |
| `group_welcomereview.php` | 01:00, 15:00 | Low | Group welcome review |
| `message_deindex.php` | 01:00 | Low | Message de-indexing |
| `group_stats.php` | 02:00 | Low | Group statistics |
| `doogal` | 03:00 | Low | Doogal data import |
| `users_kudos.php` | 03:00 | Low | User kudos |
| `engage_update.php` | 03:00 | Low | Engagement update |
| `purge_sessions.php` | 03:00 | Low | Session purging |
| `email_validate.php` | 04:00 | Low | Email validation |
| `messages_popular.php` | 05:00 | Low | Popular messages |
| `users_remap.php` | 05:00 | Low | User remapping |
| `locations_skewwhiff.php` | 05:00 | Low | Location fixes |
| `nearby.php` | 14:05 | Medium | Nearby items |
| `chat_review.php` | 11:00 | Medium | Chat review queue |
| `engage.php` | 16:00 | Medium | User engagement emails |
| `user_askdonation.php` | 17:00 | Medium | Donation requests |
| `facebook_chaseup.php` | 18:00 | Low | Facebook chase-up |
| `whatjobs.php` | Hourly 08:00-22:00 | Low | WhatJobs |
| `microactions_score.php` | 23:00 | Low | Microactions scoring |
| `restartproject.php` | 23:00 | Low | Restart project |
| `repaircafewales.php` | 23:00 | Low | Repair Cafe Wales |
| `archive_attachments.php` | 22:30 | Low | Attachment archiving |

## Weekly Scripts - Not Started

| Script | Schedule | Priority | Description |
|--------|----------|----------|-------------|
| `events.php` | Thu 23:00 | Low | Community events email |
| `volunteering.php` | Mon 23:00 | Low | Volunteering opportunities email |
| `stories.php` | Sat 11:00 | Low | Success story requests |
| `groups_closed.php` | Sun 08:00 | Low | Closed groups check |
| `stories_tocentral.php` | Fri 14:00 | Low | Stories to central |
| `domains_common.php` | Fri 07:00 | Low | Common domains |
| `git_summary_ai.php` | Fri 07:38 | Skip | Development tool |
| `mod_active.php` | Mon 15:00 | Low | Active moderators |

## Monthly Scripts - Not Started

| Script | Schedule | Priority | Description |
|--------|----------|----------|-------------|
| `stories_newsletter.php` | 12th 23:00 | Low | Stories newsletter |
| `users_retention.php` | 27th 11:00 | Low | User retention |
| `lovejunk_tn_invoice.php` | 1st 15:00 | Low | LoveJunk/TN invoice |

## Scripts to Skip

| Script | Reason |
|--------|--------|
| `sa_train` | SpamAssassin training - external |
| `cron_checker_iznik.php` | Monitoring - external tool |
| `discourse_checkusers.php` | Discourse integration - separate system |
| `discourse_not_signed_up.php` | Discourse integration - separate system |
| `git_summary_ai.php` | Development tool |
| `locations_pgsql` | PostgreSQL locations - external |
| `doogal` | External data import script |
| `eximlogs.php` | Mail server logs - external |
| `facebook_share.php` | Disabled in crontab |
| `tweet_*.php` | Twitter - commented out |
| `sms.php` | Retired |
| `badnumber.php` | Retired |
| `spam_toddlers.php` | Commented out |

## Known Issues

### Schema Discrepancies

When migrating, we've found cases where the database schema (from migration generator) differs from iznik-server constants. Always verify against iznik-server source code.

Example: `messages_outcomes.outcome` enum - the migration generator may not capture all values defined in `Message::OUTCOME_*` constants. The iznik-server defines:
- `OUTCOME_TAKEN`
- `OUTCOME_RECEIVED`
- `OUTCOME_WITHDRAWN`
- `OUTCOME_REPOST`
- `OUTCOME_EXPIRED`
- `OUTCOME_PARTIAL`

## Adding New Migrations

When starting work on a new script:

1. Update this file to mark it "In Progress"
2. Read the original PHP script in `iznik-server/scripts/cron/`
3. Check related PHPUnit tests in `iznik-server/test/ut/php/` (see sections above)
4. Verify any constants/enums against iznik-server source
5. Write tests first based on expected behavior
6. Implement the service
7. Update status to "Done" when tests pass
