import { describe, it, expect, vi, beforeEach } from 'vitest'

const telemetry = vi.hoisted(() => ({ arm: null, flowArm: vi.fn() }))
vi.mock('~/composables/useFlowTelemetry', () => ({
  FlowName: { REPLY: 'reply' },
  flowArm: (...args) => {
    telemetry.flowArm(...args)
    return telemetry.arm
  },
}))

const {
  offerReplyJobInterstitial,
  replyJobInterstitialRequest,
  recordShown,
  shownRecently,
  REPLY_JOB_INTERSTITIAL_GAP,
} = await import('~/composables/useReplyJobInterstitial')

describe('useReplyJobInterstitial', () => {
  beforeEach(() => {
    localStorage.clear()
    replyJobInterstitialRequest.value = null
    telemetry.arm = null
    telemetry.flowArm.mockClear()
  })

  it('asks for the pop-up after a reply in the variant', () => {
    telemetry.arm = 'b'

    expect(offerReplyJobInterstitial({ expectNavigation: true })).toBe(true)

    expect(telemetry.flowArm).toHaveBeenCalledWith(
      'reply',
      'reply-job-interstitial'
    )
    expect(replyJobInterstitialRequest.value).toMatchObject({
      expectNavigation: true,
    })
  })

  it('asks for nothing in control, but still reads the arm', () => {
    telemetry.arm = 'control'

    expect(offerReplyJobInterstitial({ expectNavigation: false })).toBe(false)

    expect(telemetry.flowArm).toHaveBeenCalled()
    expect(replyJobInterstitialRequest.value).toBe(null)
  })

  it('treats no arm (no experiment running) as control', () => {
    expect(offerReplyJobInterstitial({ expectNavigation: false })).toBe(false)
    expect(replyJobInterstitialRequest.value).toBe(null)
  })

  it('shows it at most once a day on a device', () => {
    telemetry.arm = 'b'
    const now = Date.now()
    recordShown(now)

    expect(shownRecently(now + REPLY_JOB_INTERSTITIAL_GAP - 1000)).toBe(true)
    expect(offerReplyJobInterstitial({ expectNavigation: false })).toBe(false)
    expect(replyJobInterstitialRequest.value).toBe(null)
    // The arm was read anyway, so the member is counted the same way as in control.
    expect(telemetry.flowArm).toHaveBeenCalled()

    expect(shownRecently(now + REPLY_JOB_INTERSTITIAL_GAP + 1000)).toBe(false)
  })

  it('still offers it when storage is blocked', () => {
    telemetry.arm = 'b'
    const get = vi
      .spyOn(Storage.prototype, 'getItem')
      .mockImplementation(() => {
        throw new Error('blocked')
      })

    expect(offerReplyJobInterstitial({ expectNavigation: false })).toBe(true)
    get.mockRestore()
  })
})
