// @ts-check
/**
 * The SysAdmin UX tab (docs/developers/reference/ux-telemetry.md section 6):
 * opening it loads every panel from /modtools/ux/* without errors, and the
 * panels render on an empty rollup as well as a filled one.
 */

const { test, expect } = require('./fixtures')
const { timeouts, environment } = require('./config')
const { loginViaModTools } = require('./utils/user')

const MODTOOLS_URL = environment.modtoolsBaseUrl

const PANELS = [
  'Data health',
  'Funnels',
  'Friction',
  'Second thoughts',
  'Help',
  'Adoption',
  'Still want it?',
  'Regret',
  'Search and browse',
]

const ENDPOINTS = [
  'health',
  'funnels',
  'outcomes',
  'fields',
  'friction',
  'adoption',
  'replyconfirm',
  'regret',
  'search',
]

test.describe('ModTools SysAdmin UX tab', () => {
  test('loads every panel from the UX read API', async ({ page, testEnv }) => {
    await loginViaModTools(page, testEnv.mod.email)

    const errors = []
    page.on('pageerror', (error) => errors.push(error.message))

    const statuses = {}
    page.on('response', (resp) => {
      const m = resp.url().match(/\/modtools\/ux\/([a-z]+)(\?|$)/)
      if (m) statuses[m[1]] = resp.status()
    })

    await page.goto(`${MODTOOLS_URL}/sysadmin?tab=ux`, {
      timeout: timeouts.navigation.initial,
    })

    for (const title of PANELS) {
      await expect(
        page.locator('.sysadmin-ux').getByText(title, { exact: true }).first()
      ).toBeVisible({ timeout: timeouts.ui.appearance })
    }

    await expect
      .poll(() => Object.keys(statuses).sort(), {
        timeout: timeouts.navigation.default,
      })
      .toEqual([...ENDPOINTS].sort())

    for (const endpoint of ENDPOINTS) {
      expect(statuses[endpoint], `/modtools/ux/${endpoint}`).toBe(200)
    }

    // A panel whose fetch failed shows its error in a danger notice.
    await expect(page.locator('.sysadmin-ux .ux-panel')).toHaveCount(
      PANELS.length
    )
    await expect(
      page.locator('.sysadmin-ux .ux-panel .notice--danger')
    ).toHaveCount(0)

    const body = await page.textContent('body')
    expect(body).not.toContain('something went wrong')
    expect(body).not.toContain('Oh dear')
    expect(errors).toHaveLength(0)
  })
})
