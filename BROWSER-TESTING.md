# Browser Testing with Chrome DevTools MCP

This document describes how to use the Chrome DevTools MCP server to visually verify design changes in the Freegle application.

## Prerequisites

### 1. Chrome with Remote Debugging

Start Chrome with remote debugging enabled on Windows:

```cmd
"C:\Program Files\Google\Chrome\Application\chrome.exe" --remote-debugging-port=9222
```

Or on Linux:
```bash
google-chrome --remote-debugging-port=9222
```

### 2. MCP Server Configuration

The Chrome DevTools MCP is configured in `~/.claude.json` under the FreegleDockerWSL project:

```json
"chrome-devtools": {
  "type": "stdio",
  "command": "npx",
  "args": [
    "-y",
    "chrome-devtools-mcp@latest",
    "--browserUrl",
    "http://127.0.0.1:9222"
  ],
  "env": {}
}
```

After adding/modifying the config, restart Claude Code for changes to take effect.

### 3. Development Container Running

Ensure the freegle-dev-live container is running:
```bash
docker-compose up -d freegle-dev-live
```

## Target URL

Use **freegle-dev-live** for visual testing as it:
- Connects to PRODUCTION APIs (real data)
- Runs in development mode (hot reload)
- Available at: `http://freegle-dev-live.localhost/`

## Test User Credentials

Test credentials are stored in `.env` (gitignored):
- `TEST_USER_EMAIL` - Test account email address
- `TEST_USER_PASSWORD` - Test account password

These are used for testing logged-in features. The `.env` file is not committed to git.

## Workflow for Design Changes

### Step 1: Make the Code Change

Edit the Vue/CSS/JS files in `iznik-nuxt3/` as needed.

### Step 2: Navigate to the Page

```
mcp__chrome-devtools__navigate_page
  type: "url"
  url: "http://freegle-dev-live.localhost/"
```

Or list existing pages and select one:
```
mcp__chrome-devtools__list_pages
mcp__chrome-devtools__select_page
  pageIdx: 0
```

### Step 3: Take a Snapshot (Accessibility Tree)

Get a text representation of the page structure:
```
mcp__chrome-devtools__take_snapshot
```

This returns the accessibility tree with unique IDs (uid) for each element.

### Step 4: Take a Screenshot

Capture a visual screenshot:
```
mcp__chrome-devtools__take_screenshot
```

Or capture full page:
```
mcp__chrome-devtools__take_screenshot
  fullPage: true
```

Or capture a specific element by uid:
```
mcp__chrome-devtools__take_screenshot
  uid: "1_23"
```

### Step 5: Interact with the Page

Click an element:
```
mcp__chrome-devtools__click
  uid: "1_3"
```

Fill a form field:
```
mcp__chrome-devtools__fill
  uid: "1_117"
  value: "test@test.com"
```

### Step 6: Login Flow (if needed)

1. Navigate to the site
2. Click "Log in or Join" link
3. Click "Log in" in the dialog
4. Fill email field with test credentials
5. Fill password field
6. Click login button

## Useful MCP Tools Reference

| Tool | Purpose |
|------|---------|
| `list_pages` | Show all open browser tabs |
| `select_page` | Switch to a specific tab |
| `navigate_page` | Go to URL or back/forward/reload |
| `take_snapshot` | Get accessibility tree (text representation) |
| `take_screenshot` | Capture visual screenshot |
| `click` | Click an element by uid |
| `fill` | Type into input/textarea |
| `hover` | Hover over element |
| `press_key` | Press keyboard keys |
| `wait_for` | Wait for text to appear |

## Troubleshooting

### "Not supported" error on new_page
The connected browser may not support creating new tabs. Use `navigate_page` on an existing tab instead.

### MCP not connecting
1. Verify Chrome is running with `--remote-debugging-port=9222`
2. Test connection: `curl http://localhost:9222/json/version`
3. Restart Claude Code after config changes

### Page not loading
1. Check container is running: `docker-compose ps`
2. Verify URL resolves: `curl -I http://freegle-dev-live.localhost/`

### resize_page not working
The `resize_page` tool and JavaScript `window.resizeTo()` don't work on most browser configurations. To control viewport size:
1. Manually resize the Chrome window, or
2. Use Chrome DevTools Device Toolbar: F12 → click device icon (top-left) → select device or enter custom dimensions
3. **Workaround**: Inject width constraints via DOM to force rendering at a specific width for screenshots:
```
mcp__chrome-devtools__evaluate_script
  function: "() => { document.body.style.minWidth = '400px'; document.body.style.maxWidth = '400px'; document.body.style.width = '400px'; }"
```

## Code Conventions for Vue Components

When making design changes, follow these conventions:

### Icons
Use `v-icon` with Font Awesome icons, NOT Bootstrap icons:
```vue
<v-icon icon="info-circle" />
<v-icon icon="shield-alt" />
<v-icon icon="exclamation-triangle" />
```

### SCSS Color Variables
Import from `assets/css/_color-vars.scss`:
```scss
@import 'assets/css/_color-vars.scss';
```

Common variables (note British spelling for some):
- `$colour-success-fg` - Green foreground
- `$color-green--darker` - Darker green
- `$color-gray--light` - Light gray
- `$danger` - Bootstrap danger red (from Bootstrap variables)
- `$info` - Bootstrap info blue

### SCSS Comments
Never use `//` comments in SCSS - use `/* */` instead (Vite compilation errors).

### Link Text Whitespace
Never add trailing/leading whitespace inside link tags - it renders as visible space:
```vue
<!-- BAD -->
<ExternalLink href="...">
  Police </ExternalLink>

<!-- GOOD -->
<ExternalLink href="...">Police</ExternalLink>
```

### Duplicate Titles
Check for redundant titles - pages often have:
- Title in the navbar/header (from layout)
- Title in the page content (h1)

If both show the same text, remove the h1 from page content to avoid duplication.

### Text Contrast
Check text contrast meets WCAG accessibility standards:
- **4.5:1** minimum for normal text
- **3:1** minimum for large text (18pt+ or 14pt bold)

Common contrast problems to avoid:
- White text on light green backgrounds (#61ae24 fails)
- Green text on green backgrounds
- Using `opacity` on text (reduces contrast)
- Light gray text on white backgrounds

Use a contrast checker tool or browser DevTools accessibility panel to verify.

### House Style
- Always put full stops at the end of sentences
- Use consistent capitalization

### After Changes
Always run eslint on modified files:
```bash
npx eslint --fix pages/your-file.vue
```
