# iOS CircleCI Build Implementation Plan

## Current State Analysis

✅ **What's Working:**
- Android builds successfully deploying to Google Play Beta via CircleCI
- Fastlane configured for Android with auto-versioning
- Capacitor iOS project exists at `ios/App/`
- CircleCI config at `.circleci/config.yml` with Android executor
- Branch: `app-ci-fd` (not `app` as in original docs)

❌ **What's Missing:**
- iOS Fastlane lane configuration
- macOS executor in CircleCI config
- App Store Connect credentials
- Code signing certificates (via Fastlane Match)
- iOS-specific environment variables in CircleCI

## Prerequisites

### 1. App Store Connect API Key
**Action Required:** Create API key with App Manager or Admin access

1. Visit https://appstoreconnect.apple.com/access/api
2. Create new key: "CircleCI iOS Deploy"
3. Download `.p8` file
4. Note these values:
   - **Issuer ID**: (UUID format, e.g., `57246542-96fe-1a63-e053-0824d011072a`)
   - **Key ID**: (10 characters, e.g., `2X9R4HXF34`)
   - **Key Content**: (base64 of .p8 file)

### 2. Apple Developer Team Information
**Action Required:** Get Team ID from Apple Developer portal

- Visit https://developer.apple.com/account
- Note **Team ID** (e.g., `S8QB4VV633`)
- Note **Apple ID** (email used for developer account)

### 3. Code Signing with Fastlane Match
**Action Required:** Set up Match for certificate management

```bash
# On your Mac with Xcode installed
cd iznik-nuxt3
git checkout app-ci-fd

# Initialize Fastlane Match
bundle exec fastlane match init

# Choose storage: git
# Repository URL: Create private repo (e.g., git@github.com:Freegle/certificates-private.git)
# IMPORTANT: This repo must be private!

# Generate certificates
MATCH_PASSWORD="create-a-strong-password" \
  bundle exec fastlane match appstore \
  --app_identifier org.ilovefreegle.direct \
  --team_id YOUR_TEAM_ID

MATCH_PASSWORD="create-a-strong-password" \
  bundle exec fastlane match development \
  --app_identifier org.ilovefreegle.direct \
  --team_id YOUR_TEAM_ID
```

**Store these securely:**
- `MATCH_PASSWORD`: The password used above
- `MATCH_GIT_BASIC_AUTHORIZATION`: Base64 of `username:github_personal_access_token`
  ```bash
  echo -n "your-github-username:ghp_yourtoken" | base64
  ```

## Implementation Steps

### Step 1: Add CircleCI Environment Variables

Navigate to CircleCI project settings for `iznik-nuxt3` and add:

**iOS App Store Connect:**
- `APP_STORE_CONNECT_API_KEY_ISSUER_ID`
- `APP_STORE_CONNECT_API_KEY_KEY_ID`
- `APP_STORE_CONNECT_API_KEY_KEY` (base64 of .p8 file)
- `FASTLANE_APPLE_ID` (your Apple ID email)
- `FASTLANE_TEAM_ID` (from Apple Developer)

**Code Signing:**
- `MATCH_PASSWORD` (password for certificate encryption)
- `MATCH_GIT_BASIC_AUTHORIZATION` (base64 of username:token)
- `MATCH_GIT_URL` (git@github.com:Freegle/certificates-private.git)

**Optional (for Sentry):**
- `SENTRY_DSN_APP_FD` (if using Sentry for iOS)

### Step 2: Create Matchfile

Create `iznik-nuxt3/fastlane/Matchfile`:

```ruby
git_url(ENV["MATCH_GIT_URL"])
git_basic_authorization(ENV["MATCH_GIT_BASIC_AUTHORIZATION"])

storage_mode("git")

type("appstore") # Can also be "development" or "adhoc"

app_identifier(["org.ilovefreegle.direct"])
username(ENV["FASTLANE_APPLE_ID"])
team_id(ENV["FASTLANE_TEAM_ID"])
```

### Step 3: Update Appfile

Update `iznik-nuxt3/fastlane/Appfile` to add iOS configuration:

```ruby
for_platform :android do
  json_key_file("fastlane/google-play-api-key.json")
  package_name("org.ilovefreegle.direct")
end

for_platform :ios do
  app_identifier("org.ilovefreegle.direct")
  apple_id(ENV["FASTLANE_APPLE_ID"])
  itc_team_id(ENV["FASTLANE_TEAM_ID"])
  team_id(ENV["FASTLANE_TEAM_ID"])
end
```

