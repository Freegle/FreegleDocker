import { describe, it, expect, vi, beforeEach } from 'vitest'

const mocks = vi.hoisted(() => ({
  fetchv2: vi.fn(),
  setLiveExperiments: vi.fn(),
}))

vi.mock('~/api', () => ({
  default: () => ({ config: { fetchv2: mocks.fetchv2 } }),
}))
vi.mock('~/composables/useExperiment', () => ({
  setLiveExperiments: mocks.setLiveExperiments,
  LIVE_STORAGE_KEY: 'freegle_ux_experiments',
}))

describe('plugins/uxExperiments.client', () => {
  let mod

  beforeEach(async () => {
    vi.clearAllMocks()
    vi.resetModules()
    mod = await import('~/plugins/uxExperiments.client.js')
  })

  it('installs the value of the ux_experiments config key', async () => {
    mocks.fetchv2.mockResolvedValue([
      { id: 1, key: 'ux_experiments', value: '{"exp":{"control":[0,49],"b":[50,99]}}' },
    ])
    await mod.fetchLiveExperiments({})
    expect(mocks.fetchv2).toHaveBeenCalledWith('ux_experiments')
    expect(mocks.setLiveExperiments).toHaveBeenCalledWith(
      '{"exp":{"control":[0,49],"b":[50,99]}}'
    )
  })

  it('installs no experiments when the key does not exist', async () => {
    mocks.fetchv2.mockResolvedValue([])
    await mod.fetchLiveExperiments({})
    expect(mocks.setLiveExperiments).toHaveBeenCalledWith('{}')
  })

  it('leaves the stored copy alone when the API fails', async () => {
    mocks.fetchv2.mockRejectedValue(new Error('down'))
    await expect(mod.fetchLiveExperiments({})).resolves.toBeUndefined()
    expect(mocks.setLiveExperiments).not.toHaveBeenCalled()
  })

  it('runs the fetch at boot in the browser', async () => {
    mocks.fetchv2.mockResolvedValue([])
    globalThis.__testRuntimeConfig = () => ({ public: {} })
    mod.default()
    await Promise.resolve()
    await Promise.resolve()
    expect(mocks.fetchv2).toHaveBeenCalledWith('ux_experiments')
    delete globalThis.__testRuntimeConfig
  })
})
