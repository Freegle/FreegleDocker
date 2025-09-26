# Cron Job Frequency Analysis & Email Load Assessment

## Current Email Sending Frequencies

### **High Frequency Email Jobs** (Every 1-5 minutes)
```bash
# Email Spooling (Critical Infrastructure)
*/5 * * * * spool.php                    # Every 5 minutes - main spool
*/5 * * * * spool.php -n /spool_1        # Every 5 minutes - spool 1
*/5 * * * * spool.php -n /spool_2        # Every 5 minutes - spool 2
... (10 member spools + 10 admin spools) # 20 total spools
```
**Impact**: 20 separate spool processors running every 5 minutes = **240 email processing jobs per hour**

### **Chat & Transactional Emails** (Every 1 minute)
```bash
*/1 * * * * chat_notifyemail_user2mod.php     # Every minute (0-3,5-23 hours)
*/1 * * * * chat_notifyemail_user2user.php    # Every minute (0-3,5-23 hours)
*/5 * * * * notification_chaseup.php          # Every 5 minutes
*/5 * * * * donations_thank.php               # Every 5 minutes
```
**Impact**: ~**1,400+ transactional emails processed per day**

### **Digest Campaigns** (Multiple frequencies)
```bash
*/1 * * * * digest.php -i -1                  # Every minute - immediate digest
*/5 * * * * digest.php -i 1                   # Every 5 minutes - 1 hour digest
*/5 * * * * digest.php -i 2                   # Every 5 minutes - 2 hour digest
*/5 * * * * digest.php -i 4                   # Every 5 minutes - 4 hour digest
*/5 * * * * digest.php -i 8                   # Every 5 minutes - 8 hour digest
*/5 * * * * digest.php -i 24 -m 2 -v 0        # Every 5 minutes - 24 hour (split load)
*/5 * * * * digest.php -i 24 -m 2 -v 1        # Every 5 minutes - 24 hour (split load)
```
**Impact**: **7 digest processors** running every 1-5 minutes = potential for massive email volume

### **Scheduled Campaign Emails** (Daily/Weekly)
```bash
00 12 * * * birthday.php                      # Daily at noon
00 17 * * * user_askdonation.php             # Daily at 5pm
30 15 * * * newsfeed_digest.php              # Daily at 3:30pm
00 15 * * MON mod_active.php                 # Weekly Monday at 3pm
00 23 * * 4 events.php                       # Weekly Thursday at 11pm
00 23 * * 1 volunteering.php                 # Weekly Monday at 11pm
```

### **Hourly Email Jobs**
```bash
*/60 * * * * mod_notifs.php                   # Every hour
*/60 * * * * supporttools.php                # Every hour
00 6-22 * * * donations_email.php            # Hourly 6am-10pm
```

## Current Email Volume Estimation

### **Per User Daily Email Potential:**
Based on cron frequencies, a single active user could theoretically receive:

1. **Transactional (unlimited)**: Chat notifications, donation confirmations, etc.
2. **Digest emails**: Up to 7 different digest frequencies if subscribed to all
3. **Scheduled campaigns**: Birthday (if applicable), donation asks, newsfeed digest
4. **Mod notifications**: If moderator - hourly pending work summaries

**Total potential**: **10+ emails per day** for highly active users/moderators

### **System-Wide Email Load:**
- **20 spool processors** × every 5 minutes = 5,760 processing cycles/day
- **7 digest variants** × every 5 minutes = 2,016 digest cycles/day
- **Chat notifications** × every minute × 22 hours = 2,640 cycles/day
- **Scheduled emails** = ~10 campaigns/day

**Total**: **~10,000+ email processing cycles per day**

## Cross-Campaign Collision Analysis

### **Peak Collision Hours:**
```
Hour 15 (3pm): newsfeed_digest + mod_active (Mon) + group_welcomereview
Hour 17 (5pm): user_askdonation + regular digests
Hour 23 (11pm): events (Thu) + volunteering (Mon) + multiple maintenance jobs
Hour 12 (noon): birthday emails + regular digests + donations_email
```

