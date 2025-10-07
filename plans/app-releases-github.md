# App Release Automation - GitHub Actions Implementation

This document covers the GitHub Actions-specific implementation for automated app releases. For general information and Fastlane configuration, see [app-releases.md](app-releases.md).

## GitHub Actions Secrets

Add the following secrets to GitHub repository settings (Settings → Secrets and variables → Actions):

### Android Secrets
- `GOOGLE_PLAY_JSON_KEY`: Base64 encoded JSON key file
  ```bash
  base64 -i google-play-api-key.json | pbcopy
  ```

### iOS Secrets
- `APP_STORE_CONNECT_API_KEY_ISSUER_ID`: From App Store Connect
- `APP_STORE_CONNECT_API_KEY_KEY_ID`: From App Store Connect
- `APP_STORE_CONNECT_API_KEY_KEY`: Base64 encoded .p8 file
  ```bash
  base64 -i AuthKey_XXXXXXXXXX.p8 | pbcopy
  ```
- `FASTLANE_APPLE_ID`: Your Apple ID email
- `FASTLANE_TEAM_ID`: Your Team ID from Apple Developer
- `MATCH_PASSWORD`: Password for encrypting certificates in match repo
- `MATCH_GIT_BASIC_AUTHORIZATION`: Base64 of `username:personal_access_token`
  ```bash
  echo -n "username:ghp_token" | base64
  ```

---

## GitHub Actions Workflow for Freegle App (Capacitor)

Create `.github/workflows/deploy-app.yml` in iznik-nuxt3:

```yaml
name: Deploy Freegle App

on:
  push:
    branches:
      - app

jobs:
  deploy-android:
    runs-on: ubuntu-latest
    steps:
      - name: Checkout code
        uses: actions/checkout@v4

      - name: Setup Node.js
        uses: actions/setup-node@v4
        with:
          node-version: '18'
          cache: 'npm'

      - name: Install dependencies
        run: npm ci

      - name: Build Nuxt app
        run: npm run build

      - name: Sync Capacitor
        run: npx cap sync android

      - name: Setup Ruby
        uses: ruby/setup-ruby@v1
        with:
          ruby-version: '3.2'
          bundler-cache: true

      - name: Decode Google Play credentials
        run: |
          echo "${{ secrets.GOOGLE_PLAY_JSON_KEY }}" | base64 -d > fastlane/google-play-api-key.json

      - name: Deploy to Google Play Internal Track
        run: bundle exec fastlane android internal

      - name: Upload build artifacts
        uses: actions/upload-artifact@v4
        with:
          name: android-bundle
          path: android/app/build/outputs/bundle/release/

  deploy-ios:
    runs-on: macos-13
    steps:
      - name: Checkout code
        uses: actions/checkout@v4

      - name: Setup Node.js
        uses: actions/setup-node@v4
        with:
          node-version: '18'
          cache: 'npm'

      - name: Install dependencies
        run: npm ci

      - name: Build Nuxt app
        run: npm run build

      - name: Sync Capacitor
        run: npx cap sync ios

      - name: Setup Ruby
        uses: ruby/setup-ruby@v1
        with:
          ruby-version: '3.2'
          bundler-cache: true

      - name: Setup App Store Connect API
        run: |
          mkdir -p ~/.appstoreconnect/private_keys/
          echo "${{ secrets.APP_STORE_CONNECT_API_KEY_KEY }}" | base64 -d > ~/.appstoreconnect/private_keys/AuthKey_${{ secrets.APP_STORE_CONNECT_API_KEY_KEY_ID }}.p8

      - name: Deploy to TestFlight
        run: bundle exec fastlane ios beta
        env:
          APP_STORE_CONNECT_API_KEY_KEY_ID: ${{ secrets.APP_STORE_CONNECT_API_KEY_KEY_ID }}
          APP_STORE_CONNECT_API_KEY_ISSUER_ID: ${{ secrets.APP_STORE_CONNECT_API_KEY_ISSUER_ID }}
          APP_STORE_CONNECT_API_KEY_KEY: ${{ secrets.APP_STORE_CONNECT_API_KEY_KEY }}
          FASTLANE_APPLE_ID: ${{ secrets.FASTLANE_APPLE_ID }}
          FASTLANE_TEAM_ID: ${{ secrets.FASTLANE_TEAM_ID }}
          MATCH_PASSWORD: ${{ secrets.MATCH_PASSWORD }}
          MATCH_GIT_BASIC_AUTHORIZATION: ${{ secrets.MATCH_GIT_BASIC_AUTHORIZATION }}
```

---

## GitHub Actions Workflow for ModTools App (Cordova)

