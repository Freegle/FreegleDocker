# CircleCI Configuration for FreegleDocker

This directory contains CircleCI configuration that automatically monitors submodule changes and runs integration tests.

## Overview

The CircleCI pipeline:
- **Updates submodules** to latest commits on their default branches
- **Runs full integration tests** using the complete Docker Compose stack
- **Commits successful updates** back to the repository
- **Responds to webhooks** from submodule repositories for immediate testing
- **Monitors submodules** every 2 days for updates

## Workflows

### 1. `webhook-triggered`
- **Trigger**: API webhook calls from submodule repositories
- **Purpose**: Immediate testing when submodules are updated

### 2. `build-and-test`
- **Trigger**: Push to `master` branch
- **Purpose**: Test submodule integration on manual pushes

### 3. `scheduled-integration-test`
- **Trigger**: Scheduled cron job every 2 days at 4pm UTC (`0 16 1,3,5,7,9,11,13,15,17,19,21,23,25,27,29,31 * *`)
- **Branch**: Only runs on `master`
- **Purpose**: Regular automated checking for submodule updates

## Jobs

### `check-submodules`
Main job that:
1. Checks out repository with submodules
2. Updates submodules to latest commits
3. Starts Docker Compose environment if changes detected
4. Waits for all services to be ready
5. Runs Playwright tests via status container API
6. Commits updates if tests pass
7. Cleans up Docker resources

### `webhook-submodule-check`
Webhook-triggered variant that:
- Forces submodule updates regardless of detected changes
- Always runs tests when triggered by webhook
- Uses same testing logic as main job

## Webhook Integration Status

The following submodules are **already configured** with GitHub Actions workflows that trigger CircleCI builds:

- ✅ **iznik-nuxt3** - `.github/workflows/trigger-parent-ci.yml` configured
- ✅ **iznik-nuxt3-modtools** - `.github/workflows/trigger-parent-ci.yml` configured  
- ✅ **iznik-server** - `.github/workflows/trigger-parent-ci.yml` configured
- ✅ **iznik-server-go** - `.github/workflows/trigger-parent-ci.yml` configured

### Activation Required

To enable the webhooks, add a `CIRCLECI_TOKEN` secret to each submodule repository:

1. Get CircleCI API Token from CircleCI → Personal API Tokens
2. In each submodule: Settings → Secrets and Variables → Actions
3. Add secret named `CIRCLCI_TOKEN` with your API token value

## Manual Testing

### Trigger via CircleCI UI
1. Go to CircleCI dashboard
2. Select FreegleDocker project  
3. Click "Trigger Pipeline"
4. Select branch and optionally set parameters

### Trigger via API
```bash
curl -X POST \
  -H "Circle-Token: YOUR_CIRCLECI_TOKEN" \
  -H "Content-Type: application/json" \
  -d '{
    "branch": "master",
    "parameters": {
      "webhook_repository": "manual-trigger",
      "webhook_branch": "master", 
      "webhook_commit": "manual"
    }
  }' \
  https://circleci.com/api/v2/project/github/Freegle/FreegleDocker/pipeline
```

## Environment Variables

The configuration uses these environment variables:

- `CIRCLE_BRANCH` - Current branch being built
- `CIRCLE_BUILD_NUM` - CircleCI build number
- `CIRCLE_BUILD_URL` - URL to build details
- `CIRCLE_SHA1` - Git commit SHA
- `WEBHOOK_REPOSITORY` - Repository that triggered webhook (if applicable)
- `WEBHOOK_BRANCH` - Branch that triggered webhook (if applicable)  
- `WEBHOOK_COMMIT` - Commit that triggered webhook (if applicable)

## Monitoring and Debugging

### Build Artifacts
Each build collects:
- Docker Compose logs (`docker-logs.txt`)
- Container status (`docker-status.txt`)
- Playwright HTML report (if available)
- Build information (`build-info.txt`)

### Common Issues

1. **Docker startup failures**: Check `docker-logs.txt` artifact
2. **Test timeouts**: Tests timeout after 30 minutes - may need adjustment
3. **Git push failures**: Check repository permissions and branch protection

### Resource Management

- **Docker Layer Caching**: Enabled to speed up builds
- **Timeouts**: Generous timeouts for Docker environment startup
- **Cleanup**: Always cleans up Docker resources after completion
- **Artifacts**: Retained for debugging failed builds

## Configuration Customization

Key configuration options:

```yaml
# Adjust schedule frequency
cron: "0 16 1,3,5,7,9,11,13,15,17,19,21,23,25,27,29,31 * *"  # Every 2 days at 4pm UTC

# Modify test timeout  
timeout_duration=1800  # 30 minutes

# Change Docker startup wait
sleep 60  # Wait time after docker-compose up

# Adjust service readiness check
for i in {1..60}; do  # 60 attempts = 10 minutes max
```

## Security Considerations

- API tokens should be stored as encrypted secrets
- Webhook payloads can be signed for verification
- Only `master` branch commits trigger submodule updates
- Git push uses service account credentials

## Benefits

✅ **Automated Integration**: Keeps parent repository current with submodule changes
✅ **Comprehensive Testing**: Full Docker stack validation before updates
✅ **Immediate Feedback**: Webhook integration provides fast feedback
✅ **Artifact Collection**: Debugging information preserved for failed builds  
✅ **Resource Efficiency**: Only runs tests when changes are detected
✅ **Rollback Safety**: Only commits updates if tests pass