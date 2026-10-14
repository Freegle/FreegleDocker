import { describe, it, expect, vi } from 'vitest'
import UxAPI from '~/api/UxAPI'

describe('UxAPI', () => {
  const routes = {
    fetchFunnels: '/modtools/ux/funnels',
    fetchOutcomes: '/modtools/ux/outcomes',
    fetchFields: '/modtools/ux/fields',
    fetchFriction: '/modtools/ux/friction',
    fetchAdoption: '/modtools/ux/adoption',
    fetchRegret: '/modtools/ux/regret',
    fetchSearch: '/modtools/ux/search',
    fetchHealth: '/modtools/ux/health',
    fetchSamples: '/modtools/ux/samples',
    fetchExperiments: '/modtools/ux/experiments',
  }

  for (const [method, path] of Object.entries(routes)) {
    it(`${method} calls ${path} on the v2 API with its params`, async () => {
      const api = new UxAPI({ public: {} })
      api.$getv2 = vi.fn().mockResolvedValue({ ok: true })
      const params = { start: '2026-10-01', end: '2026-10-07' }
      expect(await api[method](params)).toEqual({ ok: true })
      expect(api.$getv2).toHaveBeenCalledWith(path, params)
    })
  }

  it('createExperiment POSTs the draft to the experiments route', async () => {
    const api = new UxAPI({ public: {} })
    api.$postv2 = vi.fn().mockResolvedValue({ id: 5 })
    const draft = { flow: 'post', name: 'shorter-form' }
    expect(await api.createExperiment(draft)).toEqual({ id: 5 })
    expect(api.$postv2).toHaveBeenCalledWith('/modtools/ux/experiments', draft)
  })

  it('experimentAction PATCHes the action into the request body', async () => {
    const api = new UxAPI({ public: {} })
    api.$requestv2 = vi.fn().mockResolvedValue({ queued: true })
    const data = { action: 'stop', reason: 'no effect' }
    expect(await api.experimentAction(7, data)).toEqual({ queued: true })
    // The base client sends config.params as the body of a PATCH, so the action must travel there
    // and not as the config itself, where it would be dropped and the server would see no action.
    const [method, path, config] = api.$requestv2.mock.calls[0]
    expect(method).toBe('PATCH')
    expect(path).toBe('/modtools/ux/experiments/7')
    expect(config.params).toEqual(data)
  })
})
