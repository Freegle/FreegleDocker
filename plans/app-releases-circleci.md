# App Release Automation - CircleCI Implementation

This document covers the CircleCI-specific implementation for automated app releases. For general information and Fastlane configuration, see [app-releases.md](app-releases.md).

## CircleCI Environment Variables

Add the following to CircleCI project settings (both Freegle and ModTools apps):

### Android Variables
- `GOOGLE_PLAY_JSON_KEY`: Base64 encoded JSON key file
  ```bash
  base64 -i google-play-api-key.json | pbcopy
  ```

### iOS Variables
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

---

## CircleCI Config for Freegle App (Capacitor)

Create `.circleci/config-apps.yml` in iznik-nuxt3:

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

---

## CircleCI Config for ModTools App (Cordova)

Create `.circleci/config.yml` in iznik-nuxt3-modtools:

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

## Integration with Main CircleCI Workflow

Update the main FreegleDockerWSL CircleCI config to trigger app builds after successful production deployment:

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

---

## Cost Considerations

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

**CircleCI Plans:**
- Free: 30,000 credits/month
- Performance: $15/month + additional credits

---

## Testing the Setup

1. **Create test branch**
   ```bash
   git checkout -b test-circleci-deployment
   echo "# Test" >> README.md
   git add README.md
   git commit -m "Test: CircleCI deployment"
   git push origin test-circleci-deployment
   ```

2. **Monitor CircleCI**
   - Go to https://app.circleci.com
   - Watch build progress
   - Check for errors

3. **Verify uploads**
   - Check Google Play Console for Internal Track upload
   - Check TestFlight for iOS build

---

## Troubleshooting

### Build Timeout
- Increase timeout in CircleCI config
- Optimize build caching
- Consider larger resource class

### macOS Runner Issues
- Check Xcode version compatibility
- Verify certificate access
- Check disk space issues

### Credential Errors
- Verify all environment variables are set
- Check base64 encoding is correct
- Ensure API keys haven't expired

---

## References

- CircleCI Android Deploy: https://circleci.com/docs/guides/deploy/deploy-android-applications/
- CircleCI iOS Deploy: https://circleci.com/docs/guides/deploy/deploy-ios-applications/
- CircleCI macOS Executors: https://circleci.com/docs/using-macos/
