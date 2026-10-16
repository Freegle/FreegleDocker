import { describe, it, expect, vi, beforeEach } from 'vitest'

// The email landing page /job/[id] logs the click and then sends the member straight on to the
// advert. Leaving the page cancels an ordinary request, so the log must be sent with keepalive or
// it is lost: from 12 Oct 2026 a sixth of email-page clicks, and seven in ten on iPhone Safari,
// never reached logs_jobs.

vi.mock('~/stores/auth', () => ({
  useAuthStore: () => ({
    auth: { jwt: 'test-jwt', persistent: 'test-persistent' },
    user: { id: 123 },
  }),
}))
vi.mock('~/stores/misc', () => ({
  useMiscStore: () => ({
    modtools: false,
    online: true,
    api: vi.fn(),
    waitForOnline: vi.fn().mockResolvedValue(),
  }),
}))
vi.mock('~/stores/loggingContext', () => ({
  useLoggingContextStore: () => ({ getHeaders: () => ({}) }),
}))
vi.mock('~/composables/useTrace', () => ({ getTraceHeaders: () => ({}) }))
vi.mock('@sentry/vue', () => ({ captureMessage: vi.fn() }))
vi.mock('~/composables/useClientLog', () => ({
  warn: vi.fn(),
  noteApiRequestStarted: vi.fn(),
}))
vi.mock('~/composables/useFlowTelemetry', () => ({
  getLiveFlowInstanceId: () => null,
}))

const mockFetch = vi.fn()
vi.mock('~/composables/useFetchRetry', () => ({
  fetchRetry: () => mockFetch,
}))

describe('JobAPI', () => {
  let JobAPI

  beforeEach(async () => {
    vi.clearAllMocks()
    mockFetch.mockResolvedValue([200, { ret: 0 }])
    JobAPI = (await import('~/api/JobAPI')).default
  })

  function createApi() {
    return new JobAPI({ public: { APIv2: 'https://api.test' } })
  }

  it('sends the click log with keepalive, so leaving for the advert does not cancel it', async () => {
    const api = createApi()

    await api.log({
      id: 42,
      link: 'https://example.com/job/42',
      placement: 'email_redirect',
      source: 'email',
    })

    expect(mockFetch).toHaveBeenCalledTimes(1)
    const [url, init] = mockFetch.mock.calls[0]
    expect(url).toMatch(/^https:\/\/api\.test\/job\?/)
    expect(init.method).toBe('POST')
    expect(init.keepalive).toBe(true)
    expect(JSON.parse(init.body)).toMatchObject({
      id: 42,
      placement: 'email_redirect',
      source: 'email',
    })
  })

  it('leaves keepalive off for an ordinary post', async () => {
    const api = createApi()

    await api.$postv2('/job', { id: 1 })

    expect(mockFetch).toHaveBeenCalledTimes(1)
    expect(mockFetch.mock.calls[0][1].keepalive).toBeUndefined()
  })
})
