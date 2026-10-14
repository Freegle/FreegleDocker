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
  'What members say',
  'Friction',
  'Second thoughts',
  'Help',
  'Adoption',
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
  'regret',
  'search',
  'experiments',
]

// A filled rollup for the second test, served in place of three read endpoints: CI's database has
// no rollup rows, so without it a browser would only ever draw the empty states. The shapes are
// the Go responses in iznik-server-go/ux; the figures are the unit specs' examples.
const step = (name, over = {}) => ({
  step: name,
  entered: 100,
  reentered: 0,
  visits: 100,
  completed: 60,
  backed: 10,
  abandoned: 30,
  revisits: 5,
  validation_failures: 2,
  help_sought: 4,
  dwell_p50_ms: 8000,
  dwell_p90_ms: 45000,
  by: {
    device_class: [
      { key: 'mobile', entered: 70, visits: 70, completed: 35, abandoned: 25 },
      { key: 'desktop', entered: 30, visits: 30, completed: 25, abandoned: 5 },
    ],
    logged_in: [
      { key: 'true', entered: 100, visits: 100, completed: 60, abandoned: 30 },
    ],
    variant: [],
  },
  ...over,
})

const FILLED = {
  funnels: {
    start: '2026-10-01',
    end: '2026-10-07',
    flows: [
      {
        flow: 'give',
        steps: [
          step('give'),
          step('give-whereami', {
            entered: 80,
            visits: 80,
            completed: 20,
            abandoned: 10,
          }),
          step('give-mobile-photos', {
            entered: 120,
            visits: 130,
            completed: 70,
            abandoned: 50,
          }),
        ],
      },
    ],
    previous: {
      flows: [
        {
          flow: 'give',
          steps: [step('give', { entered: 200, visits: 200, completed: 150 })],
        },
      ],
    },
  },
  outcomes: {
    flows: [
      {
        flow: 'give',
        started: 220,
        submitted: 110,
        submit_ok: 105,
        submit_failed: 5,
        completed: 100,
        abandoned: 90,
        resumed: 3,
      },
    ],
    previous: { flows: [{ flow: 'give', started: 200, completed: 80 }] },
  },
  experiments: {
    running_count: 1,
    max_running: 5,
    decoy_yes_rate: 0.4,
    decoy_answers: 20,
    values: [],
    general_problems: [],
    general_ideas: [],
    flows: [
      {
        flow: 'give',
        ideas_tried: 1,
        survey_best: 20,
        survey_worst: 4,
        running: [
          {
            id: 3,
            flow: 'give',
            name: 'give-photos-skip',
            title: 'Make skipping photos obvious',
            change: 'A plain link above the camera button.',
            status: 'running',
            started_at: '2026-10-14 10:00:00',
            ends_at: '2026-10-15 10:00:00',
            hours: 6.5,
            pr_url: 'https://github.com/Freegle/Iznik/pull/1800',
            latest_read: {
              hours: 6.5,
              summary:
                'After 6.5 hours: 210 started with the change and 200 without.',
            },
            reaction: {
              better: 3,
              worse: 1,
              dont_care: 2,
              dont_understand: 0,
              total: 6,
              comments: ['Looks cleaner'],
            },
          },
        ],
        history: [
          {
            id: 2,
            flow: 'give',
            title: 'Shorter first screen',
            change: 'Postcode moved after photos.',
            status: 'adopted',
            started_at: '2026-10-10 09:00:00',
            decided_at: '2026-10-11 09:00:00',
            decision:
              'Adopted at the end: the change was probably better. 400 started with the change and 400 without.',
            pr_url: 'https://github.com/Freegle/Iznik/pull/1790',
            reaction: { total: 0 },
          },
        ],
        ideas: [
          {
            id: 1,
            title: 'Ask to sign in first',
            rationale: 'Most mobile starts are logged out.',
            expected: 'Fewer dead starts.',
            score: 8.2,
            source: 'ai',
          },
        ],
        problems: [
          {
            id: 1,
            title: 'Photos are slow',
            description: 'Uploading a photo takes a long time on a phone.',
            source: 'members',
            mentions: 14,
            answers: 120,
            asked: 22,
            weight: 0.32,
            most: 9,
            least: 1,
            times_shown: 22,
            quotes: [{ answer_id: 7, span: 'the photo took ages to upload' }],
            status: 'open',
            ideas: [
              {
                id: 1,
                title: 'Ask to sign in first',
                status: 'open',
                type: 'experiment',
                score: 8.2,
              },
            ],
            experiments: [
              {
                id: 3,
                title: 'Make skipping photos obvious',
                status: 'running',
              },
            ],
          },
        ],
      },
    ],
    surveys: [
      {
        prompt_id: 12,
        kind: 'survey',
        question: 'Which of these has been the biggest problem for you?',
        status: 'open',
        responses: 20,
        tallies: [
          {
            key: 'problem:1',
            label: 'Photos are slow',
            times_shown: 12,
            most: 9,
            least: 1,
            count: 0,
          },
          {
            key: 'problem:2',
            label: 'Too many emails',
            times_shown: 10,
            most: 3,
            least: 8,
            count: 0,
          },
        ],
        none: 2,
        dont_understand: 0,
        comments: [],
      },
      {
        prompt_id: 13,
        kind: 'awareness',
        flow: 'general',
        question: 'Do you know how to sell an item for money?',
        status: 'closed',
        responses: 20,
        yes: 8,
        no: 10,
        not_sure: 2,
        decoy: true,
        tallies: [],
        dont_understand: 0,
        comments: [],
      },
    ],
  },
}

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

  test('draws a funnel, its problems, experiments and what members say from filled data', async ({
    page,
    testEnv,
  }) => {
    await loginViaModTools(page, testEnv.mod.email)

    const errors = []
    page.on('pageerror', (error) => errors.push(error.message))

    for (const [endpoint, data] of Object.entries(FILLED)) {
      await page.route(
        new RegExp(`/modtools/ux/${endpoint}(\\?|$)`),
        (route) =>
          route.request().method() === 'GET'
            ? route.fulfill({
                status: 200,
                contentType: 'application/json',
                body: JSON.stringify(data),
              })
            : route.continue()
      )
    }

    await page.goto(`${MODTOOLS_URL}/sysadmin?tab=ux`, {
      timeout: timeouts.navigation.initial,
    })
    const ux = page.locator('.sysadmin-ux')
    const shows = (text) =>
      expect(ux.getByText(text).first()).toBeVisible({
        timeout: timeouts.ui.appearance,
      })

    // The funnel's sentence: starts, finishes and the step that lost most.
    await shows(/Offering something: 220 started, 100 finished/)
    await shows(/Biggest loss: 38% of the 130 visits/)

    // Its problems, with what members wrote behind a toggle.
    await shows('Photos are slow')
    await ux
      .locator('.ux-problems')
      .getByText(/What members wrote \(1\)/)
      .first()
      .click()
    await shows('the photo took ages to upload')

    // Its experiments: the running one with its latest read, then the history.
    await shows('Make skipping photos obvious')
    await shows(/After 6\.5 hours: 210 started with the change/)
    await shows('Shorter first screen')

    // What members say: the follow-up ranking and the decoy.
    await shows('Which of these has been the biggest problem for you?')
    await shows(/^Biggest problem$/)
    await shows('Do you know how to sell an item for money?')

    await expect(
      page.locator('.sysadmin-ux .ux-panel .notice--danger')
    ).toHaveCount(0)
    expect(errors).toHaveLength(0)
  })
})
