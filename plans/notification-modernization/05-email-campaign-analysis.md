# Email Campaign Analysis & Cross-Campaign Optimization

## Current Email Types Analysis

Based on analysis of the 40+ email-sending cron jobs, Freegle sends these main categories:

### 1. **Transactional Emails** (High Priority, No Experimentation)
- **Chat notifications** (user2user, user2mod)
- **Message status updates** (expired, taken)
- **Membership actions** (approved, rejected)
- **Donation receipts**
- **Bounced email notifications**

**Framework Mapping**: These bypass bandit testing and go direct to Mautic as individual sends.

### 2. **Engagement Campaigns** (Primary Experimentation Target)
- **Daily digests** (personalizable frequency: 1h, 4h, 24h)
- **Weekly summaries** (group activity)
- **Newsfeed digests** (community updates)
- **Mod notification summaries** (pending work)

**Framework Mapping**: Each digest type = separate experiment with shared user fatigue management.

### 3. **Lifecycle Campaigns** (Medium Experimentation)
- **Welcome sequences** (new users)
- **Donation asks** (after successful transactions)
- **Re-engagement** (dormant users)
- **Volunteer recruitment** (active users → moderators)

**Framework Mapping**: Journey-based campaigns with stage-specific optimization.

### 4. **Administrative Emails** (Low Priority)
- **Moderator tools** (pending approvals, spam alerts)
- **System status** (cron job failures)
- **Group management** (closure warnings)
- **External sync** (TrashNothing, LoveJunk)

**Framework Mapping**: Template optimization only, no timing experiments.

## Campaign Framework Mapping

### Campaign Hierarchy:
```
1. Email Type Classification
   ├── Transactional (immediate send)
   ├── Engagement (bandit tested)
   ├── Lifecycle (journey-based)
   └── Administrative (template only)

2. Campaign Groups (for cross-campaign optimization)
   ├── Daily Communications (digests + notifications)
   ├── Weekly Communications (summaries + reports)
   ├── Lifecycle Communications (welcome + re-engagement)
   └── Emergency Communications (system alerts)

3. Experiment Levels
   ├── Global: Cross-campaign frequency management
   ├── Campaign: Subject/content optimization within type
   └── Variant: Individual message testing
```

### Implementation in Mautic + Laravel:

#### **Campaign Structure:**
```php
// Each email type becomes a Mautic campaign
$campaigns = [
    'daily_digest' => [
        'priority' => 1,
        'frequency_cap' => '1 per day',
        'experiments' => ['subject_line', 'content', 'send_time'],
        'segments' => ['champions', 'regulars', 'occasional', 'newbies']
    ],
    'chat_notifications' => [
        'priority' => 0, // Transactional - immediate
        'frequency_cap' => 'unlimited',
        'experiments' => [],
        'segments' => ['all']
    ],
    'donation_ask' => [
        'priority' => 2,
        'frequency_cap' => '1 per week',
        'experiments' => ['subject_line', 'content', 'timing_delay'],
        'segments' => ['recent_receivers']
    ],
    'welcome_series' => [
        'priority' => 1,
        'frequency_cap' => '3 emails in 7 days',
        'experiments' => ['content_style', 'cta_placement'],
        'segments' => ['new_users']
    ]
];
```

## Cross-Campaign Optimization Strategy

### 1. **User Fatigue Management**

