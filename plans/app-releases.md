# App Release Automation with Fastlane

## Overview

This document outlines the step-by-step process for migrating from manual app releases to automated deployments using Fastlane integrated with CircleCI. The goal is to automatically deploy app releases when tests pass successfully by merging to the `app` branch.

## Current State

### Apps
- **Freegle App**: Built with Capacitor (Nuxt3-based, separate directory)
- **ModTools App**: Built with Cordova (requires different JDK)

### Current Process
1. Merge master into app branch
2. Bump version numbers manually
3. Build dev Android app, test via USB
4. Build release Android, upload to Google Play
5. Pull code on Mac, build with Capacitor (may fail initially)
6. Build debug iOS in XCode
7. Build release iOS with Capacitor, upload via Transporter
8. Small .sh scripts handle repetitive tasks

### Hardware
- **Android Testing**: Recent Android phone (A16)
- **iOS Testing**: Older iPhone (iOS 18, excluded from iOS 26)
- **Mac**: MacOS 14 (slow, low disk space, but functional)

## Target State

Automated deployment triggered by:
- Tests pass on master branch
- Merge to app branch
- Automatic build, sign, and deploy to stores
- Maintain ability to build locally for debugging

---

## Phase 1: Prerequisites Setup

### 1.1 Development Environment Setup

#### Android Environment
```bash
# Install Android Studio
# Download from: https://developer.android.com/studio

# Install required SDKs via Android Studio SDK Manager:
# - Android SDK Platform-Tools
# - Android SDK Build-Tools
# - Android SDK Platform (API 33+)

# Install Gradle (usually included with Android Studio)
# Verify: gradle --version

# Install JDK for Capacitor
# Download OpenJDK 17+: https://adoptium.net/
# Set JAVA_HOME environment variable

# Install JDK for Cordova (different version)
# Download OpenJDK 11: https://adoptium.net/
# Create separate JAVA_HOME_CORDOVA variable
```

#### iOS Environment (Mac)
```bash
# Install Xcode from App Store
# Minimum version: 14.0

# Install Xcode Command Line Tools
xcode-select --install

# Verify installation
xcode-select -p
```

#### Fastlane Installation
```bash
# Install Homebrew (if not already installed)
/bin/bash -c "$(curl -fsSL https://raw.githubusercontent.com/Homebrew/install/HEAD/install.sh)"

# Install fastlane
brew install fastlane

# Add fastlane to PATH (add to ~/.bash_profile or ~/.zshrc)
export PATH="$HOME/.fastlane/bin:$PATH"

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
     - Name: "CircleCI Fastlane Deploy"
     - Grant role: "Service Account User"
   - Create JSON key:
     - Click on service account → Keys → Add Key → Create New Key
     - Choose JSON format
     - Save as `google-play-api-key.json`

2. **Grant Play Console Access**
   - Go to Google Play Console: https://play.google.com/console
   - Select app → Setup → API access
   - Link service account created above
   - Grant permissions:
     - Release Manager (for production)
     - Or specific track permissions for beta/internal

3. **Store Credentials Securely**
   ```bash
   # Create fastlane directory structure (per app)
   mkdir -p iznik-nuxt3/fastlane
   mkdir -p iznik-nuxt3-modtools/fastlane

   # Store JSON key (DO NOT COMMIT)
   # Add to .gitignore
   echo "fastlane/*.json" >> .gitignore
   echo "fastlane/Appfile" >> .gitignore
   ```

#### App Store Connect (iOS)

1. **Generate App Store Connect API Key**
   - Go to: https://appstoreconnect.apple.com/access/api
   - Click "+" to create new key
   - Name: "CircleCI Fastlane Deploy"
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

   # This creates and stores:
   # - Distribution certificates
   # - Provisioning profiles
   # - Encrypted in private repo
   ```

3. **Store Credentials Securely**
   ```bash
   # Save API key file
   mkdir -p ~/.appstoreconnect/private_keys/
   cp AuthKey_XXXXXXXXXX.p8 ~/.appstoreconnect/private_keys/

   # Note these for CircleCI environment variables:
   # - APP_STORE_CONNECT_API_KEY_ISSUER_ID
   # - APP_STORE_CONNECT_API_KEY_KEY_ID
   # - APP_STORE_CONNECT_API_KEY_KEY (base64 encoded .p8 content)
   ```

