# App Release Automation with Fastlane

## Overview

This document outlines automated app deployment using Fastlane. The goal is to automatically deploy app releases when tests pass by merging to the `app` branch.

## Current State

### Apps
- **Freegle App**: Built with Capacitor (Nuxt3-based)
- **ModTools App**: Built with Cordova (requires different JDK)

### Current Manual Process
1. Merge master into app branch
2. Bump version numbers manually
3. Build dev Android app, test via USB
4. Build release Android, upload to Google Play
5. Pull code on Mac, build with Capacitor
6. Build debug iOS in XCode
7. Build release iOS with Capacitor, upload via Transporter

## Target State

Automated deployment triggered by:
- Tests pass on master branch
- Merge to app branch
- Automatic build, sign, and deploy to stores

---

## CI/CD Platform Options

Choose one of the following platforms for automated deployment:

### Option 1: GitHub Actions (Recommended for Public Repos)
**[See detailed implementation →](app-releases-github.md)**

**Pros:**
- ✅ **FREE for public repositories** (unlimited minutes)
- ✅ Native GitHub integration
- ✅ Fast setup, no external service
- ✅ Large Actions marketplace

**Cons:**
- Limited free minutes for private repos (2,000/month)
- macOS runners slightly slower than CircleCI M1

**Cost:** $0 for public repos, minimal for private

### Option 2: CircleCI
**[See detailed implementation →](app-releases-circleci.md)**

**Pros:**
- Fast M1 macOS runners
- Robust caching system
- Good for complex workflows

**Cons:**
- Costs ~3,800 credits/month for iOS builds
- Requires external service setup

**Cost:** Free tier (30,000 credits), then paid plans

---

## Phase 1: Prerequisites (Both Options)

### 1.1 Fastlane Installation

```bash
# Install Homebrew (if not already installed)
/bin/bash -c "$(curl -fsSL https://raw.githubusercontent.com/Homebrew/install/HEAD/install.sh)"

# Install fastlane
brew install fastlane

# Verify installation
fastlane --version

# Install Bundler for dependency management
gem install bundler
```

### 1.2 App Store Credentials Setup

#### Google Play Console (Android)

1. **Create Service Account**
   - Go to Google Cloud Console: https://console.cloud.google.com
   - Create new project or select existing
   - Enable Google Play Android Developer API
   - Create Service Account:
     - IAM & Admin → Service Accounts → Create Service Account
     - Name: "CI/CD Fastlane Deploy"
     - Grant role: "Service Account User"
   - Create JSON key:
     - Click on service account → Keys → Add Key → Create New Key
     - Choose JSON format
     - Save as `google-play-api-key.json`

2. **Grant Play Console Access**
   - Go to Google Play Console: https://play.google.com/console
   - Select app → Setup → API access
   - Link service account created above
   - Grant permissions: Release Manager

3. **Store Credentials Securely**
   ```bash
   # Add to .gitignore
   echo "fastlane/*.json" >> .gitignore
   echo "fastlane/Appfile" >> .gitignore
   ```

#### App Store Connect (iOS)

1. **Generate App Store Connect API Key**
   - Go to: https://appstoreconnect.apple.com/access/api
   - Click "+" to create new key
   - Name: "CI/CD Fastlane Deploy"
   - Access: "App Manager" or "Admin"
   - Download the `.p8` key file
   - Note the following values:
     - **Issuer ID** (UUID format)
     - **Key ID** (10-character string)
     - **Key file** (.p8 file content)

2. **Certificate Management with Match**
   ```bash
   # Create private Git repository for certificates
   # (GitHub private repo recommended)

   # Initialize fastlane match
   cd iznik-nuxt3
   fastlane match init

   # Choose storage: git
   # Enter repository URL: git@github.com:your-org/certificates.git

   # Generate certificates
   fastlane match appstore
   fastlane match development
   ```

---

## Phase 2: Fastlane Configuration

### 2.1 Freegle App (Capacitor)

#### Initialize Fastlane
```bash
cd iznik-nuxt3
fastlane init

# For Android: Choose option 3 (Manual setup)
# Package name: org.ilovefreegle.direct

# For iOS: Choose option 2 (Automate beta distribution)
# App Identifier: org.ilovefreegle.direct
```

