import { describe, it, expect, beforeEach, vi } from 'vitest'
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
  latest_read: { hours: 6.5, summary: 'After 6.5 hours: 210 started with the change and 200 without.' },
  reaction: { better: 3, worse: 1, dont_care: 2, dont_understand: 0, total: 6, comments: [] },
}

const adopted = {
  id: 2,
  flow: 'give',
  title: 'Shorter first screen',
  change: 'Postcode moved after photos.',
  status: 'adopted',
  started_at: '2026-10-10 09:00:00',
  decided_at: '2026-10-11 09:00:00',
  decision: 'Adopted at the end: the change was probably better. 400 started with the change and 400 without.',
  cleanup_overdue: true,
  pr_url: 'https://github.com/Freegle/Iznik/pull/1790',
  reaction: { better: 1, worse: 4, dont_care: 0, dont_understand: 1, total: 6, comments: ['Hated it'] },
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
    { id: 1, title: 'Ask to sign in first', rationale: 'Most mobile starts are logged out.', expected: 'Fewer dead starts.', score: 8.2, source: 'ai' },
    { id: 2, title: 'Example description', rationale: 'Details step backs.', expected: '+5 points.', score: 6.1, source: 'survey' },
  ],
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
      global: { stubs: uxStubs },
    })

  it('tells the story: running with its latest read, history with outcomes, ideas left', () => {
    const w = make()
    const t = w.text()
    expect(t).toContain('1 running, 2 finished, 2 ideas left')
    expect(t).toContain('Make skipping photos obvious')
    expect(t).toContain('Running 6.5 hours')
    expect(t).toContain('After 6.5 hours: 210 started')
    expect(t).toContain("Members asked (6): 3 better, 1 worse, 2 don't care, 0 didn't understand.")
    expect(t).toContain('Kept')
    expect(t).toContain('Adopted at the end: the change was probably better.')
    expect(t).toContain('cleanup overdue')
    expect(t).toContain('Not started')
    expect(t).toContain('Ask to sign in first')
    expect(t).toContain('score 8.2, suggested by the loop')
    expect(t).toContain('from the survey')
  })

  it('shows minutes for a young experiment and a note before the first read', () => {
    const w = make({
      flowData: { ...flowData, running: [{ ...running, hours: 0.25, latest_read: null, reaction: null }] },
    })
    expect(w.text()).toContain('Running 15 minutes')
    expect(w.text()).toContain('No read yet')
    expect(w.text()).toContain('no answers yet')
  })

  it('stops a running experiment through the store and says it was queued', async () => {
    const w = make()
    await w.find('button.stop').trigger('click')
    await flushPromises()
    expect(store.experimentAction).toHaveBeenCalledWith(3, { action: 'stop' })
    expect(w.text()).toContain('Stop requested')
  })

  it('starts a draft, unless the cap is full', async () => {
    const w = make()
    await w.find('button.start').trigger('click')
    await flushPromises()
    expect(store.experimentAction).toHaveBeenCalledWith(5, { action: 'start' })

    const fullW = make({ full: true })
    expect(fullW.find('button.start').attributes('disabled')).toBeDefined()
  })

  it('closes an adopted experiment once its code is cleaned up', async () => {
    const w = make()
    await w.find('button.close').trigger('click')
    await flushPromises()
    expect(store.experimentAction).toHaveBeenCalledWith(2, { action: 'close' })
  })

  it('shows the error when an action fails', async () => {
    store.experimentAction.mockRejectedValueOnce(new Error('cap reached'))
    const w = make()
    await w.find('button.stop').trigger('click')
    await flushPromises()
    expect(w.text()).toContain('cap reached')
  })

  it('says when nothing has been tried and no ideas wait', () => {
    const w = make({ flowData: { flow: 'ask', running: [], history: [], ideas: [] } })
    expect(w.text()).toContain('Nothing has been tried on this funnel yet.')
    expect(w.text()).toContain('No ideas waiting')
  })
})