---

## Phase 2: Fastlane Configuration

### 2.1 Freegle App (Capacitor)

#### Initialize Fastlane
```bash
cd iznik-nuxt3
fastlane init

# For Android:
# - Choose option 3: Manual setup
# - Package name: org.ilovefreegle.direct (verify from config)

# For iOS:
# - Choose option 2: Automate beta distribution to TestFlight
# - Apple ID: your-apple-id@ilovefreegle.org
# - App Identifier: org.ilovefreegle.direct (verify from config)
```

#### Create Fastfile (Android + iOS)
```ruby
# iznik-nuxt3/fastlane/Fastfile

default_platform(:android)

# Global variables
VERSION_FILE = "../VERSION.txt"

platform :android do
  desc "Build and deploy to Google Play Internal Testing"
  lane :internal do
    # Read version from file
    version = File.read(VERSION_FILE).strip

    # Update version in Android
    android_set_version_name(
      version_name: version,
      gradle_file: "android/app/build.gradle"
    )

    # Increment build number based on latest in Play Store
    latest_build = google_play_track_version_codes(
      track: 'internal',
      json_key: 'fastlane/google-play-api-key.json'
    ).first || 0

    android_set_version_code(
      version_code: latest_build + 1,
      gradle_file: "android/app/build.gradle"
    )

    # Build the app
    gradle(
      task: 'bundle',
      build_type: 'Release',
      project_dir: 'android/'
    )

    # Upload to Play Store
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
    # Read version from file
    version = File.read(VERSION_FILE).strip

    # Setup App Store Connect API Key
    app_store_connect_api_key(
      key_id: ENV['APP_STORE_CONNECT_API_KEY_KEY_ID'],
      issuer_id: ENV['APP_STORE_CONNECT_API_KEY_ISSUER_ID'],
      key_content: ENV['APP_STORE_CONNECT_API_KEY_KEY']
    )

    # Update version number
    increment_version_number(
      version_number: version,
      xcodeproj: "ios/App/App.xcodeproj"
    )

    # Increment build number based on TestFlight
    latest_build = latest_testflight_build_number(
      version: version,
      initial_build_number: 0
    )

    increment_build_number(
      build_number: latest_build + 1,
      xcodeproj: "ios/App/App.xcodeproj"
    )

    # Sync certificates and profiles
    match(
      type: "appstore",
      readonly: true
    )

    # Build the app
    gym(
      scheme: "App",
      workspace: "ios/App/App.xcworkspace",
      export_method: "app-store"
    )

    # Upload to TestFlight
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

# Android
for_platform :android do
  json_key_file("fastlane/google-play-api-key.json")
  package_name("org.ilovefreegle.direct")
end

# iOS
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
# Create version file in app root
echo "1.0.0" > iznik-nuxt3/VERSION.txt

# Add to git
git add VERSION.txt
git commit -m "Add VERSION.txt for automated version management"
```

### 2.2 ModTools App (Cordova)

#### Initialize Fastlane
```bash
cd iznik-nuxt3-modtools
fastlane init

# Similar process to Freegle app
# Update package names accordingly
```

