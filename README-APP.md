# Freegle Mobile App

Native mobile app for Freegle using Kotlin Multiplatform (KMP) shared logic with platform-native UI.

## Architecture

- **Shared module** (`freegle-app/shared/`): Kotlin Multiplatform code containing data models, API client (Ktor), repositories, and business logic. Consumed by both Android and iOS apps.
- **Android app** (`freegle-app/androidApp/`): Jetpack Compose UI with Material 3 theming.
- **iOS app** (`freegle-app/iosApp/`): SwiftUI (deferred - not yet implemented).

## Building

### Prerequisites

- Java 17+ (OpenJDK recommended)
- Android SDK with platform 35 and build-tools 35.0.0

### Build Debug APK

```bash
cd freegle-app
export ANDROID_HOME=/path/to/android-sdk
./gradlew assembleDebug
```

The APK will be at `androidApp/build/outputs/apk/debug/androidApp-debug.apk`.

### API Configuration

The app connects to the Freegle V2 API. The base URL defaults to `http://10.0.2.2:18193/api` (Android emulator accessing host machine on external port 18193, mapped from the apiv2 container's internal port 8192).

To change the API URL, edit `androidApp/build.gradle.kts`:
```kotlin
buildConfigField("String", "API_BASE_URL", "\"http://your-api-host:18193/api\"")
```

### V2 API Response Format

The Go V2 API returns **bare JSON arrays and objects** (not wrapped in `{ret: 0, status: "", ...}` envelopes like the V1 PHP API). For example, `GET /api/message/inbounds` returns `[{...}, {...}]` directly, and `GET /api/user/{id}` returns `{...}` directly.

## Features

### UI Design
- **Person-centred feed**: Items grouped by giver (name, avatar, items) instead of flat list
- **Camera-first Give flow**: Progressive disclosure one question at a time with slide transitions
- **Story circles chat list**: Horizontal avatar carousel for active chats with gradient activity rings
- **Immersive post detail**: Full-screen photos with BottomSheetScaffold, parallax image paging
- **Impact dashboard profile**: Animated counters, achievements/milestones, environmental impact stats
- **Pulsing Give button**: Custom Freegle heart-arrows motif in nav bar with infinite pulse animation
- **Shimmer skeleton loading**: Animated placeholder cards while feed loads (Facebook Marketplace pattern)
- **Quick reply suggestion chips**: Pre-composed messages on post detail (OfferUp/Olio pattern)

### Core Functionality
- **5-tab navigation**: Home, Explore, Give, Chat, Me
- **Search**: Expandable search bar with debounced live results and loading indicator
- **In-chat item context**: Persistent header showing which item is being discussed
- **Message sending**: User types their own message to express interest (no auto-send)
- **Quick-reply chips**: Suggested messages in new chats and on post detail
- **Error handling**: Visible error states with retry actions on all screens (Home, Chat, ChatList)
- **Pull-to-refresh**: On home feed and chat list
- **Location**: GPS auto-detection with manual postcode fallback, saved to DataStore
- **Onboarding**: 4-page intro with Freegle community photos + feature tour overlay
- **WCAG AA colours**: Green darkened to #008040 (5:1 contrast with white), blue for Wanted items
- **Material 3**: Freegle brand colour scheme, light and dark theme support

### Test Data
Run `create-test-data.php` inside the apiv1 container to populate 20 realistic Edinburgh-area items:
```bash
docker exec freegle-apiv1 php /var/www/iznik/install/create-mobile-test-data.php
```

## Testing with Android Emulator

### Setup

```bash
# Install emulator and system image
ANDROID_HOME=/home/edward/android-sdk
$ANDROID_HOME/cmdline-tools/latest/bin/sdkmanager "emulator" "system-images;android-35;google_apis;x86_64"

# Ensure KVM access (for hardware acceleration)
sudo gpasswd -a $USER kvm

# Create AVD
echo "no" | $ANDROID_HOME/cmdline-tools/latest/bin/avdmanager create avd \
  -n freegle_test -k "system-images;android-35;google_apis;x86_64" -d pixel_6 --force

# Launch emulator (headless)
sg kvm -c "$ANDROID_HOME/emulator/emulator -avd freegle_test -no-window -no-audio -gpu swiftshader_indirect"

# Install APK
$ANDROID_HOME/platform-tools/adb install androidApp/build/outputs/apk/debug/androidApp-debug.apk

# Launch app
$ANDROID_HOME/platform-tools/adb shell am start -n org.freegle.app/.android.MainActivity

# Take screenshot
$ANDROID_HOME/platform-tools/adb exec-out screencap -p > screenshot.png
```

## Design Decisions

See `plans/active/freegle-mobile-app.md` for the full design document including:
- V2 API endpoint mapping
- UX research findings from competitor apps
- Technology stack decision rationale (KMP + native UI)
- Screen architecture and user flows
