import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest'
import { mount, flushPromises } from '@vue/test-utils'
import { setActivePinia, createPinia } from 'pinia'
import ModSysAdminUxExperiments from '~/modtools/components/ModSysAdminUxExperiments.vue'
import ModSysAdminUxExperimentsBadge from '~/modtools/components/ModSysAdminUxExperimentsBadge.vue'
import { useUxExperimentsStore } from '~/modtools/stores/uxExperiments'
import { uxStubs } from './uxStubs'

// The registry is F's. A fake one stands in for the pieces other parts of the tab add.
const hosted = vi.hoisted(() => ({ pieces: [] }))
vi.mock('~/modtools/composables/useUxSections', () => ({
  hostedFor: (slot) => (slot === 'experiment' ? hosted.pieces : []),
}))

vi.mock('~/ux-experiments.manifest.json', () => ({
  default: { experiments: [] },
}))

const exp = (id, flow, status, over = {}) => ({
  id,
  flow,
  name: `exp-${id}`,
  title: `Experiment ${id}`,
  hypothesis: 'h',
  change: `The change in ${id}.`,
  expected: 'e',
  status,
  created_at: `2026-10-0${id % 9 || 9} 00:00:00`,
  started_at: null,
  ends_at: null,
  decided_at: null,
  decision: null,
  hours: 0,
  design: null,
  result: null,
  latest_read: null,
  cleanup_overdue: false,
  holdout_until: null,
  pr_url: null,
  cleanup_pr_url: null,
  ...over,
})

const read = (over = {}) => ({
  arms: {
    control: { starts: 200, completes: 92 },
    b: { starts: 210, completes: 107 },
  },
  primary: {
    b: {
      control_rate: 0.46,
      variant_rate: 0.51,
      diff_points: 5.4,
      p_better: 0.91,
      enough: true,
      share_off: false,
    },
  },
  guardrails: [],
  ...over,
})

// The answer of GET /modtools/ux/experiments: an entry for each funnel, running apart from the rest.
const RUNNING_GIVE = exp(3, 'give', 'running', {
  started_at: '2026-10-14 10:00:00',
  ends_at: '2026-10-15 10:00:00',
  hours: 6.5,
  result: read(),
})
const RUNNING_ASK = exp(8, 'ask', 'running', {
  started_at: '2026-10-13 10:00:00',
  result: { mde_points: 4 },
})
const DRAFT_GIVE = exp(5, 'give', 'draft', {
  created_at: '2026-10-14 08:00:00',
})
const DRAFT_SETTINGS = exp(6, 'settings', 'draft', {
  created_at: '2026-10-13 08:00:00',
})
const ADOPTED_GIVE = exp(2, 'give', 'adopted', {
  started_at: '2026-10-10 09:00:00',
  decided_at: '2026-10-11 09:00:00',
  result: read(),
})
const REVERTED_ASK = exp(1, 'ask', 'reverted', {
  started_at: '2026-10-04 09:00:00',
  decided_at: '2026-10-05 09:00:00',
  result: read(),
})

const flowsOf = (list) => {
  const byFlow = new Map()
  for (const e of list) {
    if (!byFlow.has(e.flow)) {
      byFlow.set(e.flow, { flow: e.flow, running: [], history: [] })
    }
    byFlow.get(e.flow)[e.status === 'running' ? 'running' : 'history'].push(e)
  }
  return [...byFlow.values()]
}

const answer = (list, over = {}) => ({
  running_count: list.filter((e) => e.status === 'running').length,
  max_running: 5,
  flows: flowsOf(list),
  ...over,
})

const LIST = [
  RUNNING_GIVE,
  RUNNING_ASK,
  DRAFT_GIVE,
  DRAFT_SETTINGS,
  ADOPTED_GIVE,
  REVERTED_ASK,
]

// What useUxView.parseView gives for this section.
const viewOf = ({ flow = '', exp: open, estate } = {}) => ({
  section: 'experiments',
  flow,
  extra: { ...(open ? { exp: open } : {}), ...(estate ? { estate } : {}) },
})

