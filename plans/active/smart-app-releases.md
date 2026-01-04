# Smart App Release Scheduling

## Status: Complete

Implementation is in the `feature/smart-app-releases` branch of FreegleDocker (iznik-batch) and production branch of iznik-nuxt3.

## Problem Statement

Currently, every merge to the `production` branch triggers both:
1. **Web deployment** (Netlify) - should happen immediately for all changes ✅
2. **App deployment** (CircleCI iOS/Android) - currently deploys on every merge ❌

This results in excessive app releases for minor changes, creating unnecessary:
- App store review overhead (especially iOS 24-hour reviews)
- User update fatigue
- Build time and resource usage

## Solution

### Two-Phase Deployment

1. **Beta releases** (TestFlight/Google Play Beta) - happen immediately on every push to production
2. **Production promotion** - gated by commit message prefix:
   - `hotfix:` prefix (Conventional Commits standard) → immediate promotion
   - All other commits → batch for Wednesday night
   - No changes since last production release → skip promotion

### Key Features

- **Immediate beta deployment**: All changes go to beta channels immediately for testing
- **Simple hotfix detection**: Only commits with `hotfix:` prefix trigger immediate promotion
- **Weekly guarantee**: Production releases happen every Wednesday night (if there are changes)
- **Immediate hotfix detection**: CircleCI detects `hotfix:` prefix right after beta builds
- **Manual override**: CircleCI pipeline can be triggered with `run_manual_promote=true`

## Implementation

### CircleCI Hotfix Detection (Primary)

Hotfix detection happens directly in CircleCI when code is pushed to production:

1. **check-hotfix-promote job** (`iznik-nuxt3/.circleci/config.yml`)
   - Runs after beta builds (Android + iOS) complete
   - Checks if commit message starts with `hotfix:` prefix
   - If detected: triggers immediate promotion workflow via CircleCI API
   - If not: logs that promotion will happen on Wednesday

This approach has **zero timing windows**:
- Detection happens immediately after beta builds complete
- No polling or batch jobs that could miss commits
- No race conditions between multiple checks

### Weekly Scheduled Promotion

For non-hotfix commits, production promotion happens weekly:

```yaml
weekly-promote-schedule:
  triggers:
    - schedule:
        cron: "0 22 * * 3"  # Wednesday 10pm UTC
  jobs:
    - auto-promote-production
    - auto-submit-ios
    - auto-release-ios
```

### Laravel Services (iznik-batch)

Created for git summaries (hotfix detection moved to CircleCI):

1. **GeminiService** (`app/Services/GeminiService.php`)
   - Base service for Gemini AI API calls
   - Used by GitSummaryService for weekly code summaries

2. **GitSummaryService** (`app/Services/GitSummaryService.php`)
   - Generates AI-powered summaries of code changes
   - Sends weekly reports to Discourse via email

### Scheduled Jobs (routes/console.php)

```php
// Git summary - weekly on Wednesday at 6pm UTC
Schedule::command('data:git-summary')
    ->weeklyOn(3, '18:00');

// Note: App release classification is handled in CircleCI
// See check-hotfix-promote job in iznik-nuxt3/.circleci/config.yml
```

### Configuration (config/freegle.php)

```php
'git_summary' => [
    'gemini_api_key' => env('GOOGLE_GEMINI_API_KEY'),
    'repositories' => [...],
    'max_days_back' => 7,
    'discourse_email' => env('FREEGLE_DISCOURSE_TECH_EMAIL'),
],
```

## Urgency Classification

Classification uses the Conventional Commits standard - only the `hotfix:` prefix triggers immediate promotion. This keeps the logic simple and predictable, avoiding false positives from AI-based classification.

### URGENT (immediate promotion)
- Commit message starts with `hotfix:` prefix (case-insensitive)
- Example: `hotfix: Fix crash on login for iOS users`

