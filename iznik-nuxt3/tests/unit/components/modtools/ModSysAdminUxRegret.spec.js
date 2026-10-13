import { describe, it, expect, beforeEach } from 'vitest'
import { mount } from '@vue/test-utils'
import { setActivePinia, createPinia } from 'pinia'
import ModSysAdminUxRegret from '~/modtools/components/ModSysAdminUxRegret.vue'
import { useUxStore } from '~/modtools/stores/ux'
import { uxStubs } from './uxStubs'

describe('ModSysAdminUxRegret', () => {
  let store
  beforeEach(() => {
    setActivePinia(createPinia())
    store = useUxStore()
    store.regret.data = {
      signals: [
        {
          signal: 'edit_after_post',
          days: [
            { date: '2026-10-01', count: 3, base: 50 },
            { date: '2026-10-02', count: 7, base: 50 },
          ],
          total: 10,
          previous_total: 4,
        },
      ],
    }
  })
  const make = () => mount(ModSysAdminUxRegret, { global: { stubs: uxStubs } })

  it('shows each signal with its total as a percentage of the base, with counts', () => {
    const t = make().text()
    expect(t).toContain('edit_after_post')
    expect(t).toContain('10.0% (10/100)')
  })

  it('shows the previous total and the change', () => {
    const t = make().text()
    expect(t).toContain('4')
    expect(t).toContain('+6')
  })

  it('draws a daily chart with one column per signal', () => {
    const w = make()
    expect(w.find('.gchart').exists()).toBe(true)
    expect(w.vm.chartData).toEqual([
      ['Date', 'edit_after_post'],
      ['2026-10-01', 3],
      ['2026-10-02', 7],
    ])
  })

  it('shows the empty message', () => {
    store.regret.data = { signals: [] }
    expect(make().text()).toContain('No regret data')
  })

  it('shows an error', () => {
    store.regret.data = null
    store.regret.error = 'fail'
    expect(make().text()).toContain('fail')
  })
})
