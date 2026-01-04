# Job Ad Source Tracking - Design

## Problem

Cannot reliably compare job ad click performance between website and email sources. Currently:
- Website clicks (JobOne.vue) log to Loki with rich context
- Email clicks (/job/[id].vue) don't log to Loki at all
- No `source` field to distinguish click origins

## Solution

Add source tracking to Loki logging for all job ad clicks.

## Implementation

### 1. Email Link Tracking (iznik-batch)

Update `ChatNotification.php` to wrap job URLs with tracking:

```php
foreach ($this->jobs as $index => $job) {
    $job->tracked_url = $this->trackedUrl(
        config('freegle.sites.user') . '/job/' . $job->id .
        '?source=email&campaign=chat_notification&position=' . $index .
        '&list_length=' . count($this->jobs),
        'job_ad_' . $index,
        'job_click'
    );
}
```

Update Blade template (`notification.blade.php`) to use tracked URLs:
- Line 362: Image link uses `$job->tracked_url`
- Line 368: Title link uses `$job->tracked_url`

### 2. Loki Logging in /job/[id].vue (iznik-nuxt3)

Read tracking params and log to Loki before redirect:

```javascript
const route = useRoute()
const source = route.query.source || 'direct'
const campaign = route.query.campaign || null
const position = route.query.position ? parseInt(route.query.position) : null
const listLength = route.query.list_length ? parseInt(route.query.list_length) : null

const { action } = useClientLog()

action('job_ad_click', {
  job_id: job.value.id,
  job_reference: job.value.reference,
  job_category: job.value.category,
  cpc: job.value.cpc,
  source: source,
  campaign: campaign,
  position: position,
  list_length: listLength,
  context: 'email_redirect'
})
```

### 3. Update JobOne.vue for Consistency (iznik-nuxt3)

Add `source: 'website'` to existing `job_ad_click` action:

```javascript
action('job_ad_click', {
  job_id: job.value.id,
  job_reference: job.value.reference,
  job_category: job.value.category,
  cpc: job.value.cpc,
  position: props.position,
  list_length: props.listLength,
  context: props.context,
  source: 'website'
})
```

## Testing & Verification

1. **Email link tracking** - Send test ChatNotification, click job ad, verify:
   - Click recorded in `email_tracking_clicks` table
   - Redirected to `/job/{id}?source=email&campaign=chat_notification&position=0&list_length=2`

2. **Loki logging** - Check Grafana Loki:
   ```
   {event_type="job_ad_click"} | json | source="email"
   ```

3. **Website clicks** - Verify Loki event has `source="website"`

4. **Comparison query** in Grafana:
   ```
   sum by (source) (count_over_time({event_type="job_ad_click"} | json [24h]))
   ```

## Files Changed

- `iznik-batch/app/Mail/Chat/ChatNotification.php`
- `iznik-batch/resources/views/emails/mjml/chat/notification.blade.php`
- `iznik-nuxt3/pages/job/[id].vue`
- `iznik-nuxt3/components/JobOne.vue`

## Notes

- No database migration needed - purely Loki-based solution
- Uses existing email tracking infrastructure (`TrackableEmail` trait)
- Direct links to `/job/{id}` (without params) will have `source='direct'`