#### Create Fastfile (Cordova-specific)
```ruby
# iznik-nuxt3-modtools/fastlane/Fastfile

default_platform(:android)

VERSION_FILE = "../VERSION.txt"

platform :android do
  desc "Build and deploy ModTools to Google Play Internal Testing"
  lane :internal do
    version = File.read(VERSION_FILE).strip

    # For Cordova, update config.xml instead
    sh("cd .. && cordova-set-version -v #{version}")

    # Get latest build number
    latest_build = google_play_track_version_codes(
      track: 'internal',
      json_key: 'fastlane/google-play-api-key.json'
    ).first || 0

    new_build = latest_build + 1

    # Update build number in config.xml
    sh("cd .. && cordova-set-version -b #{new_build}")

    # Build Cordova app
    sh("cd .. && cordova build android --release")

    # Upload to Play Store
    upload_to_play_store(
      track: 'internal',
      json_key: 'fastlane/google-play-api-key.json',
      apk: '../platforms/android/app/build/outputs/apk/release/app-release.apk',
      skip_upload_metadata: true,
      skip_upload_images: true,
      skip_upload_screenshots: true
    )
  end
end

platform :ios do
  desc "Build and upload ModTools to TestFlight"
  lane :beta do
    version = File.read(VERSION_FILE).strip

    app_store_connect_api_key(
      key_id: ENV['APP_STORE_CONNECT_API_KEY_KEY_ID'],
      issuer_id: ENV['APP_STORE_CONNECT_API_KEY_ISSUER_ID'],
      key_content: ENV['APP_STORE_CONNECT_API_KEY_KEY']
    )

    # Update Cordova version
    sh("cd .. && cordova-set-version -v #{version}")

    # Get latest build
    latest_build = latest_testflight_build_number(
      version: version,
      initial_build_number: 0
    )

    new_build = latest_build + 1
    sh("cd .. && cordova-set-version -b #{new_build}")

    # Sync certificates
    match(
      type: "appstore",
      readonly: true
    )

    # Build Cordova iOS
    sh("cd .. && cordova build ios --release")

    # Upload with pilot (manually specify IPA path)
    pilot(
      ipa: "../platforms/ios/build/device/ModTools.ipa",
      skip_waiting_for_build_processing: true,
      distribute_external: false
    )
  end
end
```

**Note**: You'll need to install `cordova-set-version`:
```bash
npm install -g cordova-set-version
```

---

## Phase 3: CircleCI Integration

### 3.1 CircleCI Environment Variables

Add the following to CircleCI project settings (both Freegle and ModTools):

#### Android Variables
- `GOOGLE_PLAY_JSON_KEY`: Base64 encoded JSON key file
  ```bash
  base64 -i google-play-api-key.json | pbcopy
  ```

#### iOS Variables
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

### 3.2 CircleCI Config for Freegle App

Create `.circleci/config-apps.yml`:

```yaml
version: 2.1

orbs:
  android: circleci/android@2.3.0

executors:
  android-executor:
    docker:
      - image: cimg/android:2024.01.1-node
    resource_class: large

  macos-executor:
    macos:
      xcode: 15.4.0
    resource_class: macos.m1.medium.gen1

commands:
  setup-android-fastlane:
    steps:
      - run:
          name: Install Fastlane
          command: |
            bundle config set --local path 'vendor/bundle'
            bundle install
      - run:
          name: Decode Google Play JSON Key
          command: |
            echo $GOOGLE_PLAY_JSON_KEY | base64 -d > fastlane/google-play-api-key.json

  setup-ios-fastlane:
    steps:
      - run:
          name: Install Fastlane
          command: |
            bundle config set --local path 'vendor/bundle'
            bundle install
      - run:
          name: Setup App Store Connect API Key
          command: |
            mkdir -p ~/.appstoreconnect/private_keys/
            echo $APP_STORE_CONNECT_API_KEY_KEY | base64 -d > ~/.appstoreconnect/private_keys/AuthKey_${APP_STORE_CONNECT_API_KEY_KEY_ID}.p8

jobs:
  build-android:
    executor: android-executor
    working_directory: ~/project/iznik-nuxt3
    steps:
      - checkout:
          path: ~/project
      - restore_cache:
          keys:
            - npm-deps-{{ checksum "package-lock.json" }}
      - run:
          name: Install Dependencies
          command: npm ci
      - save_cache:
          key: npm-deps-{{ checksum "package-lock.json" }}
          paths:
            - node_modules
      - run:
          name: Build Nuxt App
          command: npm run build
      - run:
          name: Sync Capacitor
          command: npx cap sync android
      - restore_cache:
          keys:
            - bundle-{{ checksum "Gemfile.lock" }}
      - setup-android-fastlane
      - save_cache:
          key: bundle-{{ checksum "Gemfile.lock" }}
          paths:
            - vendor/bundle
      - run:
          name: Build and Deploy to Internal Track
          command: bundle exec fastlane android internal
      - store_artifacts:
          path: android/app/build/outputs/bundle/release/

  build-ios:
    executor: macos-executor
    working_directory: ~/project/iznik-nuxt3
    steps:
      - checkout:
          path: ~/project
      - restore_cache:
          keys:
            - npm-deps-{{ checksum "package-lock.json" }}
      - run:
          name: Install Dependencies
          command: npm ci
      - save_cache:
          key: npm-deps-{{ checksum "package-lock.json" }}
          paths:
            - node_modules
      - run:
          name: Build Nuxt App
          command: npm run build
      - run:
          name: Sync Capacitor
          command: npx cap sync ios
      - restore_cache:
          keys:
            - bundle-{{ checksum "Gemfile.lock" }}
      - setup-ios-fastlane
      - save_cache:
          key: bundle-{{ checksum "Gemfile.lock" }}
          paths:
            - vendor/bundle
      - run:
          name: Build and Deploy to TestFlight
          command: bundle exec fastlane ios beta

workflows:
  version: 2

  # Deploy apps when app branch is updated
  deploy-apps:
    jobs:
      - build-android:
          filters:
            branches:
              only:
                - app
      - build-ios:
          filters:
            branches:
              only:
                - app
```

