import { describe, it, expect, beforeEach, afterEach, vi } from 'vitest'
import { mount, flushPromises } from '@vue/test-utils'
import { setActivePinia, createPinia } from 'pinia'
import ModSysAdminUxExperiments from '~/modtools/components/ModSysAdminUxExperiments.vue'
import { useUxStore } from '~/modtools/stores/ux'
import { uxStubs } from './uxStubs'

const running = {
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
    summary: 'After 6.5 hours: 210 started with the change and 200 without.',
  },
  reaction: {
    better: 3,
    worse: 1,
    dont_care: 2,
    dont_understand: 0,
    total: 6,
    comments: ['Looks cleaner', 'I do not like it'],
  },
}

const adopted = {
  id: 2,
  flow: 'give',
  title: 'Shorter first screen',
  change: 'Postcode moved after photos.',
  status: 'adopted',
  started_at: '2026-10-10 09:00:00',
  decided_at: '2026-10-11 09:00:00',
  decision:
    'Adopted at the end: the change was probably better. 400 started with the change and 400 without.',
  cleanup_overdue: true,
  pr_url: 'https://github.com/Freegle/Iznik/pull/1790',
  reaction: {
    better: 1,
    worse: 4,
    dont_care: 0,
    dont_understand: 1,
    total: 6,
    comments: ['Hated it'],
  },
}

const draft = {
  id: 5,
  flow: 'give',
  title: 'Say photos get more replies',
  change: 'A line under the camera button.',
  status: 'draft',
  reaction: { total: 0 },
}

const flowData = {
  flow: 'give',
  running: [running],
  history: [adopted, draft],
  ideas: [
    {
      id: 1,
      title: 'Ask to sign in first',
      rationale: 'Most mobile starts are logged out.',
      expected: 'Fewer dead starts.',
      score: 8.2,
      source: 'ai',
    },
    {
      id: 2,
      title: 'Example description',
      rationale: 'Details step backs.',
      expected: '+5 points.',
      score: 6.1,
      source: 'survey',
    },
  ],
}

// The shared table stub prints plain values; this component fills the result and actions cells
// through slots (the cleanup warning and the Start and Close buttons), so the stub must render them.
const tableWithCellSlots = {
  template:
    '<table class="table"><tr v-for="(item, i) in items" :key="i"><td v-for="f in fields" :key="f.key"><slot :name="\'cell(\' + f.key + \')\'" :item="item">{{ item[f.key] }}</slot></td></tr></table>',
  props: ['items', 'fields'],
}

