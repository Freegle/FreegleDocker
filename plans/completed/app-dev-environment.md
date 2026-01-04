# Freegle Dev App - Live Reload Development Environment

## Overview

A separate "Freegle Dev" app that connects to a local development server for rapid iteration without rebuilding APKs.

## Architecture

```
┌─────────────────────────────────────────────────────────┐
│  Phone                                                  │
│  ┌───────────────────┐  ┌───────────────────┐          │
│  │ Freegle           │  │ Freegle Dev       │          │
│  │ (Production)      │  │ (Development)     │          │
│  │                   │  │                   │          │
│  │ Bundled assets    │  │ Loads from        │          │
│  │ Works offline     │  │ dev server        │          │
│  └───────────────────┘  └─────────┬─────────┘          │
└─────────────────────────────────────┼───────────────────┘
                                      │ HTTP
                                      ▼
┌─────────────────────────────────────────────────────────┐
│  Developer Machine (Windows + WSL2 + Docker)            │
│                                                         │
│  ┌─────────────────────────────────────────────────┐    │
│  │ Docker: freegle-dev container                   │    │
│  │ npm run dev (port 3002)                         │    │
│  │                                                 │    │
│  │ Hot reload on file changes                      │    │
│  └─────────────────────────────────────────────────┘    │
│                                                         │
│  ┌─────────────────────────────────────────────────┐    │
│  │ Docker: status container                        │    │
│  │ Displays QR code with dev server URL            │    │
│  └─────────────────────────────────────────────────┘    │
└─────────────────────────────────────────────────────────┘
```

## App Comparison

| Aspect | Freegle (Production) | Freegle Dev |
|--------|---------------------|-------------|
| **Package ID** | `org.ilovefreegle.direct` | `org.ilovefreegle.dev` |
| **App Name** | Freegle | Freegle Dev |
| **Icon** | Normal | Orange tint or "DEV" badge |
| **Assets** | Bundled | From dev server |
| **Startup** | Normal flow | QR/URL connect screen |
| **Play Store** | Published | Never published |
| **Coexistence** | ✓ Both can be installed | ✓ |

## Connection Flow

### First Launch / No Saved Server

```
┌─────────────────────────────────────────┐
│                                         │
│         🔧 Freegle Dev                  │
│                                         │
│    Connect to development server        │
│                                         │
│         ┌─────────────┐                 │
│         │             │                 │
│         │   📷 SCAN   │                 │
│         │     QR      │                 │
│         └─────────────┘                 │
│                                         │
│    Scan QR code from status page        │
│                                         │
│    ─────────── or ───────────           │
│                                         │
│    ┌─────────────────────────────┐      │
│    │ http://                     │      │
│    └─────────────────────────────┘      │
│    Enter server URL manually            │
│                                         │
└─────────────────────────────────────────┘
```

### Returning User (Has Saved Server)

```
┌─────────────────────────────────────────┐
│                                         │
│         🔧 Freegle Dev                  │
│                                         │
│    Last server: 192.168.1.50:3002       │
│                                         │
│    ┌─────────────────────────────┐      │
│    │      [ Reconnect ]          │      │
│    └─────────────────────────────┘      │
│                                         │
│    ┌─────────────────────────────┐      │
│    │   [ Scan Different QR ]     │      │
│    └─────────────────────────────┘      │
│                                         │
│    Checking connection...               │
│                                         │
└─────────────────────────────────────────┘
```

### Connection Successful

App loads normally from dev server. All Capacitor plugins (camera, etc.) work because native code is on device.

### Connection Failed

```
┌─────────────────────────────────────────┐
│                                         │
│    ❌ Cannot reach dev server           │
│                                         │
│    http://192.168.1.50:3002             │
│                                         │
│    Possible issues:                     │
│    • Dev server not running             │
│    • Phone not on same WiFi             │
│    • Firewall blocking port 3002        │
│                                         │
│    ┌─────────────────────────────┐      │
│    │      [ Try Again ]          │      │
│    └─────────────────────────────┘      │
│                                         │
│    ┌─────────────────────────────┐      │
│    │   [ Scan Different QR ]     │      │
│    └─────────────────────────────┘      │
│                                         │
└─────────────────────────────────────────┘
```

## Implementation

### 1. Status Container: QR Code Page

**File:** `status/api/dev-qr.js` or dedicated page

