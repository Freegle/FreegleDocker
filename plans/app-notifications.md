# App Notification Features Research

This document summarizes push notification features available on iOS and Android that the Freegle app is **not currently using**.

---

## Implementation Progress

### Dual Notification Strategy

To maintain backwards compatibility during rollout, the server will send **two notifications** for each event:

1. **Legacy notification** (no `channel_id`): For old apps
   - Simple format: "You have 2 new messages"
   - Processed by apps that filter for notifications WITHOUT `channel_id`

2. **New notification** (with `channel_id`): For new apps
   - Rich format: Individual messages, threaded, with images and actions
   - Processed by apps that filter for notifications WITH `channel_id`

**App behavior:**
- **Legacy app** (master): Only processes notifications WITHOUT `channel_id`
- **New app** (PR #1): Only processes notifications WITH `channel_id`

This ensures no duplicate notifications and a clean transition.

### Setup

- [x] **Enable Android Debug Builds on Feature Branches** (iznik-nuxt3) - committed to master
  - [x] Modified `.circleci/config.yml` to run `build-android-debug` on `feature/*` branches
  - [ ] Test: PR triggers Android debug build

### Rollout Order

1. **Legacy app fix** (master) - committed
   - [x] Remove old channels (`PushDefaultForeground`, `NewPosts`)
   - [x] Ignore notifications with `channel_id` (process only legacy)
   - [ ] Release to app stores and wait for rollout

2. **Server update** (PR #2) - after legacy app rollout
   - [ ] Send both legacy AND new notifications
   - [ ] Legacy: no `channel_id`, simple message
   - [ ] New: with `channel_id`, rich content

3. **New app** (PR #1) - after server update
   - [x] Create new channels (`chat_messages`, `social`, etc.)
   - [x] Ignore notifications without `channel_id` (process only new)
   - [ ] Release to app stores

### Android Work

- [x] **PR 1: Android Notification Channels** (iznik-nuxt3) - [PR #120](https://github.com/Freegle/iznik-nuxt3/pull/120)
  - [x] Create channels at startup: `chat_messages`, `social`, `reminders`, `tips`, `new_posts`
  - [x] Only process notifications WITH `channel_id`
  - [x] Test: Verify channels appear in Android settings
  - [x] Test: Notifications still work with default channel

- [ ] **PR 2: Backend - Dual Notification System** (iznik-server) - [PR #31](https://github.com/Freegle/iznik-server/pull/31)
  - [x] Add category config constants to `PushNotifications.php`
  - [x] Add `channel_id` to Android payloads
  - [x] Add `interruption-level` to iOS payloads
  - [x] Add unit tests for new payload structure
  - [ ] Update to send BOTH legacy and new notifications
  - [ ] Test: Old app receives legacy notification only
  - [ ] Test: New app receives new notification only

- [ ] **PR 3: Backend Phase 1 - Grouping** (iznik-server)
  - [ ] Add `thread-id` to payloads for notification grouping
  - [ ] Send full message text (remove truncation)
  - [ ] Add unit tests
  - [ ] Test: Notifications from same chat group together
  - [ ] Test: Old app still works (ignores new fields)

- [ ] **PR 4: Backend Phase 1 - Images** (iznik-server)
  - [ ] Add `image` URL to notification payloads
  - [ ] Determine image source per notification type
  - [ ] Add unit tests
  - [ ] Test: Android shows images in notifications
  - [ ] Test: Old app still works (ignores image field)

- [ ] **PR 5: Android Action Buttons** (iznik-nuxt3)
  - [ ] Handle `category` field from payload
  - [ ] Implement Reply action with text input
  - [ ] Implement Mark Read action
  - [ ] Test: Reply button appears, text input works
  - [ ] Test: Reply sends message successfully

### iOS Work (after Android complete)

- [ ] **PR 6: iOS Notification Service Extension** (iznik-nuxt3)
  - [ ] Add Service Extension for image attachments
  - [ ] Test: Images appear in iOS notifications

- [ ] **PR 7: iOS Action Categories** (iznik-nuxt3)
  - [ ] Register notification categories at startup
  - [ ] Implement Reply action with text input
  - [ ] Test: Reply button appears on notifications

### Future (not in current scope)

- [ ] Notification interaction tracking
- [ ] Rich media for events
- [ ] Event reminder actions

---

## Current Implementation

The app currently uses:
- **Basic push notifications** via `@freegle/capacitor-push-notifications-cap7`
- **Notification channels** (Android): `PushDefaultForeground` for chats, `NewPosts` for new posts
- **Badge counts**: Managed via `@capawesome/capacitor-badge`
- **Inline reply** (Android only): Partial support for `inlineReply` in notification data
- **Deep links**: Route handling from notification data
- **Registration/token handling**: For FCM/APNs

---

## Features NOT Currently Used - Summary

| Feature | Platform | Value | Min Version |
|---------|----------|-------|-------------|
| Rich media attachments (images, GIFs) | Both | High | iOS 10+ / API 16+ |
| Action buttons (incl. text input) | Both | High | iOS 8+ / API 16+ |
| Notification grouping/threading | Both | Medium | iOS 12+ / API 24+ |
| Expandable notifications | Both | Medium | iOS 10+ / API 16+ |
| Categories & interruption levels | iOS (categories on both) | Medium | iOS 10+, iOS 15+ for levels |

---

## Features To Implement

### 1. Rich Media Attachments (Images, GIFs)

**What it does**: Display images, GIFs, or video thumbnails directly in the notification without opening the app.

| Platform | Availability |
|----------|-------------|
| iOS | iOS 10+ (UNNotificationAttachment) |
| Android | API 16+ (BigPictureStyle) |

**Use cases**:
- Show item photos in OFFER/WANTED notifications
- Display user avatars in chat message notifications

**Implementation notes**:
- Requires backend to include `image` URL in notification payload
- iOS needs Notification Service Extension for processing
- Android uses `NotificationCompat.BigPictureStyle`

---

### 2. Action Buttons (Including Text Input)

**What it does**: Add 2-4 buttons users can tap directly from the notification, including a "Reply" button that opens a text input field.

| Platform | Availability |
|----------|-------------|
| iOS | iOS 8+ (up to 4 actions), iOS 9+ for text input |
| Android | API 16+ (up to 3 actions), inline reply already partially implemented |

**Actions for Freegle**:
- **Chat notifications**: "Reply" (with text input), "Mark Read", "View Profile"
- **OFFER notifications**: "Reply", "Save for Later", "Hide"
- **Post expiry warnings**: "Extend", "Mark Complete", "Withdraw"

**User benefit**: Quick responses without opening the app

**Implementation notes**:
- Requires defining action categories in iOS
- iOS: Use `UNTextInputNotificationAction` for Reply button
- Android: Inline reply already partially implemented
- Response comes back in `actionPerformed.inputValue`
- Backend must include `actionTypeId` in payload
- Backend needs endpoint to receive direct replies

---

### 3. Notification Grouping/Threading

**What it does**: Group related notifications together to reduce clutter.

| Platform | Availability |
|----------|-------------|
| iOS | iOS 12+ (threadIdentifier) |
| Android | API 24+ (group/groupSummary) |

**Grouping suggestions**:
- Group by chat conversation
- Group by post type (all OFFERs together)

**User benefit**: Cleaner notification center, easier to find specific notifications

**Implementation notes**:
- Add `threadIdentifier` (iOS) or `group` (Android) to payload
- Minimal code changes, mostly backend payload structure

---

### 4. Expandable Notifications (Big Text/Big Picture)

**What it does**: Show extended content when user pulls down/expands notification.

| Platform | Availability |
|----------|-------------|
| iOS | iOS 10+ |
| Android | API 16+ (BigTextStyle, BigPictureStyle, InboxStyle) |

**Use case**: Chat message notifications - show full message text when expanded.

**Styles to use**:
- **BigTextStyle**: Full message preview for longer chat messages
- **InboxStyle**: Summary when multiple unread messages ("3 new messages from John")

**User benefit**: Read full message without opening app

---

### 5. Notification Categories and Interruption Levels (iOS)

**What it does**: Define categories with different action sets, behaviors, and interruption levels. On iOS 15+, interruption levels control whether notifications bypass Focus Mode or get batched into scheduled summaries.

| Platform | Availability |
|----------|-------------|
| iOS | iOS 10+ (categories), iOS 15+ (interruption levels) |
| Android | Via channels (already implemented) |

**FD notification types and their configuration:**

| Category | Source | iOS (15+) | Android | Suggested Actions |
|----------|--------|-----------|---------|-------------------|
| `CHAT_MESSAGE` | Chat messages (User2User, User2Mod) | Time-sensitive (bypasses Focus Mode) | High importance (heads-up) | Reply (with text input), Mark Read |
| `CHITCHAT_COMMENT` | Someone commented on your ChitChat post | Passive (batched in summary) | Default importance | View |
| `CHITCHAT_REPLY` | Someone replied to a comment you made | Passive (batched in summary) | Default importance | View |
| `CHITCHAT_LOVED` | Someone loved your post or comment | Passive (batched in summary) | Low importance | View |
| `POST_REMINDER` | Autorepost warning, Chaseup | Active (normal) | Default importance | Repost, Complete, Withdraw |
| `NEW_POSTS` | Digest, Relevant posts, Nearby | Passive (batched in summary) | Low importance | View |
| `COLLECTION` | Tryst/calendar collection reminder | Active (normal) | Default importance | View |
| `EVENT_SUMMARY` | Community events digest | Passive (batched in summary) | Low importance | View |
| `EXHORT` | Encouragement to post/engage | Passive (batched in summary) | Low importance | View |

**Platform behavior:**

| Level | iOS 15+ | Android (via channel importance) |
|-------|---------|----------------------------------|
| Time-sensitive | Bypasses Focus Mode and DND | Importance 4: Heads-up notification |
| Active | Normal notification | Importance 3: Sound and appears in tray |
| Passive | Silently added, batched into scheduled summary | Importance 2: No sound, appears in tray |
| - | - | Importance 1: No sound, no visual interruption |

**User benefits**:
- Chat messages break through Focus Mode so users don't miss replies
- Non-urgent notifications (posts, ChitChat) get batched into morning/evening digests

**Implementation notes**:
- Define categories at app startup
- Backend specifies category and `interruptionLevel` in payload
- No special permissions needed for time-sensitive (unlike Critical Alerts)
- User can still disable time-sensitive in iOS settings

---

## Future Enhancements

These features could be added later but are lower priority:

### Notification Interaction Tracking

**What it does**: Track when users interact with push notifications to avoid sending redundant email notifications.

**Current state**:
- Server tracks `seen` flag for newsfeed notifications
- Server tracks `lastseen` per chat room
- No tracking of whether push notification was delivered or acted upon

**What could be tracked**:
- When user taps a notification (`pushNotificationActionPerformed` event)
- When user opens app after receiving notification (app resume with badge > 0)
- FCM/APNs delivery receipts (notification reached device)

**Implementation approach**:

New database table:
```sql
CREATE TABLE users_push_interactions (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    userid BIGINT UNSIGNED NOT NULL,
    notificationid VARCHAR(64) NOT NULL,
    type ENUM('delivered', 'opened', 'action', 'dismissed') NOT NULL,
    actionid VARCHAR(32) NULL,
    timestamp TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX (userid, notificationid),
    INDEX (timestamp)
);
```

New API endpoint `POST /api/pushinteraction`:
```php
$params = [
    'notificationid' => $_POST['notificationid'],
    'type' => $_POST['type'],  // 'opened', 'action', 'dismissed'
    'actionid' => $_POST['actionid']  // e.g., 'reply', 'mark_read'
];
```

Server generates unique notification ID in payload:
```php
$notificationId = 'notif_' . uniqid();
'data' => [
    'notificationId' => $notificationId
]
```

Email scripts check for interactions before sending:
```php
// In chat_notifyemail_user2user.php
$interactions = $dbhr->preQuery(
    "SELECT * FROM users_push_interactions
     WHERE userid = ? AND type IN ('opened', 'action')
     AND timestamp > ?",
    [$userid, $chatMessageTimestamp]
);
if (count($interactions) > 0) {
    // User already saw this via push, skip email
    continue;
}
```

**User benefit**: Fewer redundant emails when user has already seen/acted on notification via the app.

---

### Rich Media for Events

**What it does**: Show event images in notification.

**Use case**: Preview event images in community event notifications.

**Implementation**: Same as rich media attachments above, but for event notification type.

---

### Event Reminder Actions

**What it does**: Action buttons for event reminders.

**Actions**:
- "Going", "Not Going", "View Details"

**Implementation**: Add to action buttons implementation above.

---

## Features We're Not Implementing

These notification features are available but not suitable for Freegle:

| Feature | Platform | Reason |
|---------|----------|--------|
| Live Activities | iOS 16+ | Designed for real-time tracking (deliveries, sports scores). No matching use case in Freegle. |
| Ongoing Notifications | Android | Persistent notifications require foreground service. No use case for always-visible status in Freegle. |
| Custom Notification Sounds | Both | Default system sounds are sufficient. Adding custom sounds adds complexity without significant user benefit. |
| LED/Light Indicators | Android | Most modern phones no longer have notification LEDs. Not worth implementing for diminishing device support. |

---

## Backend Changes Required

The goal is to control notification behavior from the backend as much as possible, avoiding dependency on app updates. FCM and APNs support most features via payload configuration.

### Current Payload Structure

```json
{
  "token": "device_token",
  "data": {
    "badge": "3",
    "count": "3",
    "chatcount": "1",
    "notifcount": "2",
    "title": "New message from John",
    "message": "Hi, I can collect the sofa tomorrow...",
    "chatids": "12345",
    "route": "/chats/12345",
    "modtools": "0"
  },
  "notification": {
    "title": "New message from John",
    "body": "Hi, I can collect the sofa tomorrow..."
  },
  "apns": {
    "payload": {
      "aps": {
        "badge": 3,
        "sound": "default"
      }
    }
  }
}
```

### Proposed Enhanced Payload Structure

```json
{
  "token": "device_token",
  "data": {
    "badge": "3",
    "count": "3",
    "chatcount": "1",
    "notifcount": "2",
    "title": "New message from John",
    "message": "Hi, I can collect the sofa tomorrow...",
    "chatids": "12345",
    "route": "/chats/12345",
    "modtools": "0",
    "category": "CHAT_MESSAGE",
    "threadId": "chat_12345",
    "notificationId": "notif_abc123"
  },
  "notification": {
    "title": "New message from John",
    "body": "Hi, I can collect the sofa tomorrow...",
    "image": "https://images.ilovefreegle.org/timg_12345.jpg"
  },
  "android": {
    "priority": "high",
    "notification": {
      "channel_id": "chat_messages",
      "image": "https://images.ilovefreegle.org/timg_12345.jpg",
      "notification_priority": "PRIORITY_HIGH"
    }
  },
  "apns": {
    "headers": {
      "apns-priority": "10"
    },
    "payload": {
      "aps": {
        "badge": 3,
        "sound": "default",
        "mutable-content": 1,
        "interruption-level": "time-sensitive",
        "thread-id": "chat_12345",
        "category": "CHAT_MESSAGE"
      }
    }
  }
}
```

### Backend Implementation Details

#### 1. Notification Categories (Server-Controlled)

Define categories in `PushNotifications.php` or a new config:

```php
const CATEGORIES = [
    'CHAT_MESSAGE' => [
        'ios_interruption' => 'time-sensitive',
        'android_channel' => 'chat_messages',
        'android_priority' => 'PRIORITY_HIGH',
        'actions' => ['reply', 'mark_read']
    ],
    'CHITCHAT_COMMENT' => [
        'ios_interruption' => 'passive',
        'android_channel' => 'social',
        'android_priority' => 'PRIORITY_DEFAULT',
        'actions' => ['view']
    ],
    'CHITCHAT_REPLY' => [
        'ios_interruption' => 'passive',
        'android_channel' => 'social',
        'android_priority' => 'PRIORITY_DEFAULT',
        'actions' => ['view']
    ],
    'CHITCHAT_LOVED' => [
        'ios_interruption' => 'passive',
        'android_channel' => 'social',
        'android_priority' => 'PRIORITY_LOW',
        'actions' => ['view']
    ],
    'POST_REMINDER' => [
        'ios_interruption' => 'active',
        'android_channel' => 'reminders',
        'android_priority' => 'PRIORITY_DEFAULT',
        'actions' => ['repost', 'complete', 'withdraw']
    ],
    'NEW_POSTS' => [
        'ios_interruption' => 'passive',
        'android_channel' => 'new_posts',
        'android_priority' => 'PRIORITY_LOW',
        'actions' => ['view']
    ],
    'COLLECTION' => [
        'ios_interruption' => 'active',
        'android_channel' => 'reminders',
        'android_priority' => 'PRIORITY_DEFAULT',
        'actions' => ['view']
    ],
    'EVENT_SUMMARY' => [
        'ios_interruption' => 'passive',
        'android_channel' => 'social',
        'android_priority' => 'PRIORITY_LOW',
        'actions' => ['view']
    ],
    'EXHORT' => [
        'ios_interruption' => 'passive',
        'android_channel' => 'tips',
        'android_priority' => 'PRIORITY_LOW',
        'actions' => ['view']
    ]
];
```

#### 2. Android Channels (App Must Create, Server Selects)

The app must create these channels at startup (one-time). Server then specifies which channel to use:

| Channel ID | Name | Importance | Description |
|------------|------|------------|-------------|
| `chat_messages` | Chat Messages | HIGH (4) | Direct messages with other Freeglers |
| `social` | ChitChat & Social | DEFAULT (3) | Comments, replies, and likes |
| `reminders` | Reminders | DEFAULT (3) | Post expiry and other reminders |
| `tips` | Tips & Suggestions | LOW (2) | Encouragement and tips |
| `new_posts` | New Posts | LOW (2) | New OFFERs and WANTEDs nearby |

**App change required (once)**: Create these channels at startup. After that, server controls which channel each notification uses.

#### 3. iOS Interruption Levels (Fully Server-Controlled)

No app changes needed. Server sets in APNs payload:

```php
'apns' => [
    'payload' => [
        'aps' => [
            'interruption-level' => $category['ios_interruption']  // 'passive', 'active', 'time-sensitive'
        ]
    ]
]
```

#### 4. Notification Grouping/Threading (Fully Server-Controlled)

No app changes needed. Server sets thread ID:

```php
// For chat messages - group by chat room
$threadId = 'chat_' . $chatId;

// For ChitChat - group by newsfeed thread
$threadId = 'chitchat_' . $newsfeedThreadId;

// For post notifications - group by type
$threadId = 'posts_offers';  // or 'posts_wanted'
```

Payload:
```php
'apns' => [
    'payload' => [
        'aps' => [
            'thread-id' => $threadId
        ]
    ]
],
'android' => [
    'notification' => [
        'tag' => $threadId  // Groups notifications with same tag
    ]
]
```

#### 5. Rich Media / Images (Fully Server-Controlled)

No app changes needed for basic image support. Server includes image URL:

```php
'notification' => [
    'title' => $title,
    'body' => $message,
    'image' => $imageUrl  // FCM handles download and display
],
'android' => [
    'notification' => [
        'image' => $imageUrl
    ]
]
```

**Image sources by notification type:**
- Chat messages: Sender's profile photo or item photo if discussing a post
- OFFER/WANTED: First item photo thumbnail
- ChitChat: Post author's profile photo
- Events: Event image (future)

**Note**: iOS requires `mutable-content: 1` and a Notification Service Extension to download and attach images. This is an app change.

#### 6. Expandable Notifications (Server-Controlled via Payload Size)

Android BigTextStyle is automatic when body text is long. Server should send full message:

```php
// Don't truncate message for notifications
$message = $fullChatMessage;  // Let Android truncate in collapsed view

'android' => [
    'notification' => [
        'body' => $message  // Android auto-expands if > ~40 chars
    ]
]
```

#### 7. Action Buttons (Requires App + Server Coordination)

**App must define action categories once at startup (iOS) or handle action IDs (Android).**

Server specifies category in payload:
```php
'apns' => [
    'payload' => [
        'aps' => [
            'category' => 'CHAT_MESSAGE'  // iOS looks up registered category
        ]
    ]
],
'data' => [
    'category' => 'CHAT_MESSAGE',  // Android app handles based on this
    'actions' => json_encode(['reply', 'mark_read'])  // Optional hint
]
```

iOS app registers categories at startup:
```swift
let replyAction = UNTextInputNotificationAction(identifier: "reply", title: "Reply")
let markReadAction = UNNotificationAction(identifier: "mark_read", title: "Mark Read")
let chatCategory = UNNotificationCategory(identifier: "CHAT_MESSAGE", actions: [replyAction, markReadAction])
UNUserNotificationCenter.current().setNotificationCategories([chatCategory])
```

### Summary: What Requires App Changes

| Feature | Server Only | App Change (Once) | Ongoing App Changes |
|---------|-------------|-------------------|---------------------|
| Interruption levels (iOS) | ✓ | | |
| Notification grouping | ✓ | | |
| Rich media images | | ✓ (iOS Service Extension) | |
| Android channels | | ✓ (create at startup) | |
| Action buttons | | ✓ (register categories) | |
| Expandable text | ✓ | | |

### Graceful Fallback for Existing Apps

Existing deployed apps will handle enhanced payloads gracefully:

| Feature | Server Sends | Existing App Behavior |
|---------|--------------|----------------------|
| `interruption-level` | APNs field | iOS ignores unknown fields, uses default |
| `thread-id` | APNs field | iOS ignores, no grouping |
| `image` | FCM field | Android shows; iOS ignores without Service Extension |
| `channel_id` | Android field | Falls back to default channel if doesn't exist |
| `category` | APNs field | iOS ignores unknown category, no action buttons |
| New data fields | data object | App ignores unknown fields |

**No breaking changes** - all enhanced fields are additive and ignored by older apps.

For features requiring app support (action buttons, interaction tracking), the notification still displays - just without the enhanced functionality. Users see the message and can tap to open the app.

### Migration Strategy

1. **Phase 1 - Server only changes (no app update needed):**
   - Add interruption levels to iOS payloads
   - Add thread-id for notification grouping
   - Send full message text for expandable notifications
   - Add image URLs to payloads (Android will show, iOS won't until Phase 2)

2. **Phase 2 - One-time app update:**
   - Create additional Android notification channels
   - Add iOS Notification Service Extension for images
   - Register iOS notification categories for action buttons

3. **Phase 3 - Server enhancements (after app update rolled out):**
   - Enable action buttons in payloads
   - Fine-tune channel/category assignments based on user feedback

---

## Emails Sent to Users

This table lists all emails currently sent to Freegle users. Each should be reviewed for whether a push notification strategy would be better or complementary.

| Email Type | Purpose | Current Push? | Notes |
|------------|---------|---------------|-------|
| **Messaging** | | | |
| CHAT | New chat messages from other users | ✓ Yes | Already has push; email is fallback |
| CHAT_CHASEUP_MODS | Follow-up on chat with volunteer | No | Low volume, email sufficient |
| **Post Lifecycle** | | | |
| DIGEST | Digest of new posts on groups | No | Configurable frequency (immediate to daily) |
| AUTOREPOST | Warning that post will be auto-reposted | No | Could add push reminder |
| CHASEUP | "What happened to your post?" follow-up | No | Could add push reminder |
| **Relevant Posts** | | | |
| RELEVANT | Posts matching your interests/searches | No | Could add push summary |
| NEARBY | Post from someone nearby who needs help | No | Could add push notification |
| **Community/Social** | | | |
| NEWSFEED | Comments/replies on your ChitChat posts | ✓ Yes | Already has push |
| NOTIFICATIONS | General notifications (loves, comments) | ✓ Yes | Already has push |
| STORY_ASK | Request to share your Freegle story | No | Infrequent, email only |
| **Events & Volunteering** | | | |
| EVENTS | Community event digest | No | Could add push summary |
| VOLUNTEERING | Volunteering opportunity digest | No | Could add push summary |
| VOLUNTEERING_RENEW | Reminder to renew volunteering post | No | Low volume, email only |
| **Account & Welcome** | | | |
| WELCOME | Welcome email after joining | No | Email is appropriate (not time-critical) |
| FORGOT_PASSWORD | Password reset link | No | Email required (security) |
| VERIFY_EMAIL | Email verification | No | Email required (verification loop) |
| UNSUBSCRIBE | Confirmation of leaving Freegle | No | Email appropriate |
| **Donations & Support** | | | |
| ASK_DONATION | Request for donation after receiving item | No | Post-transaction ask |
| THANK_DONATION | Thanks for donating | No | Confirmation email |
| PLEDGE_SIGNUP | Freegle Pledge signup confirmation | No | Low volume |
| PLEDGE_SUCCESS | Freegle Pledge monthly success | No | Low volume |
| PLEDGE_REMINDER | Freegle Pledge reminder | No | Low volume |
| **Meetup Scheduling** | | | |
| CALENDAR | Tryst calendar invite for collection | No | Could add push reminder |
| **Confirmation/Settings** | | | |
| DIGEST_OFF | Digest turned off confirmation | No | Confirmation only |
| RELEVANT_OFF | Relevant posts turned off | No | Confirmation only |
| EVENTS_OFF | Events digest turned off | No | Confirmation only |
| NEWSLETTER_OFF | Newsletter unsubscribed | No | Confirmation only |
| NEWSFEED_OFF | ChitChat notifications off | No | Confirmation only |
| NOTIFICATIONS_OFF | Notifications turned off | No | Confirmation only |
| STORY_OFF | Story requests turned off | No | Confirmation only |
| FBL_OFF | Email off due to feedback loop | No | Confirmation only |
| **Admin/Special** | | | |
| INVITATION | Invite a friend to Freegle | No | User-initiated |
| REQUEST | Business cards sent | No | Low volume |
| REQUEST_COMPLETED | Business cards order completed | No | Low volume |
| NOTICEBOARD | Noticeboard poster reminders | No | Specific feature |
| NOTICEBOARD_CHASEUP_OWNER | Noticeboard follow-up | No | Low volume |
| MERGE | Account merge notification | No | Rare event |
| SPAM_WARNING | Warning about spammy behavior | No | Rare, email needed for formal record |
| NEWSLETTER | Periodic newsletter | No | Email appropriate |

### Push Notification Opportunities

Based on the email review, these emails would benefit from push notification equivalents:

1. **DIGEST** - New posts digest
   - Push: Summary notification with post count, grouped by type
   - "5 new OFFERs and 2 WANTEDs near you" with preview image
   - Uses `new_posts` channel (already planned)

2. **AUTOREPOST** - Post will be reposted warning
   - Push: Reminder notification with action buttons
   - "Your OFFER 'Blue Sofa' will be reposted tomorrow"
   - Actions: "Mark Complete", "Withdraw"
   - Uses `reminders` channel

3. **CHASEUP** - What happened to your post
   - Push: Reminder with action buttons
   - "What happened with your OFFER 'Blue Sofa'?"
   - Actions: "Repost", "Mark Complete", "Withdraw"
   - Uses `reminders` channel

4. **RELEVANT** - Posts matching your searches
   - Push: Grouped summary notification
   - "3 new items matching your searches"
   - Uses `new_posts` channel with passive interruption level

5. **NEARBY** - Nearby post needing help
   - Push: Standard notification with image
   - "John (0.5 miles away) is looking for a sofa"
   - Uses `new_posts` channel

6. **EVENTS** - Community events digest
   - Push: Summary notification
   - "2 new events near you this week"
   - Uses `social` channel with passive interruption level

7. **VOLUNTEERING** - Volunteering opportunities digest
   - Push: Summary notification
   - "3 new volunteering opportunities near you"
   - Uses `social` channel with passive interruption level

8. **CALENDAR/TRYST** - Collection appointment
   - Push: Time-sensitive reminder
   - "Reminder: Collecting 'Blue Sofa' from Jane at 2pm"
   - Uses `reminders` channel with active interruption level

### Plan Impact

The existing notification category plan already covers CHAT and CHITCHAT well. Additional categories should be added:

| Category | Source | iOS | Android | Actions |
|----------|--------|-----|---------|---------|
| `NEW_POSTS` | DIGEST, RELEVANT, NEARBY | Passive | new_posts (low) | View |
| `POST_REMINDER` | AUTOREPOST, CHASEUP | Active | reminders (default) | Repost, Complete, Withdraw |
| `EVENT_SUMMARY` | EVENTS, VOLUNTEERING | Passive | social (low) | View |
| `COLLECTION` | CALENDAR/TRYST | Active | reminders (default) | View |

These categories fit within the existing plan structure - no major changes needed.

---

## References

- [Apple Push Notification Service](https://developer.apple.com/documentation/usernotifications)
- [Firebase Cloud Messaging](https://firebase.google.com/docs/cloud-messaging)
- [Android Notification Styles](https://developer.android.com/develop/ui/views/notifications/expanded)
- [Capacitor Push Notifications Plugin](https://github.com/Freegle/capacitor-push-notifications-cap7)
