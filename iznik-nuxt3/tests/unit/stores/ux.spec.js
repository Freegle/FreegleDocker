import { describe, it, expect, vi, beforeEach } from 'vitest'
import { setActivePinia, createPinia } from 'pinia'

const fetchers = {
  fetchFunnels: vi.fn(),
  fetchOutcomes: vi.fn(),
  fetchFields: vi.fn(),
  fetchFriction: vi.fn(),
  fetchAdoption: vi.fn(),
  fetchRegret: vi.fn(),
  fetchSearch: vi.fn(),
  fetchHealth: vi.fn(),
  fetchSamples: vi.fn(),
  fetchExperiments: vi.fn(),
  createExperiment: vi.fn(),
  experimentAction: vi.fn(),
}

vi.mock('~/api', () => ({
  default: () => ({ ux: fetchers }),
}))

describe('ux store', () => {
  let useUxStore

  beforeEach(async () => {
    vi.clearAllMocks()
    setActivePinia(createPinia())
    useUxStore = (await import('~/modtools/stores/ux')).useUxStore
  })

  it('starts with every panel empty, idle and without error', () => {
    const store = useUxStore()
    for (const k of [
      'health',
      'funnels',
      'outcomes',
      'fields',
      'friction',
      'adoption',
      'regret',
      'search',
      'samples',
      'experiments',
    ]) {
      expect(store[k]).toEqual({ data: null, loading: false, error: null })
    }
  })

  it('stores the response and passes the dates to the API', async () => {
    fetchers.fetchHealth.mockResolvedValue({ metrics: [] })
    const store = useUxStore()
    await store.load('health', { start: '2026-10-01', end: '2026-10-07' })
    expect(fetchers.fetchHealth).toHaveBeenCalledWith({
      start: '2026-10-01',
      end: '2026-10-07',
    })
    expect(store.health.data).toEqual({ metrics: [] })
    expect(store.health.loading).toBe(false)
    expect(store.health.error).toBeNull()
  })

  it('is loading while a request is in flight', async () => {
    let resolve
    fetchers.fetchFunnels.mockReturnValue(new Promise((r) => (resolve = r)))
    const store = useUxStore()
    const p = store.load('funnels', {})
    expect(store.funnels.loading).toBe(true)
    resolve({ flows: [] })
    await p
    expect(store.funnels.loading).toBe(false)
  })

  it('records an error message and clears old data on failure', async () => {
    fetchers.fetchFriction.mockResolvedValueOnce({ components: [1] })
    fetchers.fetchFriction.mockRejectedValueOnce(new Error('boom'))
    const store = useUxStore()
    await store.load('friction', {})
    await store.load('friction', {})
    expect(store.friction.error).toBe('boom')
    expect(store.friction.data).toBeNull()
    expect(store.friction.loading).toBe(false)
  })

  it('falls back to a generic message when the error has none', async () => {
    fetchers.fetchRegret.mockRejectedValue({})
    const store = useUxStore()
    await store.load('regret', {})
    expect(store.regret.error).toBe('Unknown error')
  })

  it('loadAll fetches every panel including experiments, except samples, and one failure does not stop the rest', async () => {
    for (const f of Object.values(fetchers)) f.mockResolvedValue({})
    fetchers.fetchAdoption.mockRejectedValue(new Error('nope'))
    const store = useUxStore()
    await store.loadAll({ start: 'a', end: 'b' })
    for (const name of [
      'fetchHealth',
      'fetchFunnels',
      'fetchOutcomes',
      'fetchFields',
      'fetchFriction',
      'fetchAdoption',
      'fetchRegret',
      'fetchSearch',
      'fetchExperiments',
    ]) {
      expect(fetchers[name]).toHaveBeenCalledWith({ start: 'a', end: 'b' })
    }
    expect(fetchers.fetchSamples).not.toHaveBeenCalled()
    expect(store.experiments.data).toEqual({})
    expect(store.adoption.error).toBe('nope')
    expect(store.search.data).toEqual({})
  })

  it('createExperiment calls the API then reloads the experiments list without dates', async () => {
    fetchers.createExperiment.mockResolvedValue({ id: 3 })
    fetchers.fetchExperiments.mockResolvedValue({ flows: [{ flow: 'post' }] })
    const store = useUxStore()
    const ret = await store.createExperiment({ flow: 'post', name: 'x' })
    expect(fetchers.createExperiment).toHaveBeenCalledWith({
      flow: 'post',
      name: 'x',
    })
    expect(ret).toEqual({ id: 3 })
    expect(fetchers.fetchExperiments).toHaveBeenCalledWith({})
    expect(store.experiments.data).toEqual({ flows: [{ flow: 'post' }] })
  })

  it('experimentAction calls the API then reloads the experiments list', async () => {
    fetchers.experimentAction.mockResolvedValue({ queued: true })
    fetchers.fetchExperiments.mockResolvedValue({ flows: [] })
    const store = useUxStore()
    const ret = await store.experimentAction(9, { action: 'start' })
    expect(fetchers.experimentAction).toHaveBeenCalledWith(9, {
      action: 'start',
    })
    expect(ret).toEqual({ queued: true })
    expect(fetchers.fetchExperiments).toHaveBeenCalledTimes(1)
  })

  it('does not reload when the create call fails', async () => {
    fetchers.createExperiment.mockRejectedValue(new Error('refused'))
    const store = useUxStore()
    await expect(store.createExperiment({})).rejects.toThrow('refused')
    expect(fetchers.fetchExperiments).not.toHaveBeenCalled()
  })

  it('loads samples for a date, flow and step', async () => {
    fetchers.fetchSamples.mockResolvedValue({ sessions: [] })
    const store = useUxStore()
    await store.load('samples', {
      date: '2026-10-07',
      flow: 'post',
      step: 'photos',
    })
    expect(fetchers.fetchSamples).toHaveBeenCalledWith({
      date: '2026-10-07',
      flow: 'post',
      step: 'photos',
    })
    expect(store.samples.data).toEqual({ sessions: [] })
  })
})
