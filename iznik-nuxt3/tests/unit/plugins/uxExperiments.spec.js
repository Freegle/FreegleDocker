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
    const value =
      '{"exp":{"arms":{"control":[0,49],"b":[50,99]},"until":1900000000}}'
    mocks.fetchv2.mockResolvedValue([{ id: 1, key: 'ux_experiments', value }])
    await mod.fetchLiveExperiments({})
    expect(mocks.setLiveExperiments).toHaveBeenCalledWith(value)
  })

  it('asks with error logging off, so a refusal is not a Sentry event', async () => {
    mocks.fetchv2.mockResolvedValue([])
    await mod.fetchLiveExperiments({})
    expect(mocks.fetchv2).toHaveBeenCalledWith('ux_experiments', false)
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

  it('fails quietly when the key is refused before the backend deploy', async () => {
    const err = vi.spyOn(console, 'error').mockImplementation(() => {})
    const warn = vi.spyOn(console, 'warn').mockImplementation(() => {})
    const sentry = { captureMessage: vi.fn(), captureException: vi.fn() }
    globalThis.Sentry = sentry
    mocks.fetchv2.mockRejectedValue(
      Object.assign(new Error('API Error GET /config/ux_experiments 403'), {
        response: { status: 403 },
      })
    )
    await expect(mod.fetchLiveExperiments({})).resolves.toBeUndefined()
    expect(mocks.setLiveExperiments).not.toHaveBeenCalled()
    expect(err).not.toHaveBeenCalled()
    expect(warn).not.toHaveBeenCalled()
    expect(sentry.captureMessage).not.toHaveBeenCalled()
    expect(sentry.captureException).not.toHaveBeenCalled()
    delete globalThis.Sentry
    err.mockRestore()
    warn.mockRestore()
  })

  it('runs the fetch at boot in the browser', async () => {
    mocks.fetchv2.mockResolvedValue([])
    globalThis.__testRuntimeConfig = () => ({ public: {} })
    mod.default()
    await Promise.resolve()
    await Promise.resolve()
    expect(mocks.fetchv2).toHaveBeenCalledWith('ux_experiments', false)
    delete globalThis.__testRuntimeConfig
  })
})