Create `.github/workflows/deploy-app.yml` in iznik-nuxt3-modtools:

```yaml
name: Deploy ModTools App

on:
  push:
    branches:
      - app

jobs:
  deploy-android:
    runs-on: ubuntu-latest
    env:
      # Use JDK 11 for Cordova
      JAVA_VERSION: '11'
    steps:
      - name: Checkout code
        uses: actions/checkout@v4

      - name: Setup Node.js
        uses: actions/setup-node@v4
        with:
          node-version: '18'
          cache: 'npm'

      - name: Setup Java
        uses: actions/setup-java@v4
        with:
          distribution: 'temurin'
          java-version: ${{ env.JAVA_VERSION }}

      - name: Install dependencies
        run: npm ci

      - name: Install Cordova
        run: npm install -g cordova cordova-set-version

      - name: Add Android platform
        run: cordova platform add android

      - name: Setup Ruby
        uses: ruby/setup-ruby@v1
        with:
          ruby-version: '3.2'
          bundler-cache: true

      - name: Decode Google Play credentials
        run: |
          echo "${{ secrets.GOOGLE_PLAY_JSON_KEY }}" | base64 -d > fastlane/google-play-api-key.json

      - name: Deploy to Google Play Internal Track
        run: bundle exec fastlane android internal

  deploy-ios:
    runs-on: macos-13
    steps:
      - name: Checkout code
        uses: actions/checkout@v4

      - name: Setup Node.js
        uses: actions/setup-node@v4
        with:
          node-version: '18'
          cache: 'npm'

      - name: Install dependencies
        run: npm ci

      - name: Install Cordova
        run: npm install -g cordova cordova-set-version

      - name: Add iOS platform
        run: cordova platform add ios

      - name: Setup Ruby
        uses: ruby/setup-ruby@v1
        with:
          ruby-version: '3.2'
          bundler-cache: true

      - name: Setup App Store Connect API
        run: |
          mkdir -p ~/.appstoreconnect/private_keys/
          echo "${{ secrets.APP_STORE_CONNECT_API_KEY_KEY }}" | base64 -d > ~/.appstoreconnect/private_keys/AuthKey_${{ secrets.APP_STORE_CONNECT_API_KEY_KEY_ID }}.p8

      - name: Deploy to TestFlight
        run: bundle exec fastlane ios beta
        env:
          APP_STORE_CONNECT_API_KEY_KEY_ID: ${{ secrets.APP_STORE_CONNECT_API_KEY_KEY_ID }}
          APP_STORE_CONNECT_API_KEY_ISSUER_ID: ${{ secrets.APP_STORE_CONNECT_API_KEY_ISSUER_ID }}
          APP_STORE_CONNECT_API_KEY_KEY: ${{ secrets.APP_STORE_CONNECT_API_KEY_KEY }}
          FASTLANE_APPLE_ID: ${{ secrets.FASTLANE_APPLE_ID }}
          FASTLANE_TEAM_ID: ${{ secrets.FASTLANE_TEAM_ID }}
          MATCH_PASSWORD: ${{ secrets.MATCH_PASSWORD }}
          MATCH_GIT_BASIC_AUTHORIZATION: ${{ secrets.MATCH_GIT_BASIC_AUTHORIZATION }}
```

---

## Optional: Trigger from Main Repository

If you want to trigger app builds from the main FreegleDockerWSL repository, you can use repository dispatch events.

### In App Repositories

Add this workflow to receive triggers:

```yaml
# .github/workflows/deploy-on-trigger.yml
name: Deploy on Trigger

on:
  repository_dispatch:
    types: [deploy-app]

jobs:
  deploy-android:
    # ... same as deploy-app.yml

  deploy-ios:
    # ... same as deploy-app.yml
```

### In Main Repository

Add a job to trigger app deployments:

```yaml
# .github/workflows/main.yml
jobs:
  trigger-app-builds:
    runs-on: ubuntu-latest
    needs: auto-merge
    if: github.ref == 'refs/heads/production'
    steps:
      - name: Trigger Freegle app deployment
        uses: peter-evans/repository-dispatch@v3
        with:
          token: ${{ secrets.REPO_DISPATCH_TOKEN }}
          repository: YOUR_ORG/iznik-nuxt3
          event-type: deploy-app

      - name: Trigger ModTools app deployment
        uses: peter-evans/repository-dispatch@v3
        with:
          token: ${{ secrets.REPO_DISPATCH_TOKEN }}
          repository: YOUR_ORG/iznik-nuxt3-modtools
          event-type: deploy-app
```

**Note**: `REPO_DISPATCH_TOKEN` must be a personal access token with `repo` scope.