### 3.3 CircleCI Config for ModTools App

Similar structure but in `iznik-nuxt3-modtools/.circleci/config.yml`:

```yaml
version: 2.1

orbs:
  android: circleci/android@2.3.0

executors:
  android-executor:
    docker:
      - image: cimg/android:2024.01.1-node
    resource_class: large
    environment:
      # Use different JDK for Cordova
      JAVA_HOME: /usr/lib/jvm/java-11-openjdk-amd64

  macos-executor:
    macos:
      xcode: 15.4.0
    resource_class: macos.m1.medium.gen1

commands:
  setup-cordova-android:
    steps:
      - run:
          name: Install Cordova
          command: npm install -g cordova cordova-set-version
      - run:
          name: Add Android Platform
          command: cordova platform add android

  setup-cordova-ios:
    steps:
      - run:
          name: Install Cordova
          command: npm install -g cordova cordova-set-version
      - run:
          name: Add iOS Platform
          command: cordova platform add ios

jobs:
  build-android:
    executor: android-executor
    working_directory: ~/project/iznik-nuxt3-modtools
    steps:
      - checkout:
          path: ~/project
      - restore_cache:
          keys:
            - npm-deps-{{ checksum "package-lock.json" }}
      - run:
          name: Install Dependencies
          command: npm ci
      - save_cache:
          key: npm-deps-{{ checksum "package-lock.json" }}
          paths:
            - node_modules
      - setup-cordova-android
      - restore_cache:
          keys:
            - bundle-{{ checksum "Gemfile.lock" }}
      - run:
          name: Install Fastlane
          command: |
            bundle config set --local path 'vendor/bundle'
            bundle install
      - run:
          name: Decode Google Play JSON Key
          command: |
            echo $GOOGLE_PLAY_JSON_KEY | base64 -d > fastlane/google-play-api-key.json
      - save_cache:
          key: bundle-{{ checksum "Gemfile.lock" }}
          paths:
            - vendor/bundle
      - run:
          name: Build and Deploy to Internal Track
          command: bundle exec fastlane android internal

  build-ios:
    executor: macos-executor
    working_directory: ~/project/iznik-nuxt3-modtools
    steps:
      - checkout:
          path: ~/project
      - restore_cache:
          keys:
            - npm-deps-{{ checksum "package-lock.json" }}
      - run:
          name: Install Dependencies
          command: npm ci
      - save_cache:
          key: npm-deps-{{ checksum "package-lock.json" }}
          paths:
            - node_modules
      - setup-cordova-ios
      - restore_cache:
          keys:
            - bundle-{{ checksum "Gemfile.lock" }}
      - run:
          name: Install Fastlane
          command: |
            bundle config set --local path 'vendor/bundle'
            bundle install
      - run:
          name: Setup App Store Connect API Key
          command: |
            mkdir -p ~/.appstoreconnect/private_keys/
            echo $APP_STORE_CONNECT_API_KEY_KEY | base64 -d > ~/.appstoreconnect/private_keys/AuthKey_${APP_STORE_CONNECT_API_KEY_KEY_ID}.p8
      - save_cache:
          key: bundle-{{ checksum "Gemfile.lock" }}
          paths:
            - vendor/bundle
      - run:
          name: Build and Deploy to TestFlight
          command: bundle exec fastlane ios beta

workflows:
  version: 2

  deploy-modtools:
    jobs:
      - build-android:
          filters:
            branches:
              only:
                - app
      - build-ios:
          filters:
            branches:
              only:
                - app
```