#### **Global Frequency Governor:**
```php
class EmailFrequencyGovernor
{
    public function shouldSendEmail($userId, $campaignType, $priority)
    {
        $user = User::find($userId);
        $segment = $this->getUserSegment($user);

        // Get user's email preferences and recent history
        $recentEmails = $this->getRecentEmailsSent($userId, '24 hours');
        $weeklyEmails = $this->getRecentEmailsSent($userId, '7 days');

        // Segment-based limits
        $limits = [
            'champions' => ['daily' => 3, 'weekly' => 15],
            'regulars' => ['daily' => 2, 'weekly' => 10],
            'occasional' => ['daily' => 1, 'weekly' => 5],
            'newbies' => ['daily' => 2, 'weekly' => 8],
            'dormant' => ['daily' => 1, 'weekly' => 3]
        ];

        $userLimits = $limits[$segment] ?? $limits['occasional'];

        // Priority overrides (0 = transactional, always send)
        if ($priority === 0) {
            return true;
        }

        // Check daily limits
        if (count($recentEmails) >= $userLimits['daily']) {
            // Only allow higher priority emails
            $highestRecentPriority = min(array_column($recentEmails, 'priority'));
            return $priority < $highestRecentPriority;
        }

        // Check weekly limits
        if (count($weeklyEmails) >= $userLimits['weekly']) {
            return $priority <= 1; // Only high priority
        }

        return true;
    }
}
```

#### **Campaign Coordination:**
```php
class CampaignCoordinator
{
    public function optimizeCampaignSchedule($userId)
    {
        $pendingCampaigns = $this->getPendingCampaigns($userId);
        $userSegment = $this->getUserSegment($userId);
        $preferences = $this->getUserPreferences($userId);

        // Sort by priority and expected impact
        usort($pendingCampaigns, function($a, $b) {
            // Priority first (0 = highest)
            if ($a['priority'] !== $b['priority']) {
                return $a['priority'] <=> $b['priority'];
            }

            // Then by predicted performance for this user segment
            return $b['predicted_engagement'] <=> $a['predicted_engagement'];
        });

        $scheduledEmails = [];
        $dailyBudget = $this->getDailyEmailBudget($userSegment);

        foreach ($pendingCampaigns as $campaign) {
            if ($this->frequencyGovernor->shouldSendEmail(
                $userId,
                $campaign['type'],
                $campaign['priority']
            )) {
                $scheduledEmails[] = $campaign;
                $dailyBudget--;

                if ($dailyBudget <= 0) {
                    break;
                }
            }
        }

        return $scheduledEmails;
    }
}
```

### 2. **Intelligent Campaign Timing**

#### **Send Time Optimization:**
```php
class SendTimeOptimizer
{
    public function getOptimalSendTime($userId, $campaignType)
    {
        $user = User::find($userId);
        $historicalData = $this->getUserEngagementPatterns($userId);

        // Personal optimization (if enough data)
        if (count($historicalData) >= 20) {
            return $this->personalOptimalTime($historicalData);
        }

        // Segment-based optimization
        $segment = $this->getUserSegment($user);
        $segmentOptimal = $this->getSegmentOptimalTime($segment, $campaignType);

        // Global bandit testing for new patterns
        $globalExperiment = $this->getGlobalSendTimeExperiment($campaignType);

        // Combine personal, segment, and experimental insights
        return $this->weightedTimeSelection([
            'personal' => null, // Not enough data
            'segment' => $segmentOptimal,
            'experiment' => $globalExperiment,
            'weights' => [0.0, 0.7, 0.3] // Favor segment, some experimentation
        ]);
    }

    private function personalOptimalTime($historicalData)
    {
        // Find peak engagement hours for this user
        $hourlyEngagement = [];
        foreach ($historicalData as $event) {
            $hour = date('H', strtotime($event['timestamp']));
            $hourlyEngagement[$hour] = ($hourlyEngagement[$hour] ?? 0) + $event['engagement_score'];
        }

        // Return hour with highest average engagement
        return array_keys($hourlyEngagement, max($hourlyEngagement))[0] . ':00';
    }
}
```

### 3. **Content Coordination & Anti-Spam**