---

## Cost Considerations

### GitHub Actions Pricing

**For Public Repositories:**
- ✅ **UNLIMITED minutes FREE**
- Linux, Windows, and macOS runners all included
- **Zero cost** for open source projects

**For Private Repositories:**
- Free tier: 2,000 minutes/month
- Linux: 1x multiplier
- macOS: 10x multiplier (200 actual minutes on free tier)
- Paid plans start at $4/month for additional minutes

**Monthly Estimate (if private):**
- Assuming 4 releases/month (2 Freegle + 2 ModTools)
- Android: 4 × 15 min = 60 minutes (60 billable minutes)
- iOS: 4 × 25 min = 100 minutes (1,000 billable minutes)
- **Total: ~1,060 billable minutes/month**
- Free tier covers this easily

**Comparison:**
- CircleCI: ~3,800 credits/month (paid after free tier)
- GitHub Actions (public repos): $0
- GitHub Actions (private repos): Free tier sufficient

---

## Benefits of GitHub Actions

1. **FREE for public repositories** - unlimited minutes
2. **Native GitHub integration** - no external service needed
3. **Faster setup** - secrets already in GitHub
4. **Better Actions ecosystem** - thousands of pre-built actions
5. **Concurrent jobs** - Android and iOS build in parallel
6. **Artifact storage** - build outputs stored with workflow
7. **Matrix builds** - easily test multiple versions

---

## Testing the Setup

1. **Verify secrets are set**
   ```bash
   # Go to repository Settings → Secrets and variables → Actions
   # Verify all required secrets are present
   ```

2. **Create test commit**
   ```bash
   git checkout -b test-github-actions
   echo "# Test" >> README.md
   git add README.md
   git commit -m "Test: GitHub Actions deployment"
   git push origin test-github-actions
   ```

3. **Monitor workflow**
   - Go to GitHub repository
   - Click "Actions" tab
   - Watch workflow progress
   - Check job logs for errors

4. **Verify uploads**
   - Check Google Play Console for Internal Track
   - Check TestFlight for iOS build

---

## Troubleshooting

### Workflow doesn't trigger
- Check branch name matches `app` in workflow file
- Verify workflow file is in `.github/workflows/` directory
- Check workflow file YAML syntax is valid

### Secret decoding fails
- Verify base64 encoding used correct flags
- Check no newlines in secret values
- Ensure `base64 -d` (not `-D` on some systems)

### macOS runner issues
- Check Xcode version compatibility with `runs-on` version
- Try different macOS version (macos-12, macos-13, macos-14)
- Verify certificate access and expiry

### Permission denied errors
- Check MATCH_GIT_BASIC_AUTHORIZATION format
- Verify personal access token has `repo` scope
- Ensure match repository exists and is accessible

---

## Advanced Features

### Conditional Jobs

Run iOS builds only on specific conditions:

```yaml
deploy-ios:
  runs-on: macos-13
  if: github.event_name == 'push' && github.ref == 'refs/heads/app'
  # ... rest of job
```

### Matrix Strategy

Test multiple Node versions:

```yaml
jobs:
  test:
    runs-on: ubuntu-latest
    strategy:
      matrix:
        node-version: [16, 18, 20]
    steps:
      - uses: actions/setup-node@v4
        with:
          node-version: ${{ matrix.node-version }}
      # ... run tests
```

### Reusable Workflows

Create shared workflows for both apps:

```yaml
# .github/workflows/deploy-reusable.yml
on:
  workflow_call:
    inputs:
      app-type:
        required: true
        type: string

jobs:
  deploy-android:
    runs-on: ubuntu-latest
    steps:
      # ... deployment steps using inputs.app-type
```

---

## Migration from CircleCI

If migrating from CircleCI:

1. **Secrets**: Copy all CircleCI environment variables to GitHub Secrets
2. **Caching**: GitHub Actions caching works similarly
3. **Artifacts**: Use `actions/upload-artifact` instead of `store_artifacts`
4. **Triggers**: Change from CircleCI webhook to GitHub push events
5. **Syntax**: Convert CircleCI YAML to GitHub Actions format

**Key syntax differences:**
```yaml
# CircleCI
- run:
    name: Build
    command: npm run build

# GitHub Actions
- name: Build
  run: npm run build
```

---

## References

- GitHub Actions Documentation: https://docs.github.com/en/actions
- Fastlane on GitHub Actions: https://docs.fastlane.tools/best-practices/continuous-integration/github/
- Actions Marketplace: https://github.com/marketplace?type=actions
- Setup Ruby Action: https://github.com/ruby/setup-ruby
- Setup Node Action: https://github.com/actions/setup-node