#### Create Fastfile (Android + iOS)
```ruby
# iznik-nuxt3/fastlane/Fastfile

default_platform(:android)

VERSION_FILE = "../VERSION.txt"

platform :android do
  desc "Build and deploy to Google Play Internal Testing"
  lane :internal do
    version = File.read(VERSION_FILE).strip

    android_set_version_name(
      version_name: version,
      gradle_file: "android/app/build.gradle"
    )

    latest_build = google_play_track_version_codes(
      track: 'internal',
      json_key: 'fastlane/google-play-api-key.json'
    ).first || 0

    android_set_version_code(
      version_code: latest_build + 1,
      gradle_file: "android/app/build.gradle"
    )

    gradle(
      task: 'bundle',
      build_type: 'Release',
      project_dir: 'android/'
    )

    upload_to_play_store(
      track: 'internal',
      json_key: 'fastlane/google-play-api-key.json',
      skip_upload_apk: true,
      skip_upload_metadata: true,
      skip_upload_images: true,
      skip_upload_screenshots: true
    )
  end

  desc "Promote Internal to Beta"
  lane :promote_beta do
    upload_to_play_store(
      track: 'internal',
      track_promote_to: 'beta',
      json_key: 'fastlane/google-play-api-key.json',
      skip_upload_apk: true,
      skip_upload_aab: true
    )
  end

  desc "Promote Beta to Production"
  lane :promote_production do
    upload_to_play_store(
      track: 'beta',
      track_promote_to: 'production',
      json_key: 'fastlane/google-play-api-key.json',
      skip_upload_apk: true,
      skip_upload_aab: true
    )
  end
end

platform :ios do
  desc "Build and upload to TestFlight"
  lane :beta do
    version = File.read(VERSION_FILE).strip

    app_store_connect_api_key(
      key_id: ENV['APP_STORE_CONNECT_API_KEY_KEY_ID'],
      issuer_id: ENV['APP_STORE_CONNECT_API_KEY_ISSUER_ID'],
      key_content: ENV['APP_STORE_CONNECT_API_KEY_KEY']
    )

    increment_version_number(
      version_number: version,
      xcodeproj: "ios/App/App.xcodeproj"
    )

    latest_build = latest_testflight_build_number(
      version: version,
      initial_build_number: 0
    )

    increment_build_number(
      build_number: latest_build + 1,
      xcodeproj: "ios/App/App.xcodeproj"
    )

    match(
      type: "appstore",
      readonly: true
    )

    gym(
      scheme: "App",
      workspace: "ios/App/App.xcworkspace",
      export_method: "app-store"
    )

    pilot(
      skip_waiting_for_build_processing: true,
      distribute_external: false,
      changelog: "New build from CI/CD"
    )
  end

  desc "Promote TestFlight to Production"
  lane :release do
    app_store_connect_api_key(
      key_id: ENV['APP_STORE_CONNECT_API_KEY_KEY_ID'],
      issuer_id: ENV['APP_STORE_CONNECT_API_KEY_ISSUER_ID'],
      key_content: ENV['APP_STORE_CONNECT_API_KEY_KEY']
    )

    deliver(
      submit_for_review: true,
      automatic_release: false,
      force: true,
      skip_metadata: true,
      skip_screenshots: true,
      precheck_include_in_app_purchases: false
    )
  end
end
```

#### Create Appfile
```ruby
# iznik-nuxt3/fastlane/Appfile

for_platform :android do
  json_key_file("fastlane/google-play-api-key.json")
  package_name("org.ilovefreegle.direct")
end

for_platform :ios do
  app_identifier("org.ilovefreegle.direct")
  apple_id(ENV["FASTLANE_APPLE_ID"])
  team_id(ENV["FASTLANE_TEAM_ID"])
end
```

#### Create Gemfile
```ruby
# iznik-nuxt3/Gemfile

source "https://rubygems.org"

gem "fastlane"
gem "fastlane-plugin-increment_version_code"

plugins_path = File.join(File.dirname(__FILE__), 'fastlane', 'Pluginfile')
eval_gemfile(plugins_path) if File.exist?(plugins_path)
```

#### Create VERSION.txt
```bash
echo "1.0.0" > iznik-nuxt3/VERSION.txt
git add VERSION.txt
git commit -m "Add VERSION.txt for automated version management"
```

### 2.2 ModTools App (Cordova)

Similar setup to Freegle app, but with Cordova-specific configuration:

```ruby
# iznik-nuxt3-modtools/fastlane/Fastfile

platform :android do
  lane :internal do
    version = File.read(VERSION_FILE).strip
    sh("cd .. && cordova-set-version -v #{version}")

    latest_build = google_play_track_version_codes(
      track: 'internal',
      json_key: 'fastlane/google-play-api-key.json'
    ).first || 0

    new_build = latest_build + 1
    sh("cd .. && cordova-set-version -b #{new_build}")
    sh("cd .. && cordova build android --release")

    upload_to_play_store(
      track: 'internal',
      json_key: 'fastlane/google-play-api-key.json',
      apk: '../platforms/android/app/build/outputs/apk/release/app-release.apk',
      skip_upload_metadata: true
    )
  end
end

platform :ios do
  lane :beta do
    version = File.read(VERSION_FILE).strip
    sh("cd .. && cordova-set-version -v #{version}")

    # ... similar iOS setup with Cordova build commands
  end
end
```

**Note**: Install `cordova-set-version`:
```bash
npm install -g cordova-set-version
```

