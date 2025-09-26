# Current System Analysis

## Email Infrastructure Overview

### Current Cron Jobs (From Codebase Analysis)
1. **spool.php** - Core email queue processor using Swift_FileSpool
2. **chat_notifyemail_user2user.php** - User-to-user chat notifications (every minute, 30min batching)
3. **chat_notifyemail_user2mod.php** - User-to-moderator notifications (every minute)
4. **digest.php** - Group digest emails (configurable intervals)
5. **donations_email.php** - Daily donation summary emails
6. **users_modmails.php** - Mod email roster updates
7. **email_validate.php** - Email validation processing
8. **background.php** - General background processing
9. **exports.php** - Data export notifications
10. **memberships_processing.php** - Membership change notifications

### Email Template System
- **35+ MJML templates** in organized directory structure
- **Template categories**:
  - Core system (verify, welcome, admin)
  - Digest emails (single, multiple, events, volunteering)
  - Notifications (chat, bounces, alerts)
  - Donation/fundraising emails
  - Story and engagement emails
  - Noticeboard communications
- **Processing**: MJML → HTML → Twig template expansion
- **Issues**: Inconsistent construction methods, poor text email variants

### Email Processing Flow
```
PHP Cron Jobs → Swift Mailer → Spool Files → Background Processor → SMTP
```

### Performance Characteristics
- **Volume**: Several hundred thousand emails per day
- **Spooling**: Multiple spooler instances (Admin::SPOOLERS=4, Digest::SPOOLERS=10)
- **Reliability**: Spool file approach ensures local disk availability
- **Optimization**: "Big ugly queries" for performance reasons

## Current User Engagement System

### Engagement Classifications (from Engage.php)
- **ENGAGEMENT_NEW**: Recently joined users (< 31 days)
- **ENGAGEMENT_OCCASIONAL**: Light activity, recent access
- **ENGAGEMENT_FREQUENT**: Regular posting and participation
- **ENGAGEMENT_OBSESSED**: High-frequency users
- **ENGAGEMENT_INACTIVE**: No recent activity (14+ days)
- **ENGAGEMENT_ATRISK**: Declining engagement patterns
- **ENGAGEMENT_DORMANT**: Long-term inactive (6+ months)

### Engagement Update Logic
- **New users**: Added within lookback period (31 days)
- **Activity-based transitions**: Based on lastaccess and post/reply activity
- **Time-based degradation**: Automatic movement to less engaged states
- **Reactivation**: Users can move back to more engaged states

### Current Metrics Used
- **Last access time**: Platform login activity
- **Post frequency**: Message creation and replies
- **Chat activity**: User-to-user and user-to-mod interactions
- **Time-based thresholds**: 14 days, 31 days, 6 months

## Current Tracking Infrastructure

### alerts_tracking Table
```sql
CREATE TABLE `alerts_tracking` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `alertid` bigint unsigned NOT NULL,
  `groupid` bigint unsigned DEFAULT NULL,
  `userid` bigint unsigned DEFAULT NULL,
  `emailid` bigint unsigned DEFAULT NULL,
  `type` enum('ModEmail','OwnerEmail','PushNotif','ModToolsNotif'),
  `sent` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `responded` timestamp NULL DEFAULT NULL,
  `response` enum('Read','Clicked','Bounce','Unsubscribe'),
  PRIMARY KEY (`id`)
);
```

### Current Tracking Capabilities
- **Notification types**: ModEmail, OwnerEmail, PushNotif, ModToolsNotif
- **Response tracking**: Read, Clicked, Bounce, Unsubscribe
- **Association**: Links to users, emails, groups, alerts
- **Beacon tracking**: URLs for read/click detection

### Email Configuration Complexity
- **User preferences**: Basic choice (standard/minimal/none)
- **Group-level controls**: Advanced per-group email type settings
- **Storage**: Group settings in JSON columns, user preferences in user records
- **Frequency controls**: Per-group digest intervals and email types

## Current Email Types & Processing

### Email Constants (from Mail.php)
- **Core System**: VERIFY_EMAIL, WELCOME, FORGOT_PASSWORD
- **Notifications**: CHAT, DIGEST, NEARBY, RELEVANT
- **Engagement**: NEWSLETTER, STORY_ASK, VOLUNTEERING
- **Administrative**: ADMIN, BOUNCE, MODMAIL
- **Donations**: ASK_DONATION, THANK_DONATION
- **Special**: BIRTHDAY, LIMBO, PLEDGE_*

### Processing Patterns
- **Immediate**: Chat notifications, system emails
- **Batched**: Digest emails (hourly to daily intervals)
- **Scheduled**: Weekly/monthly engagement emails
- **Event-driven**: User action triggers (registration, donations)

## Current Limitations

### Administration Complexity
- Multiple separate cron jobs to manage
- No unified view of email sending status
- Difficult to coordinate cross-email timing
- Manual email campaign management

### Limited Personalization
- Basic frequency controls only
- No sophisticated user segmentation
- No A/B testing capabilities
- Static template content

### Analytics Gaps
- Limited visibility into email performance
- No cross-channel attribution
- No automated optimization
- Minimal moderator access to email logs

### Technical Debt
- Inconsistent email construction methods
- Poor text email generation
- No unified notification API
- Legacy Swift Mailer dependencies

## Opportunities for Improvement

### Immediate Wins
- Unified cron job management
- Enhanced moderator visibility
- Consistent template processing
- Better error handling and monitoring

### Strategic Enhancements
- Intelligent content optimization
- Cross-channel coordination
- Advanced user segmentation
- Real-time personalization

### Long-term Vision
- Predictive engagement modeling
- Automated lifecycle campaigns
- Cross-platform notification unification
- AI-driven content generation