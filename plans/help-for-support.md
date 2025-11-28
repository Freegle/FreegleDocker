# Help for Support: Enhanced Diagnostic Information Collection

## Problem Statement

A common problem for Mods/Support is that users don't provide screenshots or enough information when reporting issues. This makes debugging difficult and creates back-and-forth communication that delays resolution.

## Current State

Freegle currently uses:
- **Sentry** for error tracking (already integrated via `plugins/sentry.client.ts`)
- **SomethingWentWrong component** that displays error messages with stack traces
- **Help page** with device info display (mobile version, deviceuserinfo) and "Copy app and device info" button
- **Debug logs** available in the mobile app via DebugLogsModal

The current "Something went wrong" message tells users to "take a screenshot and contact Support" - this could be improved.

## Recommended Solutions

### Option 1: Sentry Session Replay (Recommended - Already Have Sentry)

**Why this is the best option for Freegle:**
- Already using Sentry for error tracking
- No new vendor to manage
- Integrated with existing error tracking
- Can use "error-only" mode to minimize overhead

**Performance Impact:**
- ~36-50KB gzipped additional bundle size
- CPU overhead: ~10-20% increase during heavy interactions (imperceptible to most users)
- Network: compressed data, ~0.017-0.07 seconds added to interactions
- Has built-in safeguards: stops recording if >750 DOM mutations, limits at 10,000+ mutations

**Configuration for Error-Only Recording:**
```typescript
// In sentry.client.ts
import { replayIntegration } from '@sentry/vue'

Sentry.init({
  // ... existing config ...
  replaysSessionSampleRate: 0,      // Don't record normal sessions
  replaysOnErrorSampleRate: 1.0,    // Record 100% of sessions with errors
  integrations: [
    // ... existing integrations ...
    replayIntegration({
      maskAllText: true,            // Privacy: mask all text by default
      blockAllMedia: true,          // Privacy: block images/video
      networkDetailAllowUrls: [     // Only capture Freegle API calls
        /ilovefreegle\.org/,
      ],
    }),
  ],
})
```

**How it works:**
- Buffers up to 1 minute of events prior to an error
- When an error occurs, the replay is uploaded to Sentry
- Links directly to the error in Sentry dashboard
- Volunteers can see exactly what the user was doing when the error occurred

**Privacy Considerations:**
- Default masking obscures all text with asterisks
- Images and media blocked by default
- Can add CSS classes `sentry-block`, `sentry-ignore`, `sentry-mask` to specific elements
- Network requests show URL/method/status but body content is masked unless explicitly allowed
- GDPR: User consent may be required - integrate with CookieYes

**Cost:**
- Sentry Team plan: $26/month includes 500 replays
- Pay-as-you-go for additional replays
- Error-only mode significantly reduces replay volume

**Implementation Effort:** Low - just configuration changes

---

### Option 2: Enhanced Device Info Collection (Lightweight Alternative)

For cases where session replay is overkill, collect comprehensive device/browser info automatically.

**What to collect:**
- Browser name and version
- Operating system and version
- Screen resolution
- Device type (mobile/tablet/desktop)
- Network connection type
- JavaScript errors from console
- Recent API calls and their status
- Current URL/route
- User actions (last 10-20 clicks/navigations)
- Memory usage
- Page performance metrics (LCP, FID, CLS)

**Libraries:**
- **UAParser.js** (~15KB): Parse user agent for browser/OS/device detection
- **Platform.js** (~3KB): Similar but more lightweight
- Custom collection script for console logs and performance

**Implementation:**
```javascript
// composables/useDeviceInfo.js
export function useDeviceInfo() {
  return {
    browser: navigator.userAgent,
    screenSize: `${window.screen.width}x${window.screen.height}`,
    viewport: `${window.innerWidth}x${window.innerHeight}`,
    platform: navigator.platform,
    language: navigator.language,
    cookiesEnabled: navigator.cookieEnabled,
    online: navigator.onLine,
    deviceMemory: navigator.deviceMemory,
    hardwareConcurrency: navigator.hardwareConcurrency,
    connection: navigator.connection?.effectiveType,
    timestamp: new Date().toISOString(),
    url: window.location.href,
  }
}
```

**How to share with Support:**
1. Generate a unique "debug token" when error occurs
2. Upload device info + recent console logs to server
3. Show user the token and include in email/chat
4. Volunteers can look up the token to see full context

**Performance Impact:** Minimal - just reading browser APIs

**Implementation Effort:** Medium

---

### Option 3: Third-Party Feedback Tools

**Jam.dev** (Free browser extension)
- One-click bug reports with automatic capture of:
  - Screenshot or screen recording
  - Console logs
  - Network requests
  - Device info
- Integrates with GitHub Issues, Jira, Linear
- No installation needed on website - users install extension
- **Limitation:** Requires users to install extension

**BugHerd** ($41/month)
- Point-and-click feedback on live site
- Automatic screenshot + technical metadata
- Screen recording capability
- Kanban board for task management
- **Limitation:** Cost, requires installation on site

