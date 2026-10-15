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
    ]) {
      expect(store[k]).toEqual({
        data: null,
        loading: false,
        error: null,
        missing: false,
      })
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

  describe('loadSlots', () => {
    const params = { start: '2026-10-01', end: '2026-10-07' }

    it('loads only the slots it is given, with the params', async () => {
      for (const f of Object.values(fetchers)) f.mockResolvedValue({})
      const store = useUxStore()
      await store.loadSlots(['health', 'funnels'], params)
      expect(fetchers.fetchHealth).toHaveBeenCalledWith(params)
      expect(fetchers.fetchFunnels).toHaveBeenCalledWith(params)
      expect(fetchers.fetchOutcomes).not.toHaveBeenCalled()
      expect(fetchers.fetchFriction).not.toHaveBeenCalled()
    })

    it('never loads samples, which are opened on demand', async () => {
      for (const f of Object.values(fetchers)) f.mockResolvedValue({})
      const store = useUxStore()
      await store.loadSlots(['samples', 'health', 'nothing'], params)
      expect(fetchers.fetchSamples).not.toHaveBeenCalled()
      expect(fetchers.fetchHealth).toHaveBeenCalledTimes(1)
    })

    it('skips a slot that already holds the same params', async () => {
      for (const f of Object.values(fetchers)) f.mockResolvedValue({})
      const store = useUxStore()
      await store.loadSlots(['health', 'funnels'], params)
      await store.loadSlots(['health', 'funnels', 'outcomes'], { ...params })
      expect(fetchers.fetchHealth).toHaveBeenCalledTimes(1)
      expect(fetchers.fetchFunnels).toHaveBeenCalledTimes(1)
      expect(fetchers.fetchOutcomes).toHaveBeenCalledTimes(1)
    })

    it('treats the same params in another order as the same', async () => {
      for (const f of Object.values(fetchers)) f.mockResolvedValue({})
      const store = useUxStore()
      await store.loadSlots(['health'], { start: 'a', end: 'b' })
      await store.loadSlots(['health'], { end: 'b', start: 'a' })
      expect(fetchers.fetchHealth).toHaveBeenCalledTimes(1)
    })

    it('loads again when a filter changes', async () => {
      for (const f of Object.values(fetchers)) f.mockResolvedValue({})
      const store = useUxStore()
      await store.loadSlots(['funnels'], params)
      await store.loadSlots(['funnels'], { ...params, device: 'mobile' })
      await store.loadSlots(['funnels'], { ...params, device: 'mobile', signed_in: '1' })
      expect(fetchers.fetchFunnels).toHaveBeenCalledTimes(3)
      expect(fetchers.fetchFunnels).toHaveBeenLastCalledWith({
        ...params,
        device: 'mobile',
        signed_in: '1',
      })
    })

    it('loads again after forget(), as a refresh', async () => {
      for (const f of Object.values(fetchers)) f.mockResolvedValue({})
      const store = useUxStore()
      await store.loadSlots(['health'], params)
      store.forget()
      await store.loadSlots(['health'], params)
      expect(fetchers.fetchHealth).toHaveBeenCalledTimes(2)
    })

    it('does not stop the others when one fails', async () => {
      for (const f of Object.values(fetchers)) f.mockResolvedValue({})
      fetchers.fetchAdoption.mockRejectedValue(new Error('nope'))
      const store = useUxStore()
      await store.loadSlots(['health', 'adoption', 'search'], params)
      expect(store.adoption.error).toBe('nope')
      expect(store.health.data).toEqual({})
      expect(store.search.data).toEqual({})
    })
  })

  describe('requested', () => {
    it('says what a slot was last asked with, and nothing for one never asked', async () => {
      fetchers.fetchFunnels.mockResolvedValue({})
      const store = useUxStore()
      expect(store.requested('funnels')).toEqual({})
      await store.load('funnels', { start: 'a', end: 'b', device: 'app' })
      expect(store.requested('funnels')).toEqual({
        start: 'a',
        end: 'b',
        device: 'app',
      })
    })

    it('does not hand out the object the store keeps', async () => {
      fetchers.fetchFunnels.mockResolvedValue({})
      const store = useUxStore()
      const asked = { start: 'a' }
      await store.load('funnels', asked)
      asked.start = 'changed'
      expect(store.requested('funnels')).toEqual({ start: 'a' })
    })

    it('is empty again after forget()', async () => {
      fetchers.fetchFunnels.mockResolvedValue({})
      const store = useUxStore()
      await store.load('funnels', { start: 'a' })
      store.forget()
      expect(store.requested('funnels')).toEqual({})
    })
  })

  it('a route the server does not have is "missing", not an error', async () => {
    fetchers.fetchSearch.mockRejectedValue(
      Object.assign(new Error('nope'), { response: { status: 404 } })
    )
    const store = useUxStore()
    await store.load('search', {})
    expect(store.search.missing).toBe(true)
    expect(store.search.error).toBeNull()
  })

  it('keeps the newest answer when a slower earlier one arrives last', async () => {
    let first
    fetchers.fetchFunnels.mockReturnValueOnce(new Promise((r) => (first = r)))
    fetchers.fetchFunnels.mockResolvedValueOnce({ flows: ['new'] })
    const store = useUxStore()
    const a = store.load('funnels', { device: 'app' })
    await store.load('funnels', { device: 'web' })
    first({ flows: ['old'] })
    await a
    expect(store.funnels.data).toEqual({ flows: ['new'] })
    expect(store.requested('funnels')).toEqual({ device: 'web' })
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