### Step 4: Add iOS Lane to Fastfile

Add this iOS platform block to `iznik-nuxt3/fastlane/Fastfile`:

```ruby
platform :ios do
  desc "Build and upload to TestFlight"
  lane :beta do
    # Validate environment variables
    unless ENV['APP_STORE_CONNECT_API_KEY_KEY_ID']
      UI.user_error!("❌ CRITICAL: APP_STORE_CONNECT_API_KEY_KEY_ID not set")
    end
    unless ENV['APP_STORE_CONNECT_API_KEY_ISSUER_ID']
      UI.user_error!("❌ CRITICAL: APP_STORE_CONNECT_API_KEY_ISSUER_ID not set")
    end
    unless ENV['APP_STORE_CONNECT_API_KEY_KEY']
      UI.user_error!("❌ CRITICAL: APP_STORE_CONNECT_API_KEY_KEY not set")
    end

    # Set up App Store Connect API Key
    app_store_connect_api_key(
      key_id: ENV['APP_STORE_CONNECT_API_KEY_KEY_ID'],
      issuer_id: ENV['APP_STORE_CONNECT_API_KEY_ISSUER_ID'],
      key_content: Base64.decode64(ENV['APP_STORE_CONNECT_API_KEY_KEY']),
      is_key_content_base64: false
    )

    # Get version from CircleCI environment variable (same as Android)
    current_version = ENV['CURRENT_VERSION']
    unless current_version
      UI.user_error!("❌ CRITICAL: CURRENT_VERSION environment variable not set")
    end

    UI.message("📱 Current version from CircleCI: #{current_version}")

    # Auto-increment patch version (matching Android logic)
    parts = current_version.split('.').map(&:to_i)
    unless parts.length == 3
      UI.user_error!("❌ CRITICAL: Invalid version format '#{current_version}'")
    end

    parts[2] += 1
    version = parts.join('.')
    UI.success("📱 Auto-incremented version: #{current_version} → #{version}")

    # Set version number in Xcode
    increment_version_number(
      version_number: version,
      xcodeproj: "ios/App/App.xcodeproj"
    )

    # Get latest TestFlight build number
    begin
      latest_build = latest_testflight_build_number(
        version: version,
        initial_build_number: 0,
        app_identifier: "org.ilovefreegle.direct"
      )
      UI.message("📊 Latest TestFlight build for v#{version}: #{latest_build}")
    rescue => e
      UI.message("ℹ️  No existing builds for v#{version}, starting at 0")
      latest_build = 0
    end

    new_build = latest_build + 1
    UI.success("📊 New build number: #{new_build}")

    # Set build number in Xcode
    increment_build_number(
      build_number: new_build,
      xcodeproj: "ios/App/App.xcodeproj"
    )

    # Get code signing certificates via Match
    match(
      type: "appstore",
      readonly: true,
      app_identifier: "org.ilovefreegle.direct"
    )

    # Build the app
    gym(
      scheme: "App",
      workspace: "ios/App/App.xcworkspace",
      export_method: "app-store",
      export_options: {
        provisioningProfiles: {
          "org.ilovefreegle.direct" => "match AppStore org.ilovefreegle.direct"
        }
      },
      clean: true,
      output_directory: "./build"
    )

    # Upload to TestFlight
    pilot(
      skip_waiting_for_build_processing: true,
      distribute_external: false,
      changelog: "Version #{version} - Bug fixes and improvements",
      app_identifier: "org.ilovefreegle.direct"
    )

    UI.success("✅ Successfully uploaded to TestFlight!")

    # Write version for CircleCI to update (same as Android)
    File.write('../.new_version', version)
  end

  desc "Promote TestFlight to Production"
  lane :release do
    app_store_connect_api_key(
      key_id: ENV['APP_STORE_CONNECT_API_KEY_KEY_ID'],
      issuer_id: ENV['APP_STORE_CONNECT_API_KEY_ISSUER_ID'],
      key_content: Base64.decode64(ENV['APP_STORE_CONNECT_API_KEY_KEY']),
      is_key_content_base64: false
    )

    deliver(
      submit_for_review: true,
      automatic_release: false,
      force: true,
      skip_metadata: true,
      skip_screenshots: true,
      precheck_include_in_app_purchases: false,
      app_identifier: "org.ilovefreegle.direct"
    )

    UI.success("✅ Submitted for App Store review!")
  end
end
```

