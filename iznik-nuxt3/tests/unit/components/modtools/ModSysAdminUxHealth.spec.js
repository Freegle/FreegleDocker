import { describe, it, expect, beforeEach } from 'vitest'
import { mount } from '@vue/test-utils'
import { setActivePinia, createPinia } from 'pinia'
import ModSysAdminUxHealth from '~/modtools/components/ModSysAdminUxHealth.vue'
import { useUxStore } from '~/modtools/stores/ux'
import { uxStubs } from './uxStubs'

const day = (date, ratio, ok = true) => ({
  date,
  expected: 100,
  observed: ratio * 100,
  ratio,
  ok,
})

const metric = (name, ok = true) => ({
  metric: name,
  ok,
  days: [day('2026-10-01', ok ? 1 : 0.4, ok)],
})

describe('ModSysAdminUxHealth', () => {
  let store
  beforeEach(() => {
    setActivePinia(createPinia())
    store = useUxStore()
  })
  const make = (props = {}) =>
    mount(ModSysAdminUxHealth, {
      props,
      global: { stubs: { ...uxStubs } },
    })

  it('says nothing is wrong when every metric is ok', () => {
    store.health.data = { metrics: [metric('flow_events')] }
    const w = make({ open: true })
    expect(w.find('.notice-message.danger').exists()).toBe(false)
    expect(w.text()).toContain('flow_events')
    expect(w.text()).toContain('OK')
  })

  it('raises a red notice naming the failing metrics and warning about the figures', () => {
    store.health.data = {
      metrics: [
        metric('flow_events'),
        {
          metric: 'impressions',
          ok: false,
          days: [day('2026-10-01', 0.4, false), day('2026-10-02', 1)],
        },
      ],
    }
    const w = make({ open: true })
    const notice = w.find('.notice-message.danger')
    expect(notice.exists()).toBe(true)
    expect(notice.text()).toContain('impressions')
    expect(notice.text()).not.toContain('flow_events')
    expect(notice.text().toLowerCase()).toContain('instrumentation')
  })

  it('counts the days outside the expected range', () => {
    store.health.data = {
      metrics: [
        {
          metric: 'impressions',
          ok: false,
          days: [day('2026-10-01', 0.4, false), day('2026-10-02', 1)],
        },
      ],
    }
    expect(make({ open: true }).text()).toContain('1 of 2 days')
  })

  it('shows the empty message with no metrics, open or closed', () => {
    store.health.data = { metrics: [] }
    expect(make({ open: true }).text()).toContain(
      'No health data for this period.'
    )
    expect(make().find('.ux-summary').text()).toBe(
      'No health data for this period.'
    )
  })

  it('shows the error from the store', () => {
    store.health.error = 'nope'
    expect(make().text()).toContain('nope')
  })

  describe('the closed row', () => {
    it('is closed unless the person opened it', () => {
      store.health.data = { metrics: [metric('flow_events')] }
      const w = make()
      expect(w.find('h4 > button').attributes('aria-expanded')).toBe('false')
      expect(w.text()).not.toContain('Latest ratio')
    })

    it('says all checks are inside the expected range', () => {
      store.health.data = { metrics: [metric('a'), metric('b'), metric('c')] }
      expect(make().find('.ux-summary').text()).toBe(
        'All 3 checks are inside the expected range.'
      )
    })

    it('says so for a single check', () => {
      store.health.data = { metrics: [metric('a')] }
      expect(make().find('.ux-summary').text()).toBe(
        'The check is inside the expected range.'
      )
    })

    it('is held open while a check fails, and says which', () => {
      store.health.data = {
        metrics: [metric('flow_events'), metric('impressions', false)],
      }
      const w = make()
      expect(w.find('h4 > button').attributes('aria-expanded')).toBe('true')
      expect(w.find('.notice-message.danger').exists()).toBe(true)
    })

    it('names up to three failing checks on the closed line', () => {
      store.health.data = {
        metrics: [metric('ok'), metric('a', false), metric('b', false)],
      }
      // Held open, so read the line the panel builds through its summary prop.
      const w = make()
      const panel = w.findComponent({ name: 'ModSysAdminUxPanel' })
      expect(panel.props('summary')).toBe(
        '2 of 3 checks are outside the expected range: a, b.'
      )
    })

    it('names three and counts the rest', () => {
      store.health.data = {
        metrics: ['a', 'b', 'c', 'd', 'e'].map((m) => metric(m, false)),
      }
      const panel = make().findComponent({ name: 'ModSysAdminUxPanel' })
      expect(panel.props('summary')).toBe(
        '5 of 5 checks are outside the expected range: a, b, c and 2 more.'
      )
    })

    it('says "is" for one failing check out of one', () => {
      store.health.data = { metrics: [metric('a', false)] }
      const panel = make().findComponent({ name: 'ModSysAdminUxPanel' })
      expect(panel.props('summary')).toBe(
        '1 of 1 check is outside the expected range: a.'
      )
    })

    it('asks the section to open it', async () => {
      store.health.data = { metrics: [metric('a')] }
      const w = make()
      await w.find('h4 > button').trigger('click')
      expect(w.emitted('toggle')).toHaveLength(1)
    })
  })

  it('says when the server did not use a device filter that was asked for', () => {
    store.health.data = {
      metrics: [metric('a')],
      filters: { device: '', signed_in: '' },
      ignored: ['device'],
    }
    store.held.health = { start: 'a', end: 'b', device: 'mobile' }
    expect(make().find('.ux-note').text()).toBe(
      'Not filtered by device. Shows every device.'
    )
  })

  it('says the part is not switched on when the route is missing', () => {
    store.health.missing = true
    const w = make()
    expect(w.find('.ux-summary').text()).toBe(
      'This part is not switched on yet.'
    )
    expect(w.find('.notice-message').exists()).toBe(false)
  })
})