#### **Content Diversity Manager:**
```php
class ContentDiversityManager
{
    public function preventContentOverlap($userId, $newCampaign)
    {
        $recentEmails = $this->getRecentEmailsSent($userId, '48 hours');

        // Check for similar content themes
        $themes = array_map(function($email) {
            return $this->extractContentThemes($email['content']);
        }, $recentEmails);

        $newThemes = $this->extractContentThemes($newCampaign['content']);

        // Calculate overlap score
        $overlapScore = $this->calculateThemeOverlap($themes, $newThemes);

        if ($overlapScore > 0.7) {
            // High overlap - modify content or delay
            return $this->diversifyContent($newCampaign, $themes);
        }

        return $newCampaign;
    }

    public function extractContentThemes($content)
    {
        // Use AI to extract semantic themes
        $themes = $this->aiService->extractThemes($content);

        return [
            'topics' => $themes['topics'], // e.g., ['furniture', 'garden', 'books']
            'sentiment' => $themes['sentiment'], // e.g., 'promotional', 'informational', 'urgent'
            'cta_type' => $themes['cta_type'] // e.g., 'browse', 'post', 'moderate'
        ];
    }
}
```

## Campaign Priority Matrix

### Priority Levels:
```
0 = Transactional (immediate, unlimited frequency)
1 = High Priority (welcome, urgent mod notifications)
2 = Medium Priority (regular digests, donation asks)
3 = Low Priority (weekly summaries, newsletter content)
4 = Experimental (A/B tests, new campaign types)
```

### Frequency Caps by Segment:
```php
$frequencyCaps = [
    'champions' => [
        'daily' => 3,
        'weekly' => 15,
        'priority_overrides' => [0 => 'unlimited', 1 => 5]
    ],
    'regulars' => [
        'daily' => 2,
        'weekly' => 10,
        'priority_overrides' => [0 => 'unlimited', 1 => 3]
    ],
    'occasional' => [
        'daily' => 1,
        'weekly' => 5,
        'priority_overrides' => [0 => 'unlimited', 1 => 2]
    ],
    'newbies' => [
        'daily' => 2,
        'weekly' => 8,
        'priority_overrides' => [0 => 'unlimited', 1 => 4]
    ],
    'dormant' => [
        'daily' => 1,
        'weekly' => 3,
        'priority_overrides' => [0 => 'unlimited', 1 => 1]
    ]
];
```

## Cross-Campaign Learning

### Shared Experimentation Insights:
```php
class CrossCampaignLearning
{
    public function shareExperimentInsights()
    {
        // Find winning patterns across all campaigns
        $winningPatterns = $this->analyzeWinningVariants();

        foreach ($winningPatterns as $pattern) {
            // Apply successful subject line patterns to other campaigns
            if ($pattern['type'] === 'subject_line') {
                $this->propagateSubjectLinePattern($pattern);
            }

            // Apply successful send times across campaigns
            if ($pattern['type'] === 'send_time') {
                $this->propagateSendTimePattern($pattern);
            }

            // Apply successful content structures
            if ($pattern['type'] === 'content_structure') {
                $this->propagateContentPattern($pattern);
            }
        }
    }

    private function propagateSubjectLinePattern($pattern)
    {
        // If "Your impact: X items saved" works well for digest emails,
        // test similar impact-focused subjects in donation asks
        $applicableCampaigns = $this->findSimilarCampaigns($pattern['source_campaign']);

        foreach ($applicableCampaigns as $campaign) {
            $this->createCrossPollinationExperiment($pattern, $campaign);
        }
    }
}
```

## Implementation Schedule

### Phase 1: Infrastructure (Weeks 1-2)
- Campaign coordination database tables
- Frequency governor service
- Basic priority system

### Phase 2: Core Campaigns (Weeks 3-6)
- Migrate digest system (highest volume)
- Implement cross-campaign frequency limits
- Basic bandit testing for digests

### Phase 3: Advanced Optimization (Weeks 7-10)
- Send time optimization
- Content diversity management
- Cross-campaign learning system

### Phase 4: Full Integration (Weeks 11-12)
- All campaigns migrated
- Advanced AI-driven optimization
- Performance monitoring dashboard

This approach treats each email type as a separate campaign while maintaining intelligent coordination to prevent user fatigue and maximize overall engagement across the entire email ecosystem.