describe('ModSysAdminUxExperiments', () => {
  let store

  beforeEach(() => {
    hosted.pieces = []
    setActivePinia(createPinia())
    store = useUxExperimentsStore()
    store.loadIfStale = vi.fn().mockResolvedValue(undefined)
    store.load = vi.fn().mockResolvedValue(undefined)
    store.experimentAction = vi.fn().mockResolvedValue({ queued: true })
    store.createExperiment = vi
      .fn()
      .mockResolvedValue({ id: 11, status: 'draft' })
    store.experiments.data = answer(LIST)
  })

  afterEach(() => {
    vi.useRealTimers()
  })

  const make = (view = viewOf(), data) => {
    if (data) store.experiments.data = data
    return mount(ModSysAdminUxExperiments, {
      props: { view },
      global: {
        stubs: uxStubs,
        provide: { uxHrefFor: (patch) => `/link?${JSON.stringify(patch)}` },
      },
    })
  }

  const titles = (w) => w.findAll('.ux-exp-row .title').map((t) => t.text())
  const lastNavigate = (w) => w.emitted('navigate').at(-1)[0]

  describe('its place in the tab', () => {
    it('is one entry in the registry, between Funnels and Pages, that loads its own data', async () => {
      // The registry is mocked above, so the real one is fetched by name.
      const real = await vi.importActual('~/modtools/composables/useUxSections')
      const keys = real.uxSections().map((s) => s.key)
      expect(keys.indexOf('experiments')).toBeGreaterThan(
        keys.indexOf('funnels')
      )
      expect(keys.indexOf('experiments')).toBeLessThan(keys.indexOf('pages'))
      const entry = real.SECTIONS.find((s) => s.key === 'experiments')
      expect(entry).toMatchObject({
        label: 'Experiments',
        order: 30,
        component: ModSysAdminUxExperiments,
        badge: ModSysAdminUxExperimentsBadge,
        funnelLink: { label: 'Experiments' },
        filters: [],
        slots: [],
        params: ['exp', 'estate'],
      })
    })
  })

  describe('loading', () => {
    it('asks for the list when it is drawn, unless a recent one is held', () => {
      make()
      expect(store.loadIfStale).toHaveBeenCalledWith(30000)
    })

    it('says loading before the first answer', () => {
      store.experiments.data = null
      const w = make()
      expect(w.text()).toContain('Loading...')
      expect(w.find('.strip').exists()).toBe(false)
    })

    it('says this part is not switched on when the route is missing, and draws nothing else', () => {
      store.experiments.data = null
      store.experiments.missing = true
      const w = make()
      expect(w.text()).toContain('This part is not switched on yet.')
      expect(w.find('.strip').exists()).toBe(false)
      expect(w.find('.ux-exp-row').exists()).toBe(false)
    })

    it('shows an error from the server', () => {
      store.experiments.data = null
      store.experiments.error = 'Could not read experiments'
      expect(make().text()).toContain('Could not read experiments')
    })

    it('says there are none yet, and still offers the new button', () => {
      const w = make(viewOf(), answer([]))
      expect(w.text()).toContain(
        'No experiments yet. Use New experiment to add one.'
      )
      expect(w.find('button.new').exists()).toBe(true)
    })
  })

  describe('the strip', () => {
    it('says how many of the slots are in use', () => {
      expect(make().find('.slots').text()).toBe(
        '2 of 5 experiment slots in use.'
      )
    })

    it('says no more can start when the cap is reached', () => {
      const w = make(viewOf(), answer(LIST, { running_count: 5 }))
      expect(w.find('.slots').text()).toBe(
        '5 of 5 experiment slots in use. No more can start until one ends.'
      )
    })

    it('uses the cap the server gives', () => {
      expect(
        make(viewOf(), answer(LIST, { max_running: 3 }))
          .find('.slots')
          .text()
      ).toBe('2 of 3 experiment slots in use.')
    })

    it('opens the form from New experiment', async () => {
      const w = make()
      await w.find('button.new').trigger('click')
      expect(lastNavigate(w)).toStrictEqual({ exp: 'new' })
    })
  })

  describe('the rows', () => {
    it('lists running first, then not started, then finished, newest first', () => {
      expect(titles(make())).toEqual([
        'Experiment 3',
        'Experiment 8',
        'Experiment 5',
        'Experiment 6',
        'Experiment 2',
        'Experiment 1',
      ])
    })

    it('puts the experiments of every funnel in one list', () => {
      const flows = make()
        .findAll('.ux-exp-row .funnel')
        .map((f) => f.text())
      expect(flows).toEqual([
        'Offering',
        'Asking',
        'Offering',
        'Settings',
        'Offering',
        'Asking',
      ])
    })

    it('lists a running experiment on a funnel nobody has started, and counts its slot', () => {
      // The section is about experiments, not traffic, so a quiet funnel loses nothing.
      const quiet = exp(9, 'settings', 'running', {
        started_at: '2026-10-14 12:00:00',
        result: { mde_points: 6 },
      })
      const w = make(viewOf(), answer([quiet]))
      expect(titles(w)).toEqual(['Experiment 9'])
      expect(w.find('.slots').text()).toBe('1 of 5 experiment slots in use.')
    })

    it('draws a funnel the answer lists twice only once', () => {
      const dup = answer(LIST)
      dup.flows.push({ flow: 'give', running: [RUNNING_GIVE], history: [] })
      expect(
        titles(make(viewOf(), dup)).filter((t) => t === 'Experiment 3')
      ).toHaveLength(1)
    })

    it('opens one row at a time, from the address', () => {
      const w = make(viewOf({ exp: '5' }))
      const details = w.findAll('.ux-exp-detail')
      expect(details).toHaveLength(1)
      expect(details[0].text()).toContain('The change in 5.')
      const rows = w.findAll('.ux-exp-row')
      expect(rows.map((r) => r.attributes('aria-expanded'))).toEqual([
        'false',
        'false',
        'true',
        'false',
        'false',
        'false',
      ])
    })

    it('opens a row on a press, and closes the open one on a press', async () => {
      const w = make()
      await w.findAll('.ux-exp-row')[1].trigger('click')
      expect(lastNavigate(w)).toStrictEqual({ exp: '8' })

      const open = make(viewOf({ exp: '8' }))
      await open.findAll('.ux-exp-row')[1].trigger('click')
      expect(lastNavigate(open)).toStrictEqual({ exp: undefined })
    })

    it('draws nothing open for an address that names no experiment, and says so', () => {
      const w = make(viewOf({ exp: '999' }))
      expect(w.find('.ux-exp-detail').exists()).toBe(false)
      expect(w.find('.not-in-list').text()).toBe(
        'That experiment is not in the list.'
      )
    })

    it('ignores an open value that is not a number', () => {
      const w = make(viewOf({ exp: 'abc' }))
      expect(w.find('.ux-exp-detail').exists()).toBe(false)
      expect(w.find('.form, .ux-exp-form').exists()).toBe(false)
    })

    it('says a request is waiting on the row it was made for', async () => {
      // Experiment 6 is on Settings, where nothing else runs.
      const w = make(viewOf({ exp: '6' }))
      await w.find('button.start').trigger('click')
      await flushPromises()
      expect(store.experimentAction).toHaveBeenCalledWith(6, {
        action: 'start',
      })
      const row = w.findAll('.ux-exp-row')[3]
      expect(row.find('.waiting').text()).toBe('Waiting for the batch')
    })
  })

  describe('the Funnel select', () => {
    it('lists all funnels first, then the seven by title', () => {
      const options = make()
        .findAll('.flow-select option')
        .map((o) => o.text())
      expect(options).toEqual([
        'All funnels',
        'Offering something',
        'Asking for something',
        'Replying to a post',
        'Promising an item',
        'Marking taken or received',
        'Signing in or joining',
        'Settings',
      ])
    })

    it('shows the funnel from the address', () => {
      expect(
        make(viewOf({ flow: 'ask' })).find('.flow-select').element.value
      ).toBe('ask')
      expect(make().find('.flow-select').element.value).toBe('')
    })

    it('changes the funnel, and closes whatever was open', async () => {
      const w = make(viewOf({ exp: '5' }))
      await w.find('.flow-select').setValue('ask')
      expect(lastNavigate(w)).toStrictEqual({ flow: 'ask', exp: undefined })
      await w.find('.flow-select').setValue('')
      expect(lastNavigate(w)).toStrictEqual({ flow: undefined, exp: undefined })
    })

    it('shows only that funnel, and says so, with how many run on the others', () => {
      const w = make(viewOf({ flow: 'give' }))
      expect(titles(w)).toEqual([
        'Experiment 3',
        'Experiment 5',
        'Experiment 2',
      ])
      expect(w.find('.funnel-notice').text()).toBe(
        'Showing Offering only. 1 running on other funnels. Show all'
      )
    })

    it('does not hide a running experiment without saying so: Show all brings them back', async () => {
      const w = make(viewOf({ flow: 'give' }))
      expect(titles(w)).not.toContain('Experiment 8')
      await w.find('.funnel-notice a').trigger('click')
      expect(lastNavigate(w)).toStrictEqual({ flow: undefined, exp: undefined })
    })

    it('gives Show all as a link whose address can be copied', () => {
      const href = make(viewOf({ flow: 'give' }))
        .find('.funnel-notice a')
        .attributes('href')
      expect(href).toBe(`/link?${JSON.stringify({})}`)
    })

    it('leaves out the count when nothing runs on the other funnels', () => {
      const w = make(viewOf({ flow: 'ask' }), answer([RUNNING_ASK, DRAFT_GIVE]))
      expect(w.find('.funnel-notice').text()).toBe(
        'Showing Asking only. Show all'
      )
    })

    it('has no notice when it shows every funnel', () => {
      expect(make().find('.funnel-notice').exists()).toBe(false)
    })

    it('takes a value that is no funnel as no choice at all', () => {
      const w = make(viewOf({ flow: 'none' }))
      expect(titles(w)).toHaveLength(6)
      expect(w.find('.funnel-notice').exists()).toBe(false)
    })

    it('says nothing has been tried on a funnel that has only a draft', () => {
      const w = make(viewOf({ flow: 'settings' }))
      expect(titles(w)).toEqual(['Experiment 6'])
      expect(w.find('.untried').text()).toBe(
        'Nothing has been tried on this funnel yet.'
      )
    })

    it('says nothing is tried on a funnel with none at all', () => {
      const w = make(viewOf({ flow: 'reply' }))
      expect(w.find('.untried').exists()).toBe(true)
      expect(w.text()).toContain('No experiments here.')
    })

    it('says nothing of the sort for a funnel that has run something', () => {
      expect(
        make(viewOf({ flow: 'give' }))
          .find('.untried')
          .exists()
      ).toBe(false)
    })
  })

  describe('the chips', () => {
    const chipTexts = (w) => w.findAll('.chip').map((c) => c.text())

    it('count what each would show', () => {
      expect(chipTexts(make())).toEqual([
        'All (6)',
        'Running (2)',
        'Not started (2)',
        'Finished (2)',
      ])
    })

    it('count within the funnel chosen', () => {
      expect(chipTexts(make(viewOf({ flow: 'give' })))).toEqual([
        'All (3)',
        'Running (1)',
        'Not started (1)',
        'Finished (1)',
      ])
    })

    it('show only that kind of experiment', () => {
      expect(titles(make(viewOf({ estate: 'running' })))).toEqual([
        'Experiment 3',
        'Experiment 8',
      ])
      expect(titles(make(viewOf({ estate: 'draft' })))).toEqual([
        'Experiment 5',
        'Experiment 6',
      ])
      expect(titles(make(viewOf({ estate: 'finished' })))).toEqual([
        'Experiment 2',
        'Experiment 1',
      ])
    })

    it('mark the one in use', () => {
      const w = make(viewOf({ estate: 'draft' }))
      expect(w.find('.chip-draft').attributes('aria-pressed')).toBe('true')
      expect(w.find('.chip-all').attributes('aria-pressed')).toBe('false')
    })

    it('are chosen with a press, which closes what was open; all takes the choice off', async () => {
      const w = make(viewOf({ exp: '3' }))
      await w.find('.chip-running').trigger('click')
      expect(lastNavigate(w)).toStrictEqual({
        estate: 'running',
        exp: undefined,
      })
      await w.find('.chip-all').trigger('click')
      expect(lastNavigate(w)).toStrictEqual({
        estate: undefined,
        exp: undefined,
      })
    })

    it('ignore a value in the address that is no kind', () => {
      expect(titles(make(viewOf({ estate: 'nonsense' })))).toHaveLength(6)
    })
  })

  describe('paging the finished experiments', () => {
    const many = (n) =>
      Array.from({ length: n }, (_, i) =>
        exp(100 + i, 'give', 'stopped', {
          decided_at: `2026-09-${String(10 + (i % 18)).padStart(2, '0')} ${String(i % 24).padStart(2, '0')}:00:00`,
        })
      )

    it('shows 25 finished ones and offers 25 more', async () => {
      const w = make(viewOf(), answer([RUNNING_GIVE, DRAFT_GIVE, ...many(60)]))
      expect(w.findAll('.ux-exp-row')).toHaveLength(2 + 25)
      expect(w.find('button.more').text()).toBe('Show 25 more')
      await w.find('button.more').trigger('click')
      expect(w.findAll('.ux-exp-row')).toHaveLength(2 + 50)
      expect(w.find('button.more').text()).toBe('Show 10 more')
      await w.find('button.more').trigger('click')
      expect(w.findAll('.ux-exp-row')).toHaveLength(2 + 60)
      expect(w.find('button.more').exists()).toBe(false)
    })

    it('never pages away a running or a not started experiment', () => {
      const w = make(
        viewOf(),
        answer([
          RUNNING_GIVE,
          RUNNING_ASK,
          DRAFT_GIVE,
          DRAFT_SETTINGS,
          ...many(40),
        ])
      )
      const t = titles(w)
      for (const kept of [
        'Experiment 3',
        'Experiment 8',
        'Experiment 5',
        'Experiment 6',
      ]) {
        expect(t).toContain(kept)
      }
    })

    it('has no button when there are 25 finished or fewer', () => {
      expect(
        make(viewOf(), answer(many(25)))
          .find('button.more')
          .exists()
      ).toBe(false)
    })

    it('starts the paging again when a choice is made', async () => {
      const w = make(viewOf(), answer([DRAFT_GIVE, ...many(60)]))
      await w.find('button.more').trigger('click')
      expect(w.findAll('.ux-exp-row')).toHaveLength(1 + 50)
      await w.setProps({ view: viewOf({ estate: 'finished' }) })
      expect(w.findAll('.ux-exp-row')).toHaveLength(25)
    })
  })

  describe('the form', () => {
    it('opens for new, with no row open', () => {
      const w = make(viewOf({ exp: 'new' }))
      expect(w.find('.ux-exp-form').exists()).toBe(true)
      expect(w.find('.ux-exp-detail').exists()).toBe(false)
    })

    it('is not drawn otherwise', () => {
      expect(make().find('.ux-exp-form').exists()).toBe(false)
    })

    it('starts on the funnel in view', () => {
      const w = make(viewOf({ exp: 'new', flow: 'ask' }))
      expect(w.find('.f-flow').element.value).toBe('ask')
    })

    it('closes when cancelled', async () => {
      const w = make(viewOf({ exp: 'new' }))
      await w.find('.ux-exp-form .cancel').trigger('click')
      expect(lastNavigate(w)).toStrictEqual({ exp: undefined })
    })

    async function fillAndSave(w, buttonClass) {
      const v = {
        name: 'brand-new',
        title: 'A title',
        hypothesis: 'h',
        change: 'c',
        expected: 'e',
      }
      for (const [key, value] of Object.entries(v)) {
        await w.find(`.f-${key}`).setValue(value)
      }
      await w.find(buttonClass).trigger('click')
      await flushPromises()
    }

    it('opens the new draft and shows every funnel and kind when it is saved', async () => {
      const w = make(viewOf({ exp: 'new', estate: 'running' }))
      store.experiments.data = answer([...LIST, exp(11, 'give', 'draft')])
      await fillAndSave(w, '.save-draft')
      expect(store.createExperiment).toHaveBeenCalledTimes(1)
      expect(lastNavigate(w)).toStrictEqual({
        exp: '11',
        estate: undefined,
        flow: undefined,
      })
    })

    it('moves a funnel choice to the funnel of the new draft, so it shows', async () => {
      const w = make(viewOf({ exp: 'new', flow: 'give' }))
      await w.find('.f-flow').setValue('ask')
      store.experiments.data = answer([...LIST, exp(11, 'ask', 'draft')])
      await fillAndSave(w, '.save-draft')
      expect(lastNavigate(w)).toStrictEqual({
        exp: '11',
        estate: undefined,
        flow: 'ask',
      })
    })

    it('asks the batch to start it on save and start, and the row says so', async () => {
      const w = make(viewOf({ exp: 'new' }))
      store.experiments.data = answer([...LIST, exp(11, 'give', 'draft')])
      await fillAndSave(w, '.save-start')
      expect(store.experimentAction).toHaveBeenCalledWith(11, {
        action: 'start',
      })
      await w.setProps({ view: viewOf({ exp: '11' }) })
      const row = w
        .findAll('.ux-exp-row')
        .find((r) => r.text().includes('Experiment 11'))
      expect(row.find('.waiting').exists()).toBe(true)
      expect(w.text()).toContain('Start requested')
    })

    describe('a draft that the list does not hold yet', () => {
      it('says it will show in a moment, then asks again until it does', async () => {
        vi.useFakeTimers()
        const w = make(viewOf({ exp: 'new' }))
        await fillAndSave(w, '.save-draft')
        await w.setProps({ view: viewOf({ exp: '11' }) })
        expect(w.find('.saved-notice').text()).toBe(
          'Saved. It will show in the list in a moment.'
        )
        expect(store.load).not.toHaveBeenCalled()
        await vi.advanceTimersByTimeAsync(3000)
        expect(store.load).toHaveBeenCalledTimes(1)
        store.experiments.data = answer([...LIST, exp(11, 'give', 'draft')])
        await flushPromises()
        expect(w.find('.saved-notice').exists()).toBe(false)
        await vi.advanceTimersByTimeAsync(30000)
        expect(store.load).toHaveBeenCalledTimes(1)
      })

      it('gives up after three looks', async () => {
        vi.useFakeTimers()
        const w = make(viewOf({ exp: 'new' }))
        await fillAndSave(w, '.save-draft')
        await vi.advanceTimersByTimeAsync(60000)
        expect(store.load).toHaveBeenCalledTimes(3)
      })

      it('stops looking when the section is left', async () => {
        vi.useFakeTimers()
        const w = make(viewOf({ exp: 'new' }))
        await fillAndSave(w, '.save-draft')
        w.unmount()
        await vi.advanceTimersByTimeAsync(60000)
        expect(store.load).not.toHaveBeenCalled()
      })
    })
  })

  describe('the details', () => {
    it('are given the whole list and whether the cap is full, so Start is off when it would be refused', () => {
      // Only the Not started chip is chosen, but the running experiment on the funnel still counts.
      const w = make(viewOf({ exp: '5', estate: 'draft' }))
      expect(titles(w)).not.toContain('Experiment 3')
      expect(w.find('button.start').attributes('disabled')).toBeDefined()
      expect(w.find('button.start').attributes('title')).toBe(
        'Another change is running on this funnel'
      )
    })

    it('say the cap is reached', () => {
      const w = make(viewOf({ exp: '6' }), answer(LIST, { running_count: 5 }))
      expect(w.find('button.start').attributes('title')).toBe(
        'Already at the cap of running experiments'
      )
    })

    it('allow a start on a funnel with nothing running, below the cap', () => {
      const w = make(viewOf({ exp: '6' }))
      expect(w.find('button.start').attributes('disabled')).toBeUndefined()
    })

    it('draw the pieces other parts of the tab add, each given the experiment', () => {
      hosted.pieces = [
        {
          name: 'Piece',
          props: ['experiment'],
          template: '<div class="piece">{{ experiment.title }}</div>',
        },
      ]
      const w = make(viewOf({ exp: '3' }))
      expect(w.findAll('.piece').map((p) => p.text())).toEqual(['Experiment 3'])
    })

    it('draw no piece for a row that is closed', () => {
      hosted.pieces = [{ name: 'Piece', template: '<div class="piece" />' }]
      expect(make().find('.piece').exists()).toBe(false)
    })
  })
})
