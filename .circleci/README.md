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

## Submodule PR Testing

Each submodule has its own CircleCI configuration that runs tests on pull requests using FreegleDocker's Docker Compose setup:

### Test Types

| Submodule | Test Type | Description |
|-----------|-----------|-------------|
| **iznik-server** | PHPUnit | Unit tests for PHP API |
| **iznik-server-go** | Go tests | Unit tests for Go API with race detection |
| **iznik-nuxt3** | Playwright | End-to-end browser tests |

### How It Works

1. PR is opened in submodule repository
2. CircleCI clones FreegleDocker and initializes submodules
3. PR code replaces the submodule directory
4. Docker Compose services are started
5. Tests run via the status container API endpoints
6. Results are reported back to the PR

### Test Execution

Tests are executed via the status container's API endpoints:

```bash
# PHPUnit tests
curl -X POST http://localhost:8081/api/tests/php

# Go tests
curl -X POST http://localhost:8081/api/tests/go

# Playwright tests
curl -X POST http://localhost:8081/api/tests/playwright
```

The status container provides a unified API for test execution across all submodules, making it easy to integrate with CircleCI or run tests manually.

## Webhook Integration Status

The following submodules are **already configured** with GitHub Actions workflows that trigger CircleCI builds:

- ✅ **iznik-nuxt3** - `.github/workflows/trigger-parent-ci.yml` configured, PR Playwright tests
- ✅ **iznik-nuxt3-modtools** - `.github/workflows/trigger-parent-ci.yml` configured
- ✅ **iznik-server** - `.github/workflows/trigger-parent-ci.yml` configured, PR PHPUnit tests
- ✅ **iznik-server-go** - `.github/workflows/trigger-parent-ci.yml` configured, PR Go tests

### How Submodule Updates Work

When code is pushed to a submodule's master branch:

1. **GitHub Actions workflow** (`trigger-parent-ci.yml`) runs in the submodule
2. The workflow clones FreegleDocker and updates the submodule reference
3. The updated reference is pushed back to FreegleDocker master
4. This push triggers CircleCI to run the full test suite

### GitHub Token Configuration

The submodule workflows use a **fine-grained Personal Access Token (PAT)** to push updates to FreegleDocker.

**Important**: The PAT must be scoped to the **Freegle organization**, not a personal account.

#### Creating the PAT

1. Log in to GitHub as the service account (e.g., FreegleGeeks)
2. Go to Settings → Developer settings → Fine-grained personal access tokens
3. Click "Generate new token"
4. **Resource owner**: Select **Freegle** (the organization) - NOT your personal account
5. **Repository access**: Select "Only select repositories" → choose `FreegleDocker`
6. **Permissions**:
   - Contents: Read and write
   - Metadata: Read-only (required)
7. Generate the token

#### Adding the Secret to Submodules

Add the PAT as a secret named `FREEGLE_DOCKER_TOKEN` in each submodule:

1. Go to submodule repo → Settings → Secrets and Variables → Actions
2. Add repository secret named `FREEGLE_DOCKER_TOKEN`
3. Paste the PAT value

Links to add secrets:
- https://github.com/Freegle/iznik-nuxt3/settings/secrets/actions
- https://github.com/Freegle/iznik-server/settings/secrets/actions
- https://github.com/Freegle/iznik-server-go/settings/secrets/actions
- https://github.com/Freegle/iznik-nuxt3-modtools/settings/secrets/actions

**Troubleshooting**: If you get "Permission denied to FreegleGeeks", the PAT is likely scoped to the user account instead of the Freegle organization. Regenerate it with the correct resource owner.

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
  -d '{"branch": "master"}' \
  https://circleci.com/api/v2/project/github/Freegle/FreegleDocker/pipeline
```

## Environment Variables

The configuration uses these environment variables:

- `CIRCLE_BRANCH` - Current branch being built
- `CIRCLE_BUILD_NUM` - CircleCI build number
- `CIRCLE_BUILD_URL` - URL to build details
- `CIRCLE_SHA1` - Git commit SHA

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

## Self-Hosted Runner

Builds run on a dedicated self-hosted runner for faster execution and to avoid CircleCI cloud timeouts.

### Runner Configuration

- **Resource class**: `freegle/circleci-docker-runner`
- **Machine type**: Dedicated Linux server with Docker
- **Runner software**: CircleCI Machine Runner 3.x

### Runner Setup

The runner is installed directly on the host machine (not in Docker) to avoid permission issues:

```bash
# Runner binary location
/opt/circleci/circleci-runner

# Configuration file
/etc/circleci-runner/circleci-runner-config.yaml

# Systemd service
sudo systemctl status circleci-runner
```

### Prerequisites on Runner Machine

- Docker and docker-compose
- Git (configured with HTTPS for GitHub: `git config --global url."https://github.com/".insteadOf "git@github.com:"`)
- curl, jq
- Passwordless sudo for circleci user

### Custom Runner Docker Image

For containerized runner deployments, a custom Dockerfile is available at `circleci-runner/Dockerfile` with all required tools pre-installed.

### Troubleshooting

```bash
# Check runner status
sudo systemctl status circleci-runner

# View runner logs
sudo journalctl -u circleci-runner -f

# Restart runner
sudo systemctl restart circleci-runner
```

## Security Considerations

- API tokens should be stored as encrypted secrets
- Webhook payloads can be signed for verification
- Only `master` branch commits trigger submodule updates
- Git push uses service account credentials
- Self-hosted runner has passwordless sudo for build operations

## Benefits

✅ **Automated Integration**: Keeps parent repository current with submodule changes
✅ **Comprehensive Testing**: Full Docker stack validation before updates
✅ **Immediate Feedback**: Webhook integration provides fast feedback
✅ **Artifact Collection**: Debugging information preserved for failed builds  
✅ **Resource Efficiency**: Only runs tests when changes are detected
✅ **Rollback Safety**: Only commits updates if tests pass# Trigger pipeline to test PHPUnit worker fix