**Usersnap** ($52/month starter)
- Annotated screenshots
- Console error capture
- Environment data (URL, browser, screen size)
- **Limitation:** Cost

**Microsoft Clarity** (Free)
- Session recordings and heatmaps
- Built-in privacy features (data masking, IP anonymization)
- GDPR-compliant with proper consent
- Unlimited recordings
- **Limitation:** Data stored in US, requires consent banner integration

---

### Option 4: Custom Lightweight Session Recording (rrweb)

If Sentry replay pricing becomes an issue, build custom solution with rrweb.

**What is rrweb?**
- Open-source session replay library (~50KB)
- Used by Sentry, PostHog, Highlight internally
- Records DOM mutations efficiently (incremental snapshots)

**Performance:**
- Initial snapshot: slight CPU spike
- Ongoing: minimal - uses MutationObserver API
- Throttles high-frequency events automatically

**Implementation Complexity:** High
- Need to build data pipeline
- Need storage backend
- Need replay viewer UI
- Need privacy controls

**Recommendation:** Only if Sentry costs become prohibitive

---

## Recommended Implementation Plan

### Phase 1: Sentry Session Replay (Error-Only Mode)

1. **Enable Replay Integration**
   - Add `replayIntegration` to existing Sentry config
   - Set `replaysSessionSampleRate: 0` (no normal session recording)
   - Set `replaysOnErrorSampleRate: 1.0` (capture all error sessions)

2. **Privacy Configuration**
   - Enable `maskAllText: true`
   - Enable `blockAllMedia: true`
   - Add `sentry-mask` class to sensitive form fields (passwords, etc.)

3. **Update CookieYes Integration**
   - May need consent for session replay
   - Only enable replay after consent

4. **Update Error Display**
   - Modify `SomethingWentWrong.vue` to show:
     - "We've automatically captured diagnostic information"
     - Link to the Sentry issue (if available)
     - Still allow manual reporting for non-error issues

### Phase 2: Enhanced Device Info for Non-Error Cases

1. **Create diagnostic info composable**
   - Collect device/browser info
   - Store recent console messages
   - Track recent user actions

2. **Integrate with Help Page**
   - Enhance existing "Copy app and device info" button
   - Include more comprehensive data
   - Option to "Generate Support Report"

3. **Create server-side storage**
   - API endpoint to store diagnostic reports
   - Generate shareable token/URL
   - Auto-expire after 30 days

4. **Integration Points**
   - "Something went wrong" component
   - Help page contact form
   - Support email template

### Phase 3: ModTools Integration

1. **Display in Chat**
   - When user shares diagnostic token, auto-fetch and display info
   - Show device/browser, error details, replay link

2. **Support Email Enhancement**
   - Include diagnostic link in support emails
   - Auto-attach device info when available

---

## Cost Comparison

| Solution | Monthly Cost | Performance Impact | Implementation Effort |
|----------|-------------|-------------------|----------------------|
| Sentry Replay (error-only) | ~$0 (within 500 replays) | Low (~36KB, 10-20% CPU on error) | Low |
| Enhanced Device Info | $0 | Minimal | Medium |
| Microsoft Clarity | $0 | Medium | Low |
| Jam.dev | $0 (extension) | None | None (user install) |
| BugHerd | $41/month | Low | Low |
| Usersnap | $52/month+ | Low | Low |
| Custom rrweb | $0 + hosting | Low-Medium | High |

---

## Recommendation

**Start with Sentry Session Replay in error-only mode:**

1. Lowest implementation effort (already using Sentry)
2. No additional cost if within 500 replays/month (likely for error-only)
3. Best debugging value - see exactly what happened
4. Privacy-safe with default masking
5. Can expand to broader recording if needed

**Add Enhanced Device Info for non-error support requests:**
- Improves Help page contact workflow
- No external dependencies
- Lightweight overhead

This combination gives volunteers:
- **For errors:** Full session replay showing exactly what went wrong
- **For general support:** Comprehensive device/browser info and recent activity

---

## Technical References

### Sentry Replay Docs
- Setup: https://docs.sentry.io/platforms/javascript/guides/vue/session-replay/
- Nuxt-specific: https://docs.sentry.io/platforms/javascript/guides/nuxt/session-replay/
- Privacy: https://docs.sentry.io/platforms/javascript/guides/nuxt/session-replay/privacy/
- Performance: https://docs.sentry.io/product/session-replay/performance-overhead/

### rrweb (underlying library)
- GitHub: https://github.com/rrweb-io/rrweb
- Docs: https://www.rrweb.io/

### Alternative Tools
- Microsoft Clarity: https://clarity.microsoft.com/
- Jam.dev: https://jam.dev/
- OpenReplay (self-hosted): https://openreplay.com/
- Highlight.io: https://www.highlight.io/
- PostHog: https://posthog.com/

### Device Detection Libraries
- UAParser.js: https://uaparser.dev/
- Platform.js: https://github.com/bestiejs/platform.js/