---

## Phase 4: Local Development Scripts

### 4.1 Freegle App Scripts

Create `iznik-nuxt3/scripts/` directory:

#### Android Development Build
```bash
#!/bin/bash
# scripts/build-android-dev.sh

set -e

echo "Building Freegle Android (Development)..."

# Build Nuxt app
npm run build

# Sync with Capacitor
npx cap sync android

# Open in Android Studio
npx cap open android

echo "Android Studio opened. Build and run from there."
```

#### Android Release Build (Local)
```bash
#!/bin/bash
# scripts/build-android-release.sh

set -e

echo "Building Freegle Android (Release)..."

# Ensure we're on the right branch
BRANCH=$(git branch --show-current)
if [ "$BRANCH" != "app" ]; then
    echo "WARNING: Not on 'app' branch. Current branch: $BRANCH"
    read -p "Continue anyway? (y/n) " -n 1 -r
    echo
    if [[ ! $REPLY =~ ^[Yy]$ ]]; then
        exit 1
    fi
fi

# Build Nuxt app
npm run build

# Sync with Capacitor
npx cap sync android

# Run Fastlane
bundle exec fastlane android internal

echo "Release built and uploaded to Google Play Internal Track"
```

#### iOS Development Build
```bash
#!/bin/bash
# scripts/build-ios-dev.sh

set -e

echo "Building Freegle iOS (Development)..."

# Build Nuxt app
npm run build

# Sync with Capacitor
npx cap sync ios

# Open in Xcode
npx cap open ios

echo "Xcode opened. Build and run from there."
```

#### iOS Release Build (Local)
```bash
#!/bin/bash
# scripts/build-ios-release.sh

set -e

echo "Building Freegle iOS (Release)..."

# Ensure we're on the right branch
BRANCH=$(git branch --show-current)
if [ "$BRANCH" != "app" ]; then
    echo "WARNING: Not on 'app' branch. Current branch: $BRANCH"
    read -p "Continue anyway? (y/n) " -n 1 -r
    echo
    if [[ ! $REPLY =~ ^[Yy]$ ]]; then
        exit 1
    fi
fi

# Build Nuxt app
npm run build

# Sync with Capacitor
npx cap sync ios

# Run Fastlane
bundle exec fastlane ios beta

echo "Release built and uploaded to TestFlight"
```

#### Version Bump Script
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
    # Exact version provided
    NEW_VERSION="$1"
else
    # Parse current version
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

Make scripts executable:
```bash
chmod +x scripts/*.sh
```

### 4.2 ModTools App Scripts

Similar scripts in `iznik-nuxt3-modtools/scripts/` but adapted for Cordova:

```bash
#!/bin/bash
# scripts/build-android-dev.sh

set -e

echo "Building ModTools Android (Development)..."

# Build with Cordova
cordova build android

# Install on connected device
adb install -r platforms/android/app/build/outputs/apk/debug/app-debug.apk

echo "App installed on device"
```