describe('ModSysAdminUxExperiments', () => {
  let store
  beforeEach(() => {
    setActivePinia(createPinia())
    store = useUxStore()
    store.experimentAction = vi.fn().mockResolvedValue({ queued: true })
  })

  const make = (props = {}) =>
    mount(ModSysAdminUxExperiments, {
      props: { flowData, full: false, ...props },
      global: { stubs: { ...uxStubs, 'b-table': tableWithCellSlots } },
    })

  it('tells the story: running with its latest read, history with outcomes, ideas left', () => {
    const w = make()
    const t = w.text()
    // The draft has not started, so it is prepared, not finished.
    expect(t).toContain('1 running, 1 finished, 1 prepared, 2 ideas left')
    expect(t).toContain('Make skipping photos obvious')
    expect(t).toContain('Running 6.5 hours')
    expect(t).toContain('After 6.5 hours: 210 started')
    expect(t).toContain(
      'Of the 6 who answered: 3 said better, 1 worse, 2 said it makes no difference to them and 0 were not sure what it is for.'
    )
    expect(t).toContain('Kept')
    expect(t).toContain('Adopted at the end: the change was probably better.')
    expect(t).toContain('cleanup overdue')
    expect(t).toContain('Other ideas, best first')
    expect(t).toContain('Not started')
    expect(t).toContain('Ask to sign in first')
    expect(t).toContain('score 8.2, suggested by the loop')
    expect(t).toContain('from the survey')
  })

  it('shows minutes for a young experiment and a note before the first read', () => {
    const w = make({
      flowData: {
        ...flowData,
        running: [
          { ...running, hours: 0.25, latest_read: null, reaction: null },
        ],
      },
    })
    expect(w.text()).toContain('Running 15 minutes')
    expect(w.text()).toContain('No read yet')
    expect(w.text()).toContain('no answers yet')
  })

  describe('stopping', () => {
    it('asks first, and does nothing until the answer is yes', async () => {
      const w = make()
      await w.find('button.stop').trigger('click')
      expect(store.experimentAction).not.toHaveBeenCalled()
      expect(w.text()).toContain('Stop "Make skipping photos obvious" now?')
      await w.find('button.cancel').trigger('click')
      expect(store.experimentAction).not.toHaveBeenCalled()
      expect(w.find('button.stop').exists()).toBe(true)
    })

    it('stops through the store on yes, and says so on the card', async () => {
      const w = make()
      await w.find('button.stop').trigger('click')
      await w.find('button.confirm-stop').trigger('click')
      await flushPromises()
      expect(store.experimentAction).toHaveBeenCalledWith(3, { action: 'stop' })
      expect(w.text()).toContain('Stop requested')
      expect(w.text()).toContain('this card changes when it has')
    })

    it('keeps the button disabled after the request, so a second click cannot queue another', async () => {
      const w = make()
      await w.find('button.stop').trigger('click')
      await w.find('button.confirm-stop').trigger('click')
      await flushPromises()
      const stop = w.find('button.stop')
      expect(stop.attributes('disabled')).toBeDefined()
      await stop.trigger('click')
      expect(store.experimentAction).toHaveBeenCalledTimes(1)
    })

    it('shows a failure on the card that was clicked', async () => {
      store.experimentAction.mockRejectedValueOnce(new Error('cap reached'))
      const w = make()
      await w.find('button.stop').trigger('click')
      await w.find('button.confirm-stop').trigger('click')
      await flushPromises()
      expect(w.find('.experiment.running').text()).toContain('cap reached')
      // Nothing is marked requested, so it can be tried again.
      expect(w.text()).not.toContain('Stop requested')
    })
  })

  it('starts a draft, unless the cap is full, and then shows it as requested', async () => {
    const w = make()
    await w.find('button.start').trigger('click')
    await flushPromises()
    expect(store.experimentAction).toHaveBeenCalledWith(5, { action: 'start' })
    expect(w.text()).toContain('Start requested')
    expect(w.find('button.start').exists()).toBe(false)

    const fullW = make({ full: true })
    expect(fullW.find('button.start').attributes('disabled')).toBeDefined()
  })

  it('labels the close button as an action: mark as cleaned up', async () => {
    const w = make()
    const close = w.find('button.close')
    expect(close.text()).toBe('Mark as cleaned up')
    await close.trigger('click')
    await flushPromises()
    expect(store.experimentAction).toHaveBeenCalledWith(2, { action: 'close' })
    expect(w.text()).toContain('Cleanup noted')
  })

  it('lets go of a request once the experiment has a new status', async () => {
    const w = make()
    await w.find('button.start').trigger('click')
    await flushPromises()
    expect(w.text()).toContain('Start requested')
    // The batch started it: the same id is now running, not a draft.
    await w.setProps({
      flowData: {
        ...flowData,
        running: [
          ...flowData.running,
          { ...draft, status: 'running', hours: 0.1, reaction: null },
        ],
        history: [adopted],
      },
    })
    expect(w.text()).not.toContain('Start requested')
  })

  describe('when the batch refuses', () => {
    const refusal = {
      action: 'start',
      at: '2026-10-14T10:00:05+00:00',
      message:
        'Experiment give-photos-skip is already running on the give flow; only one at a time per flow',
    }
    const withDraft = (over) => ({
      flowData: { ...flowData, history: [adopted, { ...draft, ...over }] },
    })

    it('shows why, and gives the button back, instead of staying on requested', async () => {
      const w = make()
      await w.find('button.start').trigger('click')
      await flushPromises()
      expect(w.text()).toContain('Start requested')

      await w.setProps(withDraft({ result: { last_refusal: refusal } }))

      expect(w.text()).toContain(
        'That was not done: Experiment give-photos-skip is already running on the give flow'
      )
      expect(w.text()).not.toContain('Start requested')
      expect(w.find('button.start').exists()).toBe(true)
    })

    it('does not take an older refusal for the answer to a new click', async () => {
      const w = make(withDraft({ result: { last_refusal: refusal } }))
      expect(w.text()).toContain('That was not done:')
      await w.find('button.start').trigger('click')
      await flushPromises()
      expect(w.text()).toContain('Start requested')
      expect(w.text()).not.toContain('That was not done:')

      await w.setProps(
        withDraft({
          result: {
            last_refusal: { ...refusal, at: '2026-10-14T11:00:00+00:00' },
          },
        })
      )
      expect(w.text()).toContain('That was not done:')
      expect(w.text()).not.toContain('Start requested')
    })
  })

  describe('asking again while a request is waiting', () => {
    beforeEach(() => {
      vi.useFakeTimers()
      store.load = vi.fn().mockResolvedValue(undefined)
    })
    afterEach(() => {
      vi.useRealTimers()
    })

    it('reloads the experiments after a click, so the card can change by itself', async () => {
      const w = make()
      await w.find('button.start').trigger('click')
      await flushPromises()
      expect(store.load).not.toHaveBeenCalled()

      await vi.advanceTimersByTimeAsync(5000)
      expect(store.load).toHaveBeenCalledWith('experiments')

      // Still waiting, so it asks again later.
      await vi.advanceTimersByTimeAsync(10000)
      expect(store.load).toHaveBeenCalledTimes(2)
      w.unmount()
    })

    it('stops asking once nothing is waiting', async () => {
      const w = make()
      await w.find('button.start').trigger('click')
      await flushPromises()
      await w.setProps({
        flowData: {
          ...flowData,
          running: [
            ...flowData.running,
            { ...draft, status: 'running', hours: 0.1, reaction: null },
          ],
          history: [adopted],
        },
      })
      await vi.advanceTimersByTimeAsync(5000)
      expect(store.load).toHaveBeenCalledTimes(1)
      await vi.advanceTimersByTimeAsync(300000)
      expect(store.load).toHaveBeenCalledTimes(1)
      w.unmount()
    })
  })

  describe('an adopted experiment inside its holdout', () => {
    const inHoldout = {
      ...adopted,
      holdout_until: '2026-10-25 09:00:00',
      result: {
        holdout_check: {
          at: '2026-10-12T09:00:00+00:00',
          days: 1,
          p_better: 0.62,
          strikes: 0,
        },
      },
    }
    const makeHeld = () =>
      make({ flowData: { ...flowData, history: [inHoldout, draft] } })

    it('is kept for now, not kept, and says the holdout and the last look', () => {
      const t = makeHeld().text()
      expect(t).toContain('Kept for now')
      expect(t).toContain(
        'Some members still have the old version until 2026-10-25'
      )
      expect(t).toContain('chance the change is better 62%')
      expect(t).toContain('0 against so far')
    })

    it('says before the first look that there has not been one', () => {
      const w = make({
        flowData: {
          ...flowData,
          history: [{ ...inHoldout, result: null }, draft],
        },
      })
      expect(w.text()).toContain('The first look is a day after it was kept.')
    })

    it('warns on the cleanup button that it ends the holdout', () => {
      expect(makeHeld().find('button.close').attributes('title')).toContain(
        'ends the holdout'
      )
    })

    it('is plainly kept once the holdout is over', () => {
      const t = make().text()
      expect(t).toContain('Kept')
      expect(t).not.toContain('Kept for now')
      expect(t).not.toContain('Some members still have the old version')
    })
  })

  it('names a member as the source of a suggestion', () => {
    const w = make({
      flowData: {
        ...flowData,
        ideas: [{ ...flowData.ideas[0], source: 'member' }],
      },
    })
    expect(w.text()).toContain('suggested by a member')
  })

  describe('what members said', () => {
    it('is behind a toggle, with the count, and shown escaped', async () => {
      const w = make()
      expect(w.text()).not.toContain('Looks cleaner')
      const toggle = w.find('.experiment.running .said .toggle')
      expect(toggle.text()).toContain('What members said about this (2)')
      await toggle.trigger('click')
      expect(w.text()).toContain('Looks cleaner')
      expect(w.text()).toContain('I do not like it')
    })

    it('is shown for a finished experiment too', async () => {
      const w = make()
      const toggles = w.findAll('.said .toggle')
      expect(toggles.some((t) => t.text().includes('(1)'))).toBe(true)
    })
  })

  it('shows the error when starting fails, next to the button', async () => {
    store.experimentAction.mockRejectedValueOnce(new Error('cap reached'))
    const w = make()
    await w.find('button.start').trigger('click')
    await flushPromises()
    expect(w.text()).toContain('cap reached')
    expect(w.find('button.start').exists()).toBe(true)
  })

  it('a funnel with only a draft says nothing has been tried, not that something finished', () => {
    const w = make({
      flowData: { flow: 'ask', running: [], history: [draft], ideas: [] },
    })
    expect(w.text()).toContain('0 running, 0 finished, 1 prepared')
    expect(w.text()).toContain('Nothing has been tried on this funnel yet.')
  })

  it('shows an idea under its problem there, and not again here', () => {
    const w = make({
      flowData: {
        ...flowData,
        problems: [{ id: 4 }],
        ideas: [
          { ...flowData.ideas[0], problem_id: 4 },
          { ...flowData.ideas[1], problem_id: 99 },
        ],
      },
    })
    const t = w.text()
    expect(t).not.toContain('Ask to sign in first')
    // Its problem is not shown on this funnel, so the idea is not dropped.
    expect(t).toContain('Example description')
  })

  it('says when nothing has been tried and no ideas wait', () => {
    const w = make({
      flowData: { flow: 'ask', running: [], history: [], ideas: [] },
    })
    expect(w.text()).toContain('Nothing has been tried on this funnel yet.')
    expect(w.text()).toContain('No ideas waiting')
  })
})
