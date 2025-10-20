# CircleCI Continuous Integration

This document explains how continuous integration works across the Freegle ecosystem using CircleCI.

## Overview

The Freegle project uses a **centralized testing approach** where the FreegleDocker repository orchestrates integration testing for all components. Individual repositories trigger tests in FreegleDocker rather than running their own isolated tests.

### Why This Architecture?

- **Integration Testing**: Tests the complete system as users experience it
- **Resource Efficiency**: One comprehensive test environment vs. multiple isolated ones  
- **Realistic Testing**: Tests against production-like Docker Compose stack
- **Consistency**: All components tested together with same configuration
- **Prevents Live System Contamination**: Playwright tests run in controlled environment

## Repository Responsibilities

| Repository | Local CI | Integration Testing | Playwright Tests | Mobile App Builds |
|------------|----------|-------------------|------------------|-------------------|
| **FreegleDocker** | ✅ Full integration testing | ✅ Coordinates all testing | ✅ Runs all E2E tests | ❌ N/A |
| **iznik-server-go** | ✅ Go unit tests | ➡️ Triggers FreegleDocker | ❌ None (runs in FreegleDocker) | ❌ N/A |
| **iznik-server** | ✅ PHP unit tests | ➡️ Triggers FreegleDocker | ❌ None (runs in FreegleDocker) | ❌ N/A |
| **iznik-nuxt3** (master) | ❌ No local tests | ➡️ Triggers FreegleDocker | ➡️ Tests run in FreegleDocker | ❌ N/A |
| **iznik-nuxt3** (app-ci-fd) | ✅ Android app builds | ❌ No integration tests | ❌ No Playwright tests | ✅ Fastlane + Google Play |
| **iznik-nuxt3-modtools** | ❌ No local tests | ➡️ Triggers FreegleDocker | ➡️ Tests run in FreegleDocker | ❌ N/A |

## Workflow Architecture

### 1. Change Detection & Triggering

```mermaid
graph LR
    A[Developer pushes to submodule] --> B[GitHub Actions webhook]
    B --> C[Triggers FreegleDocker CircleCI]
    C --> D[Full integration testing]
```

Each submodule contains `.github/workflows/trigger-parent-ci.yml` that automatically triggers FreegleDocker testing when changes are pushed.

### 2. Integration Testing Process

**FreegleDocker CircleCI Pipeline:**

1. **Submodule Update**: Updates all submodules to latest commits
2. **Environment Setup**: Builds complete Docker Compose stack
3. **Service Readiness**: Waits for all services to be healthy
4. **Integration Testing**: Currently runs Playwright end-to-end tests (Go/PHP unit tests may be added in future)
5. **Result Processing**: Commits successful updates or reports failures

### 3. Test Types by Component

**Go API Server (iznik-server-go):**
- Local CircleCI: Go unit tests, benchmarks, race detection
- Integration: API endpoints tested via Playwright in FreegleDocker
- *Future: Go tests may move to FreegleDocker for unified testing*

**PHP API Server (iznik-server):**
- Local CircleCI: PHPUnit tests with MySQL, Redis, PostgreSQL
- Integration: Legacy API endpoints tested via Playwright in FreegleDocker
- *Future: PHP tests may move to FreegleDocker for unified testing*

**User Website (iznik-nuxt3):**
- No local CI (avoids live system contamination)
- Integration: Full Playwright test suite in FreegleDocker

**ModTools Website (iznik-nuxt3-modtools):**
- No local CI (avoids live system contamination)
- Integration: ModTools functionality tested in FreegleDocker

## CircleCI Workflows

### Scheduled Testing
```yaml
scheduled-submodule-check:
  schedule: "0 0,6,12,18 * * *"  # Every 6 hours
  branch: master
  purpose: Regular automated submodule updates
```

### Push-Triggered Testing
```yaml
build-and-test:
  triggers: Push to master or manual trigger
  purpose: Test integration on direct changes
```

### Webhook-Triggered Testing
```yaml
webhook-triggered:
  triggers: API calls from submodule repositories  
  purpose: Immediate testing when submodules change
```

## Environment Variables

The following variables should be configured in CircleCI:

### Required API Keys
```bash
GOOGLE_CLIENT_ID=your_google_client_id.apps.googleusercontent.com
GOOGLE_CLIENT_SECRET=your_google_client_secret
GOOGLE_PUSH_KEY=your_google_push_key
GOOGLE_VISION_KEY=your_google_vision_key
GOOGLE_PERSPECTIVE_KEY=your_google_perspective_key
GOOGLE_GEMINI_API_KEY=your_google_gemini_key
GOOGLE_PROJECT=your_google_project_id
GOOGLE_APP_NAME=Freegle
MAPBOX_KEY=your_mapbox_key
MAXMIND_ACCOUNT=your_maxmind_account
MAXMIND_KEY=your_maxmind_key
```

### Optional Branch Overrides
```bash
IZNIK_SERVER_BRANCH=master
IZNIK_SERVER_GO_BRANCH=master  
IZNIK_NUXT3_BRANCH=master
IZNIK_NUXT3_MODTOOLS_BRANCH=master
```

## Webhook Setup

Each submodule repository requires a `CIRCLECI_TOKEN` secret:

1. **Get CircleCI API Token**: CircleCI → Personal API Tokens
2. **Add to each submodule**: Settings → Secrets and Variables → Actions
3. **Secret name**: `CIRCLECI_TOKEN`
4. **Secret value**: Your CircleCI API token

