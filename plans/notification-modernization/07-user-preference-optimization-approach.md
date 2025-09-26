# User-Preference Based Email Optimization Framework

## Simplified Frequency Management with User Control

### Core Principle:
**Users control their own email frequency** - the system optimizes within their chosen preferences rather than imposing external limits.

## User Preference Tiers

### **Tier 1: Immediate ("Real-time")**
- **User Setting**: "Send me emails immediately"
- **No Frequency Limits**: Users receive emails as they're generated
- **Optimization Focus**: Content and timing optimization only
- **Safety Net**: Clear unsubscribe/change preference link in every email
- **Volume**: Could be 10+ emails per day for active users/moderators

### **Tier 2: Standard Frequencies**
- **User Settings**: Hourly, 4-hourly, Daily, Weekly options
- **Current System**: Maintains existing digest interval preferences
- **Optimization Focus**: Full bandit testing + frequency within chosen interval
- **Cross-Campaign**: Smart batching within user's chosen frequency

### **Tier 3: No Emails**
- **User Setting**: "Don't send me any emails"
- **System Response**: Complete email suppression
- **Exception**: Critical account security emails only
- **Re-engagement**: Periodic preference confirmation (quarterly)

## Simplified Architecture

### **Frequency Governor v2.0:**
```php
class UserPreferenceFrequencyGovernor
{
    public function shouldSendEmail($userId, $emailType, $campaignPriority)
    {
        $user = User::find($userId);
        $emailPreference = $user->getSetting('email_frequency');

        switch ($emailPreference) {
            case 'none':
                // Only critical security emails
                return $emailType === 'security' || $emailType === 'account_critical';

            case 'immediate':
                // Send everything immediately - no frequency limits
                return true;

            case 'hourly':
            case 'daily':
            case 'weekly':
                // Check if enough time has passed since last email in this frequency bucket
                return $this->checkFrequencyWindow($userId, $emailPreference);

            default:
                // Default to user's current digest setting
                return $this->checkLegacyDigestSetting($userId, $emailType);
        }
    }

    private function checkFrequencyWindow($userId, $frequency)
    {
        $lastEmailTime = $this->getLastEmailTime($userId);
        $windowMinutes = [
            'hourly' => 60,
            'daily' => 1440,
            'weekly' => 10080
        ][$frequency];

        return (time() - strtotime($lastEmailTime)) >= ($windowMinutes * 60);
    }
}
```

## Campaign Optimization by Preference Tier

### **Immediate Users (Tier 1) - "Real-time Optimization"**
```php
// Focus on content and send-time optimization, no frequency limits
$experiments = [
    'subject_line_variants' => ['variant_a', 'variant_b', 'variant_c'],
    'send_time_optimization' => true,
    'content_personalization' => true,
    'frequency_capping' => false  // User chose immediate
];

// Example: Immediate user gets:
// 09:15 - Chat notification
// 09:22 - New message in their area
// 09:45 - Mod notification (if moderator)
// 10:30 - Donation ask
// All sent immediately, optimized for content quality
```

### **Scheduled Users (Tier 2) - "Batched Optimization"**
```php
// Intelligent batching within user's chosen frequency
class BatchedEmailOptimizer
{
    public function optimizeBatch($userId, $pendingEmails, $frequency)
    {
        // Sort by priority and predicted engagement
        $sortedEmails = $this->prioritizeEmails($pendingEmails, $userId);

        // Optimize batch size based on user's engagement level
        $maxBatchSize = $this->calculateOptimalBatchSize($userId, $frequency);

        // Select best emails for this batch
        $selectedEmails = array_slice($sortedEmails, 0, $maxBatchSize);

        // Create digest-style email combining multiple updates
        if (count($selectedEmails) > 3) {
            return $this->createDigestEmail($selectedEmails, $userId);
        }

        // Send individually if few emails
        return $selectedEmails;
    }

    private function calculateOptimalBatchSize($userId, $frequency)
    {
        $userSegment = $this->getUserSegment($userId);

        $baseSizes = [
            'hourly' => ['champions' => 2, 'regulars' => 1, 'occasional' => 1],
            'daily' => ['champions' => 5, 'regulars' => 3, 'occasional' => 2],
            'weekly' => ['champions' => 10, 'regulars' => 7, 'occasional' => 5]
        ];

        return $baseSizes[$frequency][$userSegment] ?? 2;
    }
}
```

### **No Email Users (Tier 3) - "Minimal Contact"**
```php
// Only essential account communications
$allowedEmailTypes = [
    'password_reset',
    'account_security_alert',
    'terms_of_service_update',
    'quarterly_preference_check'
];

// Quarterly re-engagement attempt
if ($this->isQuarterlyCheck($userId)) {
    $this->sendPreferenceUpdateEmail($userId);
}
```

