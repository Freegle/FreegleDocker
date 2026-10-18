// @ts-check
/**
 * Tests for ModTools dashboard and post-login group visibility.
 */

const { test, expect } = require('./fixtures')
const { timeouts, environment } = require('./config')
const { loginViaModTools } = require('./utils/user')

const MODTOOLS_URL = environment.modtoolsBaseUrl

// Helper: dismiss any overlay modals that block interaction.
async function dismissAllModals(page) {
  await page.evaluate(() => {
    document
      .querySelectorAll('.modal.show, .modal[style*="display: block"]')
      .forEach((el) => {
        el.classList.remove('show')
        el.style.display = 'none'
      })
    document.querySelectorAll('.modal-backdrop').forEach((el) => el.remove())
    document.body.classList.remove('modal-open')
    document.body.style.removeProperty('overflow')
    document.body.style.removeProperty('padding-right')
  })
}

// Helper: check page for common error indicators.
async function assertNoErrors(page) {
  const body = await page.textContent('body')
  expect(body).not.toContain('something went wrong')
  expect(body).not.toContain('Oh dear')
  expect(body).not.toContain('undefined is not an object')
  expect(body).not.toContain('Cannot read properties of undefined')
}

test.describe('ModTools Dashboard', () => {
  test('dashboard loads without errors', async ({ page, testEnv }) => {
    await loginViaModTools(page, testEnv.mod.email)

    // Collect page errors
    const errors = []
    page.on('pageerror', (error) => {
      errors.push(error.message)
    })

    await page.goto(`${MODTOOLS_URL}/`, {
      timeout: timeouts.navigation.initial,
    })

    // Wait for the page to have meaningful content
    await page.waitForLoadState('domcontentloaded', {
      timeout: timeouts.navigation.default,
    })

    await dismissAllModals(page)
    await assertNoErrors(page)
    expect(errors).toHaveLength(0)
  })

  // Every other ModTools test logs in and leaves / at once, so the home page's
  // async work (the config fetches, then the dashboard sections, and ModYesterday's
  // backup check) finished on some CI runs and not others and its coverage
  // moved with no code change. This test waits for that work and ends on /.
  test('home dashboard renders and the test ends on /', async ({
    page,
    testEnv,
  }) => {
    // ModYesterday asks the live Yesterday host, which is not part of the CI
    // stack. Answer for it so the wait below never depends on that host.
    // Registered before login because the home page mounts during login.
    await page.route(
      'https://yesterday.ilovefreegle.org:8444/api/**',
      (route) =>
        route.fulfill({
          status: 200,
          contentType: 'application/json',
          headers: { 'access-control-allow-origin': '*' },
          body: JSON.stringify(
            route.request().url().endsWith('/restore-status')
              ? { inProgress: false, status: 'completed' }
              : {
                  // Today, so the backup is not reported as over two days old.
                  date: new Date().toISOString().slice(0, 10).replace(/-/g, ''),
                  backup_time: '03:00',
                }
          ),
        })
    )

    // testEnv.mod is seeded as Admin, which ModYesterday needs.
    await loginViaModTools(page, testEnv.mod.email)

    // The page renders once the moderator is known, but its sections fill in only after
    // onMounted's config fetches finish and refreshAll() has run. The last section is
    // Spammers; wait for it, let the requests settle, then for Refresh to be enabled, which
    // it is only when no section is still loading.
    await expect(page.getByRole('heading', { name: 'Spammers' })).toBeVisible({
      timeout: timeouts.navigation.slowPage,
    })
    await page.waitForLoadState('networkidle', {
      timeout: timeouts.navigation.slowPage,
    })
    await expect(page.getByRole('button', { name: /Refresh/ })).toBeEnabled({
      timeout: timeouts.navigation.slowPage,
    })

    // ModYesterday shows its alert only once both of its fetches have settled.
    const yesterday = page.locator('.alert', {
      hasText: 'System backed up on Yesterday up to:',
    })
    await expect(yesterday).toBeVisible({ timeout: timeouts.ui.appearance })
    await expect(yesterday).toContainText(/\d{1,2} \w{3} \d{4}/)

    // No navigation: coverage is read from this page when the test ends.
    expect(new URL(page.url()).pathname).toBe('/')
  })
})
