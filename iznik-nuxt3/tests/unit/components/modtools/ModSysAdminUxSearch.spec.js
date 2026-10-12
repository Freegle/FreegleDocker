import { describe, it, expect, beforeEach } from 'vitest'
import { mount } from '@vue/test-utils'
import { setActivePinia, createPinia } from 'pinia'
import ModSysAdminUxSearch from '~/modtools/components/ModSysAdminUxSearch.vue'
import { useUxStore } from '~/modtools/stores/ux'
import { uxStubs } from './uxStubs'

const days = [
  { date: '2026-10-01', searches: 100, zero_result: 20, re_searched_60s: 10, browse_sessions: 200, browse_scrolled_out: 50 },
  { date: '2026-10-02', searches: 100, zero_result: 30, re_searched_60s: 20, browse_sessions: 200, browse_scrolled_out: 70 },
]

describe('ModSysAdminUxSearch', () => {
  let store
  beforeEach(() => {
    setActivePinia(createPinia())
    store = useUxStore()
    store.search.data = { days }
  })
  const make = () => mount(ModSysAdminUxSearch, { global: { stubs: uxStubs } })

  it('summarises the period with percentages and counts', () => {
    const t = make().text()
    expect(t).toContain('25.0% (50/200)') // zero results
    expect(t).toContain('15.0% (30/200)') // searched again within a minute
    expect(t).toContain('30.0% (120/400)') // browse scrolled out
  })

  it('lists each day with its own percentages', () => {
    const t = make().text()
    expect(t).toContain('2026-10-01')
    expect(t).toContain('20.0% (20/100)')
    expect(t).toContain('30.0% (30/100)')
  })

  it('draws a chart of the zero-result rate per day', () => {
    const w = make()
    expect(w.find('.gchart').exists()).toBe(true)
    expect(w.vm.chartData).toEqual([
      ['Date', 'Zero-result %', 'Searched again within a minute %'],
      ['2026-10-01', 20, 10],
      ['2026-10-02', 30, 20],
    ])
  })

  it('shows the empty message', () => {
    store.search.data = { days: [] }
    expect(make().text()).toContain('No search data')
  })

  it('shows an error', () => {
    store.search.data = null
    store.search.error = 'err'
    expect(make().text()).toContain('err')
  })
})