```javascript
// Detect host's LAN IP
function getLanIp() {
  const interfaces = require('os').networkInterfaces()
  for (const name of Object.keys(interfaces)) {
    for (const iface of interfaces[name]) {
      if (iface.family === 'IPv4' && !iface.internal) {
        // Prefer 192.168.x.x or 10.x.x.x
        if (iface.address.startsWith('192.168.') ||
            iface.address.startsWith('10.')) {
          return iface.address
        }
      }
    }
  }
  return null
}

// Generate QR code data
const devServerUrl = `http://${getLanIp()}:3002`
```

**UI:** Display QR code encoding the URL, plus text for manual entry.

### 2. Freegle Dev App: Connect Screen

**File:** `components/DevConnectScreen.vue`

Features:
- QR code scanner (use `@aspect/aspectra` or similar Capacitor plugin)
- Manual URL input field
- "Reconnect" button if saved URL exists
- Connection test before proceeding
- Store URL in `@capacitor/preferences`

### 3. Capacitor Config for Dev App

**File:** `capacitor.config.dev.ts`

```typescript
import { CapacitorConfig } from '@capacitor/cli'

const config: CapacitorConfig = {
  appId: 'org.ilovefreegle.dev',
  appName: 'Freegle Dev',
  webDir: 'dist',  // Minimal, connect screen only

  // Server URL set dynamically from saved preference
  // Not hardcoded here

  plugins: {
    // Same plugins as production
  },

  android: {
    // Allow cleartext HTTP for dev servers
    allowMixedContent: true,
  },
}

export default config
```

### 4. Build Process

**CircleCI Job:** `build-dev-app`

```yaml
build-dev-app:
  executor: android-executor
  steps:
    - checkout
    - run:
        name: Build dev app connect screen
        command: |
          # Build minimal app with just connect screen
          npm ci
          npm run build:dev-connect
    - run:
        name: Sync Capacitor with dev config
        command: npx cap sync android --config capacitor.config.dev.ts
    - run:
        name: Build debug APK
        command: |
          cd android
          ./gradlew assembleDebug
    - store_artifacts:
        path: android/app/build/outputs/apk/debug/app-debug.apk
        destination: freegle-dev.apk
```

### 5. Minimal Dev App Bundle

The dev app only needs:
- Connect screen component
- QR scanner
- URL storage
- WebView that loads from dev server

Everything else comes from the dev server at runtime.

## File Structure

```
iznik-nuxt3/
├── capacitor.config.ts          # Production config
├── capacitor.config.dev.ts      # Dev app config (NEW)
├── components/
│   └── DevConnectScreen.vue     # Connect screen (NEW)
├── pages/
│   └── _dev-connect.vue         # Dev app entry point (NEW)
└── scripts/
    └── build-dev-connect.js     # Build script for minimal bundle (NEW)

FreegleDockerWSL/
├── status/
│   ├── api/
│   │   └── dev-qr.js            # QR code API endpoint (NEW)
│   └── pages/
│       └── dev-connect.vue      # QR display page (NEW)
```

## Usage Workflow

### Developer Setup (One Time)

1. Build dev app via CircleCI: trigger `build-dev-app` job
2. Download `freegle-dev.apk` from artifacts
3. Install on Android device (enable "Install from unknown sources")

### Daily Development

1. Start Docker: `docker-compose up -d`
2. Open status page: `http://status.localhost/dev-connect`
3. Open Freegle Dev app on phone
4. Scan QR code (or tap Reconnect if previously connected)
5. App connects to dev server
6. Make code changes → app hot reloads instantly

### When Capacitor Plugins Change

Rebuild the dev app APK (plugins are native code, not hot-reloadable).

## iOS Support

Same approach works for iOS:
- Different Bundle ID: `org.ilovefreegle.dev`
- Distribute via TestFlight or ad-hoc provisioning
- Connect screen works identically

## Security Considerations

- Dev app only works on local network (HTTP, not HTTPS)
- Never publish to Play Store / App Store
- QR code only shown on status page (localhost access)
- No sensitive data in dev app bundle

## Future Enhancements

- [ ] Network scan fallback if QR scan fails
- [ ] Multiple saved servers (home, office, etc.)
- [ ] Auto-reconnect on app resume
- [ ] Connection status indicator in app
- [ ] mDNS discovery if network topology allows