```bash
#!/bin/bash
# scripts/build-android-release.sh

set -e

echo "Building ModTools Android (Release)..."

BRANCH=$(git branch --show-current)
if [ "$BRANCH" != "app" ]; then
    echo "WARNING: Not on 'app' branch. Current branch: $BRANCH"
    read -p "Continue anyway? (y/n) " -n 1 -r
    echo
    if [[ ! $REPLY =~ ^[Yy]$ ]]; then
        exit 1
    fi
fi

# Run Fastlane (handles Cordova build)
bundle exec fastlane android internal

echo "Release built and uploaded to Google Play Internal Track"
```

---

## Phase 5: Migration Process

### 5.1 Testing Phase

1. **Local Testing (Android)**
   ```bash
   cd iznik-nuxt3

   # Bump version for testing
   ./scripts/bump-version.sh patch

   # Build development version
   ./scripts/build-android-dev.sh

   # Test on device via USB
   # Verify app functionality

   # Build release locally
   ./scripts/build-android-release.sh

   # Verify upload to Internal Track in Play Console
   ```

2. **Local Testing (iOS)**
   ```bash
   # On Mac
   cd iznik-nuxt3

   # Pull latest from app branch
   git pull origin app

   # Build development version
   ./scripts/build-ios-dev.sh

   # Test in Xcode simulator and device

   # Build release locally
   ./scripts/build-ios-release.sh

   # Verify upload to TestFlight
   ```

3. **CircleCI Testing**
   ```bash
   # Create test branch
   git checkout -b test-fastlane-deployment

   # Make a trivial change
   echo "# Test" >> README.md
   git add README.md
   git commit -m "Test: Fastlane deployment"

   # Push to trigger CircleCI
   git push origin test-fastlane-deployment

   # Monitor CircleCI build
   # Verify builds complete
   # Check Play Console and TestFlight for uploads
   ```

### 5.2 Integration with Existing Workflow

Update the main CircleCI config to trigger app builds:

```yaml
# .circleci/config.yml (main FreegleDockerWSL repo)

workflows:
  version: 2

  main:
    jobs:
      - run-tests
      - auto-merge:
          requires:
            - run-tests
          filters:
            branches:
              only: master
      - trigger-app-builds:
          requires:
            - auto-merge
          filters:
            branches:
              only: production

jobs:
  trigger-app-builds:
    docker:
      - image: cimg/base:stable
    steps:
      - run:
          name: Trigger App Builds
          command: |
            # Trigger Freegle app build
            curl -X POST \
              --header "Content-Type: application/json" \
              -d '{
                "branch": "app",
                "parameters": {
                  "trigger_build": true
                }
              }' \
              "https://circleci.com/api/v2/project/gh/YOUR_ORG/iznik-nuxt3/pipeline?circle-token=${CIRCLECI_API_TOKEN}"

            # Trigger ModTools app build
            curl -X POST \
              --header "Content-Type: application/json" \
              -d '{
                "branch": "app",
                "parameters": {
                  "trigger_build": true
                }
              }' \
              "https://circleci.com/api/v2/project/gh/YOUR_ORG/iznik-nuxt3-modtools/pipeline?circle-token=${CIRCLECI_API_TOKEN}"
```

**Note**: Add `CIRCLECI_API_TOKEN` to CircleCI environment variables.

### 5.3 Documentation Updates

Create README files for each app:

```markdown
# iznik-nuxt3/APP_DEPLOYMENT.md

# Freegle App Deployment

## Automatic Deployment

When changes are merged to the `app` branch and tests pass:
1. CircleCI automatically triggers app builds
2. Android build uploads to Google Play Internal Track
3. iOS build uploads to TestFlight
4. Version numbers are automatically incremented

## Manual Deployment

### Development Build
```bash
./scripts/build-android-dev.sh  # Android
./scripts/build-ios-dev.sh      # iOS (on Mac)
```

### Release Build
```bash
./scripts/build-android-release.sh  # Android
./scripts/build-ios-release.sh      # iOS (on Mac)
```

### Version Management
```bash
# Bump patch version (1.0.0 -> 1.0.1)
./scripts/bump-version.sh patch

# Bump minor version (1.0.1 -> 1.1.0)
./scripts/bump-version.sh minor

# Bump major version (1.1.0 -> 2.0.0)
./scripts/bump-version.sh major

