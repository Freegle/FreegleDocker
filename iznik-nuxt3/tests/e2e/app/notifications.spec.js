/**
 * Push notifications, end to end on the device, without Firebase's servers: the same
 * data message iznik-batch sends (PushNotificationService) is delivered to the app the
 * way Google Play services delivers it, through the FCM broadcast receiver. From there
 * everything is the app's own code: MessagingService, NotificationHelper, the plugin's
 * events into the WebView, and stores/mobile.js routing a tap on the notification.
 */

const { test, expect } = require('../fixtures')
const { timeouts } = require('../config')
const { loginViaHomepage } = require('../utils/user')
const device = require('./device')

// What PushNotificationService::buildChatPayload sends for one unread chat message.
function chatPayload(title, overrides = {}) {
  return {
    badge: '1',
    count: '1',
    chatcount: '1',
    notifcount: '0',
    title,
    message: '',
    chatids: '',
    'content-available': '1',
    image: 'www/images/user_logo.png',
    modtools: '0',
    sound: 'default',
    route: '/chats',
    category: 'CHAT_MESSAGE',
    channel_id: 'chat_messages',
    threadId: 'chats',
    notId: '1',
    ...overrides,
  }
}

async function listenForPushes(page) {
  await page.evaluate(() => {
    window.__e2ePushes = []
    window.Capacitor.Plugins.PushNotifications.addListener(
      'pushNotificationReceived',
      (n) => window.__e2ePushes.push(n)
    )
  })
}

test.describe('Push notifications', () => {
  test.beforeEach(async () => {
    await device.clearNotifications()
  })

  test('a data message in the foreground reaches the WebView and the shade', async ({
    page,
  }) => {
    // A device only gets pushes for a signed-in member, and a chat push makes the
    // app fetch that member's chats.
    await page.gotoAndVerify('/', { maxRetries: 1 })
    await loginViaHomepage(page, 'test@test.com', 'freegle')
    await listenForPushes(page)
    const title = `E2E foreground ${Date.now()}`

    await device.sendFcmData(chatPayload(title))

    // The plugin raises pushNotificationReceived with the payload.
    await expect
      .poll(() => page.evaluate(() => window.__e2ePushes.length), {
        timeout: timeouts.ui.appearance,
      })
      .toBeGreaterThan(0)
    const received = await page.evaluate(() => window.__e2ePushes[0])
    expect(received.data.title).toBe(title)
    expect(received.data.foreground).toBe(true)

    // NotificationHelper posts it on the chat_messages channel whatever the app state.
    const posted = await device.waitForNotification((n) => n.title === title)
    expect(posted.channel).toBe('chat_messages')
  })

  test('a push to the backgrounded app routes it to chats when reopened', async ({
    page,
  }) => {
    await page.gotoAndVerify('/', { maxRetries: 1 })
    await loginViaHomepage(page, 'test@test.com', 'freegle')
    const title = `E2E tap ${Date.now()}`

    // Background the app and wait until it knows it is: stores/mobile.js decides
    // where a push goes from App.getState(), and the state flips a moment after
    // the launcher takes the screen.
    await device.pressHome()
    await expect
      .poll(
        () =>
          page.evaluate(() =>
            window.Capacitor.Plugins.App.getState().then((s) => s.isActive)
          ),
        { timeout: timeouts.ui.appearance }
      )
      .toBe(false)

    await device.sendFcmData(chatPayload(title))
    await device.waitForNotification((n) => n.title === title)

    await device.tapNotification(title)
    await device.waitForForeground()

    // handleNotification: a chat push received while the app is in the background
    // sets its route to /chats, so the app is on chats when the tap brings it back.
    await expect
      .poll(() => page.url(), { timeout: timeouts.ui.appearance })
      .toMatch(/\/chats(\/|\?|$)/)
  })

  test('a message without a channel is ignored as legacy', async () => {
    const title = `E2E legacy ${Date.now()}`
    await device.sendFcmData({ title, message: 'no channel_id' })
    // No system notification is posted for it.
    await expect
      .poll(
        async () =>
          (await device.appNotifications()).some((n) => n.title === title),
        {
          timeout: timeouts.assertion.normal,
        }
      )
      .toBe(false)
  })
})