### **High-Risk User Segments:**
1. **Active Moderators**: Could receive mod_notifs + chat_notifyemail + digest + birthday + donation_ask + newsfeed_digest = **6+ emails same day**
2. **New Users**: welcome sequences + digest subscriptions + birthday (if applicable) = **3-4 emails**
3. **Donors**: Thank you + gift aid + ask donation + regular digests = **4+ emails**

### **Current Frequency Management:**
```php
// From analysis of mod_notifs.php
$activeminage = $u->getSetting('modnotifs', 4);        // Default 4 hours between mod emails
$backupminage = $u->getSetting('backupmodnotifs', 12); // Default 12 hours for backup mods

// From digest.php analysis
$interval = $opts['i']; // -1, 1, 2, 4, 8, 24 hour intervals
```

**Current Limitation**: No cross-campaign coordination - each cron job operates independently

## Framework Requirements Based on Current System

### **1. Spool Management Integration**
```yaml
Current: 20 separate spools (member + admin)
Framework: Single Laravel queue with priority levels
Migration: Map existing spools to queue priorities
```

### **2. Digest Consolidation Opportunity**
```yaml
Current: 7 separate digest processes with overlapping intervals
Framework: Single intelligent digest with personalized frequency
Optimization: Bandit testing on digest frequency per user segment
```

### **3. Transactional Email Passthrough**
```yaml
Current: Chat notifications every minute
Framework: Immediate send bypass (priority 0)
No Change: Maintain current frequency for transactional emails
```

### **4. Campaign Scheduling Coordination**
```yaml
Current: Fixed schedule collisions at 3pm, 5pm, 11pm
Framework: Intelligent scheduling to spread user load
Optimization: Personalized send times based on engagement history
```

## Proposed Migration Strategy

### **Phase 1: Spool Unification** (Week 1-2)
- Replace 20 spool processors with single Laravel queue
- Maintain current throughput with Redis/Beanstalkd
- Add email audit logging

### **Phase 2: Frequency Governor** (Week 3-4)
- Implement cross-campaign frequency limits
- Start with conservative limits based on current worst-case scenarios
- Add user preference controls

### **Phase 3: Digest Intelligence** (Week 5-6)
- Consolidate 7 digest variants into personalized system
- Implement bandit testing on digest frequency
- Maintain user's current digest preferences as baseline

### **Phase 4: Schedule Optimization** (Week 7-8)
- Move fixed-time campaigns to intelligent scheduling
- Implement send-time optimization
- Reduce peak-hour collisions

## User Fatigue Prevention Strategy

### **Immediate Safeguards** (Based on current worst-case):
```php
$frequencyLimits = [
    // Conservative limits based on current system analysis
    'max_emails_per_day' => 8,     // vs current potential 10+
    'max_emails_per_hour' => 3,    // vs current unlimited
    'min_gap_between_campaigns' => 2, // 2 hours minimum gap

    // Segment-based adjustments
    'moderators' => ['daily' => 10, 'hourly' => 4], // Higher limits for mods
    'new_users' => ['daily' => 3, 'hourly' => 1],   // Conservative for new users
    'inactive' => ['daily' => 1, 'hourly' => 1],    // Minimal for dormant users
];
```

### **Peak Hour Load Balancing**:
```php
$peakHours = [15, 17, 23]; // 3pm, 5pm, 11pm
$peakHourSpread = 2; // Spread emails ±2 hours from peak

// Instead of everyone getting emails at 5pm,
// spread 4pm-6pm based on user's optimal engagement time
```

### **Emergency Brake System**:
```php
// If user received 3+ emails in last 4 hours, pause non-critical campaigns
if ($recentEmailCount >= 3 && $hoursSinceFirst <= 4) {
    return $priority > 1 ? false : true; // Only allow high priority
}
```

This analysis shows the current system has significant potential for user overload, especially for active moderators during peak hours. The framework must prioritize frequency management and intelligent scheduling to improve user experience while maintaining essential communications.