# Set specific version
./scripts/bump-version.sh 2.5.3
```

## Promoting Releases

### Android
```bash
# Promote Internal -> Beta
bundle exec fastlane android promote_beta

# Promote Beta -> Production
bundle exec fastlane android promote_production
```

### iOS
```bash
# Promote TestFlight -> Production
bundle exec fastlane ios release
```

## Troubleshooting

### Android Build Fails
1. Check Java version: `java -version` (should be 17+)
2. Verify Gradle: `gradle --version`
3. Clear build cache: `cd android && ./gradlew clean`

### iOS Build Fails
1. Check Xcode: `xcode-select -p`
2. Update certificates: `bundle exec fastlane match appstore --force`
3. Clean build: Open Xcode -> Product -> Clean Build Folder

### Certificate Issues
```bash
# Update certificates manually
bundle exec fastlane match appstore --force
bundle exec fastlane match development --force
```
```

---

## Phase 6: Maintenance

### 6.1 Annual iOS Certificate Renewal

```bash
# Renew certificates (do this annually or when certificates expire)
cd iznik-nuxt3  # or iznik-nuxt3-modtools

# Force regenerate certificates
bundle exec fastlane match appstore --force

# Update in CircleCI
# Re-encode and update APP_STORE_CONNECT_API_KEY_KEY if needed

# Test build
bundle exec fastlane ios beta
```

### 6.2 Updating Fastlane

```bash
# Update Fastlane in both app repos
cd iznik-nuxt3
bundle update fastlane

cd ../iznik-nuxt3-modtools
bundle update fastlane

# Commit Gemfile.lock changes
git add Gemfile.lock
git commit -m "Update Fastlane"
```

### 6.3 Monitoring

- **Google Play Console**: https://play.google.com/console
  - Monitor Internal/Beta/Production tracks
  - Check crash reports
  - Review user feedback

- **App Store Connect**: https://appstoreconnect.apple.com
  - Monitor TestFlight builds
  - Check crash reports
  - Review TestFlight feedback

- **CircleCI**: https://app.circleci.com
  - Monitor build success/failure
  - Check build times
  - Review resource usage

---

## Phase 7: Rollback Plan

### If Automated Deployment Fails

1. **Immediate Rollback to Manual Process**
   ```bash
   # Disable CircleCI app deployment
   # Comment out trigger-app-builds job in .circleci/config.yml

   # Revert to manual deployment
   git checkout app
   ./scripts/bump-version.sh patch
   ./scripts/build-android-release.sh
   ./scripts/build-ios-release.sh
   ```

2. **Debug and Fix**
   - Check CircleCI logs
   - Verify credentials are valid
   - Test locally first
   - Re-enable CircleCI once fixed

3. **Emergency Hotfix**
   ```bash
   # Create hotfix branch
   git checkout -b hotfix/critical-fix app

   # Make fix
   # Test locally

   # Merge to app
   git checkout app
   git merge hotfix/critical-fix

   # Manual release
   ./scripts/build-android-release.sh
   ./scripts/build-ios-release.sh
   ```

---

## Appendix A: Cost Considerations

### CircleCI Resource Usage

**Android Builds:**
- Executor: `large` (4 CPUs, 8GB RAM)
- Estimated time: 15-20 minutes
- Credit usage: ~200 credits per build

**iOS Builds:**
- Executor: `macos.m1.medium.gen1`
- Estimated time: 20-30 minutes
- Credit usage: ~600-900 credits per build

**Monthly Estimate:**
- Assuming 4 releases/month (2 Freegle + 2 ModTools)
- Android: 4 × 200 = 800 credits
- iOS: 4 × 750 = 3,000 credits
- **Total: ~3,800 credits/month**

### Alternatives to Reduce Costs

1. **Use GitHub Actions** (free for public repos)
2. **Use Codemagic** (1,500 build minutes/month free)
3. **Self-hosted CircleCI runner** on Mac mini
4. **Only auto-deploy Android**, manual iOS builds

---

## Appendix B: Security Best Practices

### Secrets Management