### Step 5: Update CircleCI Config

Add iOS job and executor to `.circleci/config.yml`:

```yaml
executors:
  macos-executor:
    macos:
      xcode: 15.4.0
    resource_class: macos.m1.medium.gen1
    working_directory: ~/project

jobs:
  build-ios:
    executor: macos-executor
    steps:
      - checkout

      # Install Node.js 22 (matching Android)
      - run:
          name: Install Node.js 22
          command: |
            # Install nvm
            curl -o- https://raw.githubusercontent.com/nvm-sh/nvm/v0.39.0/install.sh | bash
            export NVM_DIR="$HOME/.nvm"
            [ -s "$NVM_DIR/nvm.sh" ] && \. "$NVM_DIR/nvm.sh"

            # Install Node 22
            nvm install 22
            nvm use 22
            nvm alias default 22

            # Add to bash profile for subsequent steps
            echo 'export NVM_DIR="$HOME/.nvm"' >> $BASH_ENV
            echo '[ -s "$NVM_DIR/nvm.sh" ] && \. "$NVM_DIR/nvm.sh"' >> $BASH_ENV

      # Verify versions
      - run:
          name: Verify Node.js and npm versions
          command: |
            node --version
            npm --version

      # Restore npm cache
      - restore_cache:
          keys:
            - npm-deps-v1-{{ checksum "package-lock.json" }}
            - npm-deps-v1-

      # Install Node.js dependencies
      - run:
          name: Install Node.js Dependencies
          command: npm ci

      - save_cache:
          key: npm-deps-v1-{{ checksum "package-lock.json" }}
          paths:
            - node_modules

      # Build Nuxt app for production
      - run:
          name: Build Nuxt App
          command: |
            export ISAPP=true
            export APP_ENV=production
            if [ -n "$SENTRY_DSN_APP_FD" ]; then
              export SENTRY_DSN="$SENTRY_DSN_APP_FD"
              echo "✅ Using app-specific Sentry DSN"
            fi
            npm run generate

      # Sync Capacitor to iOS
      - run:
          name: Sync Capacitor to iOS
          command: npx cap sync ios

      # Restore Fastlane bundle cache
      - restore_cache:
          keys:
            - bundle-v1-{{ checksum "Gemfile.lock" }}
            - bundle-v1-

      # Install Fastlane
      - run:
          name: Install Fastlane
          command: |
            bundle config set --local path 'vendor/bundle'
            bundle install

      - save_cache:
          key: bundle-v1-{{ checksum "Gemfile.lock" }}
          paths:
            - vendor/bundle

      # Setup SSH for Match git access
      - run:
          name: Setup SSH for Match
          command: |
            mkdir -p ~/.ssh
            ssh-keyscan github.com >> ~/.ssh/known_hosts

      # Build and Deploy to TestFlight
      - run:
          name: Build and Deploy to TestFlight
          command: bundle exec fastlane ios beta
          no_output_timeout: 30m

      # Store build artifacts
      - store_artifacts:
          path: build/
          destination: ios-build

workflows:
  version: 2

  deploy-android-app:
    jobs:
      - build-android:
          filters:
            branches:
              only:
                - app-ci-fd

  deploy-ios-app:
    jobs:
      - build-ios:
          filters:
            branches:
              only:
                - app-ci-fd

  auto-promote-schedule:
    triggers:
      - schedule:
          cron: "0 0 * * *"
          filters:
            branches:
              only:
                - app-ci-fd
    jobs:
      - auto-promote-production
```

### Step 6: Update Gemfile Dependencies

Ensure `Gemfile` includes necessary plugins:

```ruby
source "https://rubygems.org"

gem "fastlane"
gem "fastlane-plugin-increment_version_code"  # For Android

plugins_path = File.join(File.dirname(__FILE__), 'fastlane', 'Pluginfile')
eval_gemfile(plugins_path) if File.exist?(plugins_path)
```

## Testing Plan

### Phase 1: Local Testing (on Mac)