### CAN_WAIT (batch for Wednesday)
- All other commits without `hotfix:` prefix
- Includes bug fixes, features, refactoring, etc.

### NO_CHANGES
- No commits since last production release
- Skip promotion entirely

## Hotfix Flow

When a `hotfix:` commit is pushed to production:

1. **Beta builds** start immediately (Android + iOS)
2. **check-hotfix-promote job** runs after builds complete
3. Job detects `hotfix:` prefix in commit message
4. Job triggers `manual-promote-submit` workflow via CircleCI API
5. **Promotion jobs** run immediately:
   - `auto-promote-production` (Android beta → production)
   - `auto-submit-ios` (TestFlight → App Store review)
   - `auto-release-ios` (Release approved builds)

**Total time from commit to production promotion starting: ~30-40 minutes**
(Time for beta builds to complete)

No polling, no timing windows, no race conditions.

## CircleCI Integration

### Manual Override

To trigger immediate production promotion for urgent fixes:
1. Go to CircleCI Pipelines for `iznik-nuxt3`
2. Click "Trigger Pipeline"
3. Add parameter: `run_manual_promote = true`
4. Select branch: `production`

This triggers the `manual-promote-submit` workflow which runs:
- `auto-promote-production` (Android beta → production)
- `auto-submit-ios` (TestFlight → App Store review)
- `auto-release-ios` (Release approved builds)

### Weekly Scheduled Workflow

Implemented in `iznik-nuxt3/.circleci/config.yml`:
```yaml
weekly-promote-schedule:
  triggers:
    - schedule:
        cron: "0 22 * * 3"  # Wednesday 10pm UTC
        filters:
          branches:
            only: production
  jobs:
    - auto-promote-production
    - auto-submit-ios
    - auto-release-ios
```

## Environment Variables Required

| Variable | Description | Where |
|----------|-------------|-------|
| `GOOGLE_GEMINI_API_KEY` | Gemini AI API key (for git summaries) | iznik-batch .env |
| `CIRCLECI_API_TOKEN` | CircleCI API token (for hotfix promotion trigger) | CircleCI project |
| `FREEGLE_DISCOURSE_TECH_EMAIL` | Discourse email for git summaries | iznik-batch .env |

Note: The `CIRCLECI_API_TOKEN` is already configured in CircleCI for version updates - it's reused for hotfix promotion triggers.

## Success Metrics

- **Production release frequency**: Target 1-2 per week (down from potentially daily)
- **Urgent response time**: Beta available immediately, production within hours for `hotfix:` commits
- **Predictability**: Classification based solely on commit prefix - no AI ambiguity

## Rollback Plan

If the system causes issues:
1. Disable the classifier: remove scheduling from console.php
2. All beta builds continue to happen immediately
3. Manual promotion via CircleCI as before

## Files Changed

### New Files (iznik-batch)
- `app/Services/GeminiService.php` - Gemini AI for git summaries
- `app/Services/GitSummaryService.php` - Weekly code summary reports
- `app/Console/Commands/Data/GitSummaryCommand.php` - Artisan command for summaries

### Modified Files (iznik-batch)
- `config/freegle.php` - Added git_summary section
- `routes/console.php` - Added git summary schedule, note about CircleCI handling hotfix detection

### Modified Files (iznik-nuxt3)
- `.circleci/config.yml`:
  - Added `check-hotfix-promote` job (detects hotfix: prefix after beta builds)
  - Changed `auto-promote-schedule` to `weekly-promote-schedule` (Wednesday 10pm UTC)
  - Updated `deploy-apps` workflow to run hotfix check after builds

### Modified Files (FreegleDocker)
- `plans/active/smart-app-releases.md` - This plan document

## Related Documentation

- `iznik-nuxt3/README-APP.md` - App deployment documentation
- `iznik-batch/CLAUDE.md` - Batch job documentation
- `FreegleDocker/CLAUDE.md` - Docker environment documentation