### Webhook Status

| Repository | Webhook Configured | Triggers On | Purpose |
|------------|-------------------|-------------|---------|
| iznik-nuxt3 | ✅ `.github/workflows/trigger-parent-ci.yml` | `master`, `main` | Trigger FreegleDocker integration tests |
| iznik-nuxt3-modtools | ✅ `.github/workflows/trigger-parent-ci.yml` | `master`, `main` | Trigger FreegleDocker integration tests |
| iznik-server | ✅ `.github/workflows/trigger-parent-ci.yml` | `master`, `main` | Trigger FreegleDocker integration tests |
| iznik-server-go | ✅ `.github/workflows/trigger-parent-ci.yml` | `master`, `main` | Trigger FreegleDocker integration tests |

**Note**: The `app-ci-fd` branch in iznik-nuxt3 does NOT trigger FreegleDocker tests. It runs independent Android app builds.

---

## Android App Automation (iznik-nuxt3)

The Freegle Direct mobile app uses a **separate CI/CD pipeline** on the `app-ci-fd` branch to automate Android app builds and deployment to Google Play.

### Why Separate from Integration Testing?

- **Different Purpose**: App builds vs integration testing
- **Different Technology**: Capacitor/Android vs Docker Compose
- **Different Artifacts**: APK/AAB files vs test reports
- **Different Deployment**: Google Play Console vs production servers
- **No Interference**: App releases don't trigger full integration test suite

### App Build Pipeline (app-ci-fd branch only)

```mermaid
graph LR
    A[Push to app-ci-fd] --> B[CircleCI detects change]
    B --> C[Build Nuxt with ISAPP=true]
    C --> D[Sync Capacitor to Android]
    D --> E[Fastlane builds AAB]
    E --> F[Upload to Google Play Internal]
```

**Build Steps:**
1. Install Node.js dependencies
2. Build Nuxt app as static site with `ISAPP=true`
3. Sync Capacitor to Android platform
4. Install Fastlane and Ruby dependencies
5. Decode Google Play API credentials from environment
6. Build Android App Bundle (AAB) with auto-incrementing version code
7. Upload to Google Play Console Internal Testing track

### Environment Variables (iznik-nuxt3 CircleCI)

**Required for Android Builds:**
```bash
GOOGLE_PLAY_JSON_KEY=base64_encoded_service_account_json
```

This should be the base64-encoded Google Play service account JSON key with "Release Manager" permissions.

### Version Management

Version numbers are managed in `VERSION.txt` at the root of iznik-nuxt3:
- **Version Name**: Read from `VERSION.txt` (e.g., "3.2.0")
- **Version Code**: Auto-incremented from latest Google Play Internal track

### Deployment Tracks

**Internal Testing** (Automated):
- Triggered automatically on push to `app-ci-fd`
- Uploaded via Fastlane
- Available to internal testers immediately

**Beta/Production** (Manual):
- Promoted manually via Fastlane commands:
  ```bash
  bundle exec fastlane android promote_beta       # Internal → Beta
  bundle exec fastlane android promote_production # Beta → Production
  ```

### Branch Isolation

| Branch | CircleCI Config | Triggers | Purpose |
|--------|----------------|----------|---------|
| `master` | ❌ None | ✅ GitHub Actions → FreegleDocker | Web integration testing |
| `app-ci-fd` | ✅ `.circleci/config.yml` | ✅ Direct CircleCI | Android app builds |

**Complete isolation ensures:**
- App releases don't trigger full test suite
- Web changes don't trigger app builds
- Independent CircleCI projects
- No resource conflicts

### Related Documentation

- [Mobile App Documentation](iznik-nuxt3/README-APP.md)
- [App Release Plan](plans/app-releases.md)
- [Capacitor Configuration](iznik-nuxt3/capacitor.config.ts)

---

## Manual Testing

### Via CircleCI UI
1. Go to [CircleCI FreegleDocker Project](https://app.circleci.com/pipelines/github/Freegle/FreegleDocker)
2. Click "Trigger Pipeline"
3. Select branch and parameters

### Via API
```bash
curl -X POST \
  -H "Circle-Token: YOUR_CIRCLECI_TOKEN" \
  -H "Content-Type: application/json" \
  -d '{"branch": "master"}' \
  https://circleci.com/api/v2/project/github/Freegle/FreegleDocker/pipeline
```

## Monitoring & Debugging

### Build Artifacts
Each CircleCI build collects:
- **Docker Logs**: Complete container startup logs
- **Test Reports**: Playwright HTML test results
- **Container Status**: Health check results
- **Build Info**: Commit details and environment info

### Common Issues

**Docker Environment Issues:**
- Check container logs in build artifacts
- Verify service health check timeouts
- Review Docker Compose configuration

**Test Failures:**
- Access Playwright HTML reports via artifacts
- Check specific test failure patterns
- Review console errors and network issues

**Webhook Failures:**
- Verify `CIRCLECI_TOKEN` is properly configured
- Check GitHub Actions workflow execution
- Validate API response in Actions logs

## Related Documentation

- [CircleCI Configuration Details](.circleci/README.md)
- [Playwright Testing](iznik-nuxt3/tests/e2e/README.md)
- [Docker Compose Setup](README.md#running)
- [Status Monitoring](README.md#monitoring)