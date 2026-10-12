import { describe, it, expect, beforeEach } from 'vitest'
import { mount } from '@vue/test-utils'
import { setActivePinia, createPinia } from 'pinia'
import ModSysAdminUxHealth from '~/modtools/components/ModSysAdminUxHealth.vue'
import ModSysAdminUxPanel from '~/modtools/components/ModSysAdminUxPanel.vue'
import { useUxStore } from '~/modtools/stores/ux'
import { uxStubs } from './uxStubs'

const day = (date, ratio, ok = true) => ({
  date,
  expected: 100,
  observed: ratio * 100,
  ratio,
  ok,
})

describe('ModSysAdminUxHealth', () => {
  let store
  beforeEach(() => {
    setActivePinia(createPinia())
    store = useUxStore()
  })
  const make = () =>
    mount(ModSysAdminUxHealth, {
      global: { stubs: { ...uxStubs }, components: { ModSysAdminUxPanel } },
    })

  it('says nothing is wrong when every metric is ok', () => {
    store.health.data = {
      metrics: [{ metric: 'flow_events', ok: true, days: [day('2026-10-01', 1)] }],
    }
    const w = make()
    expect(w.find('.notice-message.danger').exists()).toBe(false)
    expect(w.text()).toContain('flow_events')
    expect(w.text()).toContain('OK')
  })

  it('raises a red notice naming the failing metrics and warning about the figures', () => {
    store.health.data = {
      metrics: [
        { metric: 'flow_events', ok: true, days: [day('2026-10-01', 1)] },
        {
          metric: 'impressions',
          ok: false,
          days: [day('2026-10-01', 0.4, false), day('2026-10-02', 1)],
        },
      ],
    }
    const w = make()
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
    expect(make().text()).toContain('1 of 2 days')
  })

  it('shows the empty message with no metrics', () => {
    store.health.data = { metrics: [] }
    expect(make().text()).toContain('No health data')
  })

  it('shows the error from the store', () => {
    store.health.error = 'nope'
    expect(make().text()).toContain('nope')
  })
})