1. **Never Commit Secrets**
   ```gitignore
   # .gitignore
   fastlane/*.json
   fastlane/Appfile
   *.keystore
   *.p8
   *.p12
   *.mobileprovision
   ```

2. **Rotate Credentials Regularly**
   - Google Play API keys: Annually
   - App Store Connect API keys: Annually
   - Signing certificates: Before expiry

3. **Use Environment Variables**
   - All secrets in CircleCI environment variables
   - Use base64 encoding for file contents
   - Mark secrets as sensitive in CircleCI

4. **Limit Access**
   - Service accounts with minimal permissions
   - Separate credentials for different environments
   - Regular audit of who has access

---

## Appendix C: Troubleshooting Guide

### Common Issues

#### "Unauthorized" errors from Google Play
```bash
# Check JSON key is valid
cat fastlane/google-play-api-key.json

# Verify service account has access in Play Console
# Re-download JSON key if needed
```

#### "Invalid credentials" from App Store Connect
```bash
# Check API key expiry
# Verify environment variables are set correctly
# Re-encode .p8 file:
base64 -i AuthKey_XXXXXXXXXX.p8

# Test locally first
bundle exec fastlane ios beta
```

#### Build number conflicts
```bash
# Android: Manually increment in build.gradle
# iOS: Manually increment in Xcode

# Or delete previous build from store and retry
```

#### Certificate/provisioning profile issues
```bash
# Clear match cache
bundle exec fastlane match nuke distribution
bundle exec fastlane match nuke development

# Regenerate
bundle exec fastlane match appstore
bundle exec fastlane match development
```

#### Gradle build failures
```bash
# Clear Gradle cache
cd android
./gradlew clean
./gradlew --stop

# Reset Android build
rm -rf android/build
rm -rf android/app/build

# Try again
npx cap sync android
bundle exec fastlane android internal
```

---

## Success Criteria

- [ ] Fastlane installed and configured locally
- [ ] Google Play service account created and tested
- [ ] App Store Connect API key created and tested
- [ ] Fastfile works locally for both Android and iOS
- [ ] Version bumping script works correctly
- [ ] CircleCI successfully builds and deploys Android
- [ ] CircleCI successfully builds and deploys iOS
- [ ] Apps appear in Internal Track / TestFlight
- [ ] Manual deployment scripts work for debugging
- [ ] Documentation is complete and accurate
- [ ] Team is trained on new process
- [ ] Rollback plan is tested
- [ ] First production release deployed successfully

---

## Timeline Estimate

**Phase 1: Prerequisites** (1-2 days)
- Environment setup: 4-6 hours
- Credentials setup: 2-3 hours

**Phase 2: Fastlane Config** (2-3 days)
- Freegle app: 1 day
- ModTools app: 1 day
- Testing and refinement: 1 day

**Phase 3: CircleCI Integration** (2-3 days)
- Config creation: 1 day
- Testing and debugging: 1-2 days

**Phase 4: Local Scripts** (1 day)
- Script creation: 3-4 hours
- Testing: 2-3 hours

**Phase 5: Migration** (3-5 days)
- Testing phase: 2-3 days
- Integration: 1 day
- Documentation: 1 day

**Phase 6-7: Maintenance & Rollback** (Ongoing)
- Initial setup: 1 day
- Ongoing: As needed

**Total Estimated Time: 9-14 days**

---

## Next Steps

1. Review this plan and adjust as needed
2. Set up development environment prerequisites
3. Create service accounts and API keys
4. Initialize Fastlane locally
5. Test local builds before CircleCI integration
6. Gradually roll out automation (Android first, then iOS)
7. Monitor and iterate based on issues encountered

---

## References

- Fastlane Documentation: https://docs.fastlane.tools/
- Capacitor CI/CD Guide: https://capacitorjs.com/docs/guides/ci-cd
- CircleCI Android Deploy: https://circleci.com/docs/guides/deploy/deploy-android-applications/
- CircleCI iOS Deploy: https://circleci.com/docs/guides/deploy/deploy-ios-applications/
- Google Play Console: https://play.google.com/console
- App Store Connect: https://appstoreconnect.apple.com
