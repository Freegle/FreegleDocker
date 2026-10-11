// adb-driven access to the emulator the `app` Playwright project runs against: shell
// commands, the UI hierarchy (uiautomator dump), taps, the notification shade, fake FCM
// messages, deep links. Playwright drives the WebView over CDP; this covers everything
// outside it. Every wait polls an observable condition with a deadline from config.js.
const { execFile } = require('child_process')
const { timeouts } = require('../config')

const ADB = process.env.APP_E2E_ADB || 'adb'
const SERIAL = process.env.APP_E2E_SERIAL
const PACKAGE = process.env.APP_E2E_PACKAGE || 'org.ilovefreegle.direct'
const ACTIVITY = process.env.APP_E2E_ACTIVITY || '.MainActivity'

const delay = (ms) => new Promise((resolve) => setTimeout(resolve, ms))

/** Quote a string for the device's /system/bin/sh. */
function shQuote(s) {
  return `'${String(s).replace(/'/g, `'\\''`)}'`
}

function adb(args, { timeoutMs = 60000, binary = false } = {}) {
  if (!SERIAL) {
    throw new Error('APP_E2E_SERIAL is not set: the app project needs a device')
  }
  return new Promise((resolve, reject) => {
    execFile(
      ADB,
      ['-s', SERIAL, ...args],
      {
        timeout: timeoutMs,
        maxBuffer: 64 * 1024 * 1024,
        encoding: binary ? 'buffer' : 'utf8',
      },
      (err, stdout, stderr) => {
        if (err) {
          reject(
            new Error(
              `adb ${args.join(' ')} failed: ${String(stderr || err.message).trim()}`
            )
          )
        } else {
          resolve(stdout)
        }
      }
    )
  })
}

/** Runs one command in the device shell; the command is passed as a single string. */
async function shell(cmd, opts) {
  return String(await adb(['shell', cmd], opts)).replace(/\r\n/g, '\n')
}

/** Polls fn until it returns a truthy value; throws with `what` on timeout. */
async function poll(fn, { timeoutMs, intervalMs = 500, what = 'condition' }) {
  const deadline = Date.now() + timeoutMs
  let lastErr
  for (;;) {
    try {
      const v = await fn()
      if (v) return v
    } catch (e) {
      lastErr = e
    }
    if (Date.now() >= deadline) {
      const tail = lastErr ? ` (last error: ${lastErr.message})` : ''
      throw new Error(
        `timed out after ${timeoutMs}ms waiting for ${what}${tail}`
      )
    }
    await delay(intervalMs)
  }
}

// --- UI hierarchy -------------------------------------------------------------------

/**
 * The current screen as a flat list of nodes from `uiautomator dump`:
 * { text, resourceId, desc, className, clickable, bounds: {x1,y1,x2,y2} }.
 */
async function uiNodes() {
  const xml = await poll(
    async () => {
      const out = await shell('uiautomator dump /sdcard/app-e2e-window.xml', {
        timeoutMs: 30000,
      })
      if (!/dumped to/i.test(out)) return null
      const dump = String(
        await adb(['exec-out', 'cat', '/sdcard/app-e2e-window.xml'])
      )
      return dump.includes('<hierarchy') ? dump : null
    },
    {
      timeoutMs: timeouts.ui.appearance,
      intervalMs: 1000,
      what: 'a uiautomator dump',
    }
  )
  const nodes = []
  const attr = (tag, name) => {
    const m = new RegExp(`\\s${name}="([^"]*)"`).exec(tag)
    return m ? m[1].replace(/&quot;/g, '"').replace(/&amp;/g, '&') : ''
  }
  for (const tag of xml.match(/<node\b[^>]*>/g) || []) {
    const b = /\[(\d+),(\d+)\]\[(\d+),(\d+)\]/.exec(attr(tag, 'bounds'))
    nodes.push({
      text: attr(tag, 'text'),
      resourceId: attr(tag, 'resource-id'),
      desc: attr(tag, 'content-desc'),
      className: attr(tag, 'class'),
      clickable: attr(tag, 'clickable') === 'true',
      bounds: b ? { x1: +b[1], y1: +b[2], x2: +b[3], y2: +b[4] } : null,
    })
  }
  return nodes
}

function matches(node, sel) {
  if (sel.text !== undefined) {
    if (
      sel.text instanceof RegExp
        ? !sel.text.test(node.text)
        : node.text !== sel.text
    ) {
      return false
    }
  }
  if (sel.textContains !== undefined && !node.text.includes(sel.textContains))
    return false
  if (sel.resourceId !== undefined && node.resourceId !== sel.resourceId)
    return false
  if (sel.desc !== undefined && node.desc !== sel.desc) return false
  return true
}

async function findNode(sel) {
  return (await uiNodes()).find((n) => matches(n, sel) && n.bounds) || null
}

async function waitForNode(sel, { timeoutMs = timeouts.ui.appearance } = {}) {
  return poll(() => findNode(sel), {
    timeoutMs,
    intervalMs: 750,
    what: `a UI node matching ${JSON.stringify(sel)}`,
  })
}