```bash
# Switch to app-ci-fd branch
git checkout app-ci-fd

# Set environment variables
export CURRENT_VERSION="3.2.28"
export FASTLANE_APPLE_ID="your-email@example.com"
export FASTLANE_TEAM_ID="YOUR_TEAM_ID"
export APP_STORE_CONNECT_API_KEY_KEY_ID="YOUR_KEY_ID"
export APP_STORE_CONNECT_API_KEY_ISSUER_ID="YOUR_ISSUER_ID"
export APP_STORE_CONNECT_API_KEY_KEY="$(base64 -i AuthKey_XXXXX.p8)"
export MATCH_PASSWORD="your-match-password"
export MATCH_GIT_URL="git@github.com:Freegle/certificates-private.git"

# Build Nuxt app
export ISAPP=true
export APP_ENV=production
npm run generate

# Sync Capacitor
npx cap sync ios

# Test Fastlane
bundle exec fastlane ios beta
```

### Phase 2: CircleCI Testing

1. **Push to app-ci-fd branch**
   ```bash
   git add .
   git commit -m "Add iOS build support to CircleCI"
   git push origin app-ci-fd
   ```

2. **Monitor CircleCI**
   - Watch https://app.circleci.com
   - Check iOS build logs
   - Verify TestFlight upload

3. **Verify TestFlight**
   - Check App Store Connect → TestFlight
   - Confirm build appears
   - Test on device if needed

## Cost Analysis

### CircleCI Resource Usage

**iOS Build Estimate:**
- Executor: `macos.m1.medium.gen1` (25 credits/minute)
- Build time: 20-30 minutes
- Credit usage: 500-750 credits per build

**Current Android:**
- ~200 credits per build

**Monthly Projection (4 releases/month):**
- iOS: 4 × 625 (avg) = 2,500 credits
- Android: 4 × 200 = 800 credits
- **Total: 3,300 credits/month**

**CircleCI Free Tier:** 30,000 credits/month ✅ (plenty of headroom)

## Rollback Plan

If iOS builds fail:

1. **Disable iOS workflow temporarily:**
   ```yaml
   # Comment out in .circleci/config.yml
   # deploy-ios-app:
   #   jobs:
   #     - build-ios:
   ```

2. **Continue with Android-only releases**

3. **Debug locally on Mac**

4. **Re-enable once fixed**

## Success Criteria

- [ ] App Store Connect API key configured
- [ ] Fastlane Match certificates set up
- [ ] All CircleCI environment variables added
- [ ] Matchfile created
- [ ] Appfile updated with iOS config
- [ ] iOS lane added to Fastfile
- [ ] CircleCI config updated with macOS executor
- [ ] Gemfile includes necessary dependencies
- [ ] Local iOS build succeeds on Mac
- [ ] CircleCI iOS build succeeds
- [ ] App appears in TestFlight
- [ ] Version auto-increments correctly
- [ ] Documentation updated

## Timeline

**Day 1:** Prerequisites & Setup (4-6 hours)
- Create App Store Connect API key
- Set up Fastlane Match
- Configure CircleCI environment variables

**Day 2:** Code Changes (3-4 hours)
- Update Fastfile, Appfile, Matchfile
- Update CircleCI config
- Test locally on Mac

**Day 3:** CircleCI Testing & Debugging (2-4 hours)
- Push to CircleCI
- Debug any issues
- Verify TestFlight upload

**Total: 9-14 hours over 3 days**

## Security Considerations

✅ **Never commit:**
- `.p8` files
- `google-services.json`
- `GoogleService-Info.plist`
- Match password
- API keys

✅ **Store in CircleCI:**
- All credentials as environment variables
- Mark as sensitive/hidden
- Base64 encode file contents

✅ **Use Match for certificates:**
- Stored in private Git repo
- Encrypted with MATCH_PASSWORD
- Rotated annually

## Next Steps After iOS

1. **Unified version management:** Consider syncing Android & iOS versions
2. **Auto-promotion:** Add iOS auto-promote lane (similar to Android)
3. **Notifications:** Email alerts for successful releases
4. **ModTools app:** Repeat process for `iznik-nuxt3-modtools`

## References

- [CircleCI iOS Deploy Guide](https://circleci.com/docs/guides/deploy/deploy-ios-applications/)
- [Fastlane Match Documentation](https://docs.fastlane.tools/actions/match/)
- [Capacitor iOS Guide](https://capacitorjs.com/docs/ios)
- [App Store Connect API](https://developer.apple.com/documentation/appstoreconnectapi)