---

## Phase 3: Version Management

Create version bump script in both app repositories:

```bash
#!/bin/bash
# scripts/bump-version.sh

set -e

if [ -z "$1" ]; then
    echo "Usage: ./scripts/bump-version.sh [major|minor|patch|VERSION]"
    exit 1
fi

CURRENT_VERSION=$(cat VERSION.txt)
echo "Current version: $CURRENT_VERSION"

if [[ "$1" =~ ^[0-9]+\.[0-9]+\.[0-9]+$ ]]; then
    NEW_VERSION="$1"
else
    IFS='.' read -r -a VERSION_PARTS <<< "$CURRENT_VERSION"
    MAJOR="${VERSION_PARTS[0]}"
    MINOR="${VERSION_PARTS[1]}"
    PATCH="${VERSION_PARTS[2]}"

    case "$1" in
        major)
            MAJOR=$((MAJOR + 1))
            MINOR=0
            PATCH=0
            ;;
        minor)
            MINOR=$((MINOR + 1))
            PATCH=0
            ;;
        patch)
            PATCH=$((PATCH + 1))
            ;;
        *)
            echo "Invalid bump type: $1"
            exit 1
            ;;
    esac

    NEW_VERSION="$MAJOR.$MINOR.$PATCH"
fi

echo "New version: $NEW_VERSION"
echo "$NEW_VERSION" > VERSION.txt

git add VERSION.txt
git commit -m "Bump version to $NEW_VERSION"

echo "Version bumped to $NEW_VERSION and committed"
```

Make executable:
```bash
chmod +x scripts/bump-version.sh
```

---

## Phase 4: CI/CD Implementation

Choose your platform:

- **[GitHub Actions Implementation →](app-releases-github.md)** (Recommended for public repos)
- **[CircleCI Implementation →](app-releases-circleci.md)**

---

## Promoting Releases

### Android
```bash
# Promote Internal → Beta
bundle exec fastlane android promote_beta

# Promote Beta → Production
bundle exec fastlane android promote_production
```

### iOS
```bash
# Promote TestFlight → Production
bundle exec fastlane ios release
```

---

## Rollback Plan

### If Automated Deployment Fails

1. **Disable automated deployment**
   - Comment out CI/CD trigger in workflow file
   - Push the change to prevent further builds

2. **Debug and fix**
   - Check CI/CD logs for errors
   - Verify credentials are valid
   - Check certificate expiry dates
   - Test on a branch before re-enabling

3. **Emergency hotfix process**
   ```bash
   git checkout -b hotfix/critical-fix app
   # Make the fix
   git commit -m "Hotfix: description"
   git checkout app
   git merge hotfix/critical-fix
   git push origin app
   # Monitor CI/CD build
   ```

---

## Certificate Renewal (Annual)

```bash
# Force regenerate certificates
cd iznik-nuxt3  # or iznik-nuxt3-modtools
bundle exec fastlane match appstore --force

# Update base64 encoded key in CI/CD secrets if needed
# Test build
bundle exec fastlane ios beta
```

---

## Security Best Practices

### Never Commit Secrets
```gitignore
# .gitignore
fastlane/*.json
fastlane/Appfile
*.keystore
*.p8
*.p12
*.mobileprovision
```

### Rotate Credentials Regularly
- Google Play API keys: Annually
- App Store Connect API keys: Annually
- Signing certificates: Before expiry

### Use Environment Variables
- All secrets in CI/CD environment variables
- Use base64 encoding for file contents
- Mark secrets as sensitive

---

## Success Criteria

- [ ] Fastlane installed and configured
- [ ] Google Play service account created and tested
- [ ] App Store Connect API key created and tested
- [ ] Fastfile works locally for both platforms
- [ ] Version bumping script works
- [ ] CI/CD successfully builds and deploys Android
- [ ] CI/CD successfully builds and deploys iOS
- [ ] Apps appear in Internal Track / TestFlight
- [ ] Emergency hotfix process documented
- [ ] Documentation complete
- [ ] First production release deployed successfully

---

## Timeline Estimate

**Phase 1: Prerequisites** (1-2 days)
- Fastlane installation: 2 hours
- Credentials setup: 3-4 hours

**Phase 2: Fastlane Config** (2-3 days)
- Freegle app: 1 day
- ModTools app: 1 day
- Testing: 1 day

**Phase 3: Version Management** (0.5 days)
- Script creation and testing

**Phase 4: CI/CD Implementation** (1-2 days)
- Config creation: 0.5 day
- Testing and debugging: 1-1.5 days

**Total Estimated Time: 5-8 days**

---

## References

- Fastlane Documentation: https://docs.fastlane.tools/
- Capacitor CI/CD Guide: https://capacitorjs.com/docs/guides/ci-cd
- Google Play Console: https://play.google.com/console
- App Store Connect: https://appstoreconnect.apple.com