async function tapNode(node) {
  const { x1, y1, x2, y2 } = node.bounds
  await shell(
    `input tap ${Math.round((x1 + x2) / 2)} ${Math.round((y1 + y2) / 2)}`
  )
}

/** Waits for a node matching sel and taps its centre. */
async function tap(sel, opts) {
  const node = await waitForNode(sel, opts)
  await tapNode(node)
  return node
}

// --- The app ------------------------------------------------------------------------

async function pid() {
  const out = (await shell(`pidof ${PACKAGE}`).catch(() => '')).trim()
  return out ? parseInt(out, 10) : null
}

async function foregroundPackage() {
  const out = await shell('dumpsys activity activities')
  const m =
    /(?:topResumedActivity|mResumedActivity)[^\n]*?\s([\w.]+)\/[\w.]+/.exec(out)
  return m ? m[1] : null
}

async function pressHome() {
  await shell('input keyevent KEYCODE_HOME')
  await poll(async () => (await foregroundPackage()) !== PACKAGE, {
    timeoutMs: timeouts.ui.appearance,
    what: 'the app to leave the foreground',
  })
}

async function launch() {
  await shell(`am start -W -n ${PACKAGE}/${ACTIVITY}`)
}

async function waitForForeground() {
  await poll(async () => (await foregroundPackage()) === PACKAGE, {
    timeoutMs: timeouts.ui.appearance,
    what: 'the app to come to the foreground',
  })
}

/** Opens a URL the way a tap in another app would (android.intent.action.VIEW). */
async function openUrl(url) {
  await shell(
    `am start -W -a android.intent.action.VIEW -d ${shQuote(url)} ${PACKAGE}`
  )
}

async function screenshot(path) {
  const png = await adb(['exec-out', 'screencap', '-p'], { binary: true })
  require('fs').writeFileSync(path, png)
}

// --- Push notifications -------------------------------------------------------------

/**
 * Delivers an FCM data message to the app the way Google Play services would: the
 * broadcast FCM itself sends to the app's FirebaseInstanceIdReceiver, which runs
 * MessagingService.onMessageReceived with these key/value pairs as the data payload.
 * Needs adbd running as root (the receiver is guarded by a Google-only permission, which
 * root bypasses); start-emulator.sh arranges that.
 */
async function sendFcmData(data) {
  // FCM stamps every message with a sender and an id; RemoteMessage.getData()
  // leaves both out of the data payload, getMessageId() reads the id.
  const extras = Object.entries({
    from: '000000000000',
    'google.message_id': `app-e2e-${Date.now()}`,
    ...data,
  })
    .map(([k, v]) => `--es ${shQuote(k)} ${shQuote(v)}`)
    .join(' ')
  const out = await shell(
    `am broadcast -a com.google.android.c2dm.intent.RECEIVE -n ${PACKAGE}/com.google.firebase.iid.FirebaseInstanceIdReceiver ${extras}`
  )
  if (!/Broadcast completed/.test(out)) {
    throw new Error(`FCM broadcast did not complete: ${out.trim()}`)
  }
}

/** The notifications the app currently has posted: [{ title, text, channel }]. */
async function appNotifications() {
  const out = await shell('dumpsys notification --noredact')
  const result = []
  // One record per "NotificationRecord(...: pkg=<pkg> ...)" block.
  const blocks = out.split(/NotificationRecord\(/).slice(1)
  for (const block of blocks) {
    if (!block.includes(`pkg=${PACKAGE} `)) continue
    const title = /android\.title=String \((.*)\)\s*\n/.exec(block)
    const text = /android\.text=String \((.*)\)\s*\n/.exec(block)
    // The record header: "Notification(channel=chat_messages shortcut=null ...)".
    const channel = /Notification\(channel=([^\s)]+)/.exec(block)
    result.push({
      title: title ? title[1] : '',
      text: text ? text[1] : '',
      channel: channel ? channel[1] : '',
    })
  }
  return result
}

async function waitForNotification(
  pred,
  { timeoutMs = timeouts.ui.appearance } = {}
) {
  return poll(async () => (await appNotifications()).find(pred) || null, {
    timeoutMs,
    intervalMs: 750,
    what: 'a matching app notification',
  })
}

async function clearNotifications() {
  await shell('cmd notification cancel_all 2>/dev/null || true').catch(() => {})
  await shell('service call notification 1 2>/dev/null || true').catch(() => {})
}

/** Opens the shade and taps the notification carrying this title. */
async function tapNotification(title) {
  await shell('cmd statusbar expand-notifications')
  await tap({ text: title })
}

async function collapseShade() {
  await shell('cmd statusbar collapse')
}

module.exports = {
  PACKAGE,
  SERIAL,
  adb,
  shell,
  poll,
  uiNodes,
  findNode,
  waitForNode,
  tap,
  pid,
  foregroundPackage,
  pressHome,
  launch,
  waitForForeground,
  openUrl,
  screenshot,
  sendFcmData,
  appNotifications,
  waitForNotification,
  clearNotifications,
  tapNotification,
  collapseShade,
}