## Preference Management Interface

### **User Preference Options:**
```html
<!-- Email Frequency Settings -->
<div class="email-preferences">
    <h3>Email Frequency</h3>

    <label>
        <input type="radio" name="frequency" value="immediate">
        <strong>Immediate</strong> - Get emails as soon as something happens
        <small>You'll receive all notifications, digests, and updates immediately. You can change this anytime.</small>
    </label>

    <label>
        <input type="radio" name="frequency" value="hourly">
        <strong>Hourly</strong> - Bundle emails into hourly summaries
    </label>

    <label>
        <input type="radio" name="frequency" value="daily">
        <strong>Daily</strong> - One daily digest email
    </label>

    <label>
        <input type="radio" name="frequency" value="weekly">
        <strong>Weekly</strong> - One weekly summary email
    </label>

    <label>
        <input type="radio" name="frequency" value="none">
        <strong>No emails</strong> - Only critical account security messages
    </label>
</div>

<!-- Smart Default Suggestion -->
<div class="smart-suggestion">
    <p>💡 Based on your activity level, we recommend: <strong>Daily</strong></p>
    <small>You can always change this setting from any email we send you.</small>
</div>
```

### **In-Email Preference Links:**
```html
<!-- Footer in every email -->
<div class="email-footer">
    <p>
        <strong>Too many emails?</strong>
        <a href="/preferences/frequency/reduce?user={{user_id}}&token={{token}}">Get fewer emails</a>
    </p>
    <p>
        <strong>Want more updates?</strong>
        <a href="/preferences/frequency/immediate?user={{user_id}}&token={{token}}">Get immediate notifications</a>
    </p>
    <p>
        <a href="/preferences/email?user={{user_id}}&token={{token}}">Change email preferences</a> |
        <a href="/unsubscribe?user={{user_id}}&token={{token}}">Unsubscribe</a>
    </p>
</div>
```

## Bandit Testing by Preference Tier

### **Immediate Users - Content-Focused Testing:**
- **Subject line optimization**: A/B test subject lines for highest open rates
- **Send time optimization**: Learn optimal times for each user individually
- **Content personalization**: Test different content styles/lengths
- **Template variants**: Test email layouts and designs

### **Scheduled Users - Batch Optimization Testing:**
- **Digest format testing**: Single digest vs multiple individual emails
- **Content prioritization**: Which types of content to include first
- **Batch size optimization**: How many items to include in each digest
- **Summary vs detail**: Test detailed content vs brief summaries

### **No Email Users - Minimal Testing:**
- **Re-engagement campaigns**: Test quarterly preference reminder approaches
- **Critical email optimization**: Optimize the rare emails they do receive

## Migration Strategy from Current System

### **Phase 1: Preference Mapping**
```sql
-- Map existing user preferences to new system
UPDATE users SET email_frequency = CASE
    WHEN digest_setting = -1 THEN 'immediate'
    WHEN digest_setting IN (1, 4) THEN 'hourly'
    WHEN digest_setting = 24 THEN 'daily'
    WHEN newsletters_allowed = 0 THEN 'none'
    ELSE 'daily'  -- Default fallback
END;
```

### **Phase 2: Gradual Rollout**
1. **Week 1**: Implement preference system for new users
2. **Week 2**: Migrate existing "immediate" users (digest -1)
3. **Week 3**: Migrate daily digest users (digest 24)
4. **Week 4**: Migrate remaining users with notification

### **Phase 3: Optimization**
1. **Month 2**: Start bandit testing within each preference tier
2. **Month 3**: Implement intelligent batching for scheduled users
3. **Month 4**: Add smart suggestion system for optimal preferences

## Benefits of User-Controlled Approach

### **Eliminates Frequency Management Complexity:**
- ✅ No need for complex cross-campaign coordination
- ✅ No arbitrary frequency limits that might frustrate users
- ✅ Users self-select into appropriate communication levels
- ✅ Reduces unsubscribe rates (users control their experience)

### **Focuses Optimization Efforts:**
- ✅ **Immediate users**: Optimize for content quality and relevance
- ✅ **Scheduled users**: Optimize batching and digest formats
- ✅ **No email users**: Minimal overhead, focus on critical communications

### **Maintains Current Functionality:**
- ✅ Preserves existing digest interval preferences
- ✅ Maintains mod notification frequency settings
- ✅ Allows for easy user migration from current system

### **Enables Advanced Optimization:**
- ✅ **Personalized send times** for immediate users
- ✅ **Intelligent content prioritization** for batched users
- ✅ **Thompson sampling** optimizes within user's chosen framework
- ✅ **Cross-campaign learning** improves content across all tiers

This approach respects user autonomy while still enabling sophisticated optimization within each user's chosen communication preferences.