import { describe, it, expect } from 'vitest'
import {
  funnelsFor,
  headline,
  flowTitle,
  stepLabel,
  familyLabel,
  deviceLabel,
} from '~/modtools/composables/useUxFunnel'

const s = (step, over = {}) => ({
  step,
  entered: 100,
  reentered: 0,
  visits: 100,
  completed: 60,
  backed: 5,
  abandoned: 20,
  ...over,
})

describe('useUxFunnel', () => {
  describe('funnelsFor', () => {
    it('splits give into desktop and mobile funnels in route order', () => {
      const out = funnelsFor('give', [
        s('give-whoami', { entered: 200 }),
        s('give', { entered: 300 }),
        s('give-mobile-whereami', { entered: 150 }),
        s('give-mobile-photos', { entered: 320 }),
        s('give-whereami', { entered: 250 }),
        s('give-mobile-details', { entered: 220 }),
      ])
      expect(out.map((f) => f.family)).toEqual(['desktop', 'mobile'])
      expect(out[0].steps.map((x) => x.step)).toEqual([
        'give',
        'give-whereami',
        'give-whoami',
      ])
      expect(out[1].steps.map((x) => x.step)).toEqual([
        'give-mobile-photos',
        'give-mobile-details',
        'give-mobile-whereami',
      ])
    })

    it('measures reach against the first step and loss against visits', () => {
      const [desk] = funnelsFor('give', [
        s('give', { entered: 300, visits: 310, abandoned: 93 }),
        s('give-whereami', { entered: 150, visits: 160, abandoned: 16 }),
      ])
      expect(desk.steps[0].reach).toBe(1)
      expect(desk.steps[1].reach).toBe(0.5)
      expect(desk.steps[0].lossRate).toBeCloseTo(0.3)
      expect(desk.steps[1].lossRate).toBeCloseTo(0.1)
      expect(desk.steps[0].label).toBe('Item and photos')
    })

    it('orders state-driven flows as the states happen and leaves out empty steps', () => {
      const [only] = funnelsFor('reply', [
        s('COMPLETED', { entered: 50 }),
        s('COMPOSING', { entered: 90 }),
        s('JOINING_GROUP', { entered: 100 }),
        s('AUTHENTICATING', { entered: 0, visits: 0 }),
      ])
      expect(only.family).toBe('')
      expect(only.steps.map((x) => x.step)).toEqual([
        'JOINING_GROUP',
        'COMPOSING',
        'COMPLETED',
      ])
    })

    it('never lets reach exceed one when a later step has more entries', () => {
      const [f] = funnelsFor('login', [
        s('signup', { entered: 10 }),
        s('signin', { entered: 40 }),
      ])
      expect(f.steps[1].reach).toBe(1)
    })

    it('puts unknown steps last', () => {
      const [f] = funnelsFor('reply', [
        s('MYSTERY', { entered: 999 }),
        s('COMPOSING', { entered: 1 }),
      ])
      expect(f.steps.map((x) => x.step)).toEqual(['COMPOSING', 'MYSTERY'])
      expect(stepLabel('MYSTERY')).toBe('MYSTERY')
    })

    it('handles no steps', () => {
      expect(funnelsFor('give', [])).toEqual([])
      expect(funnelsFor('give', undefined)).toEqual([])
    })
  })

  describe('headline', () => {
    const outcome = { started: 822, completed: 393 }
    const steps = [
      s('give-mobile-photos', {
        visits: 311,
        abandoned: 97,
        by: {
          device_class: [
            { key: 'mobile', visits: 280, abandoned: 90 },
            { key: 'tablet', visits: 31, abandoned: 7 },
          ],
        },
      }),
      s('give-whereami', { visits: 210, abandoned: 2 }),
      s('tiny', { visits: 5, abandoned: 5 }),
    ]

    it('names the flow, the figures, the previous period and the worst step with its worst device', () => {
      const t = headline('give', outcome, steps, {
        started: 800,
        completed: 360,
      })
      expect(t).toBe(
        'Offering something: 822 started, 393 finished (48%, before that 45%). Biggest loss: 31% of the 311 visits to Photos ended there, worst on mobile web (32%).'
      )
    })

    it('ignores steps with too few visits when picking the worst', () => {
      const t = headline('give', outcome, steps, null)
      expect(t).not.toContain('tiny')
    })

    it('says so when nobody started', () => {
      expect(headline('ask', { started: 0, completed: 0 }, steps, null)).toBe(
        'Asking for something: nobody started in this period.'
      )
      expect(headline('ask', undefined, steps, null)).toContain(
        'nobody started'
      )
    })

    it('says so when no step lost many people', () => {
      const t = headline(
        'reply',
        { started: 100, completed: 90 },
        [s('COMPOSING', { abandoned: 0 })],
        null
      )
      expect(t).toContain('No step lost many people.')
    })

    it('leaves the device out when only one device has enough visits', () => {
      const t = headline(
        'give',
        outcome,
        [
          s('give', {
            visits: 100,
            abandoned: 30,
            by: {
              device_class: [{ key: 'desktop', visits: 100, abandoned: 30 }],
            },
          }),
        ],
        null
      )
      expect(t).toContain('ended there.')
      expect(t).not.toContain('worst on')
    })
  })

  it('labels flows, families and devices for people', () => {
    expect(flowTitle('chat_promise')).toBe('Promising an item')
    expect(flowTitle('other')).toBe('other')
    expect(familyLabel('mobile')).toBe('Mobile pages')
    expect(familyLabel('')).toBe('')
    expect(deviceLabel('app-ios')).toBe('the iOS app')
    expect(deviceLabel('weird')).toBe('weird')
  })
})
