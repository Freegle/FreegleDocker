import { describe, it, expect, beforeEach } from 'vitest'
import { mount } from '@vue/test-utils'
import { setActivePinia, createPinia } from 'pinia'
import ModSysAdminUxFriction from '~/modtools/components/ModSysAdminUxFriction.vue'
import { useUxStore } from '~/modtools/stores/ux'
import { uxStubs } from './uxStubs'

const data = {
  components: [
    {
      page: '/give',
      component: 'PhotoUpload',
      impressions: 1000,
      clicks: 200,
      dead_clicks: 40,
      rage_clicks: 10,
      repeated_intent: 6,
      previous: {
        impressions: 900,
        clicks: 180,
        dead_clicks: 9,
        rage_clicks: 2,
        repeated_intent: 1,
      },
      change: 3.5,
    },
  ],
}

describe('ModSysAdminUxFriction', () => {
  let store
  beforeEach(() => {
    setActivePinia(createPinia())
    store = useUxStore()
  })
  const make = () =>
    mount(ModSysAdminUxFriction, { global: { stubs: uxStubs } })

  it('lists components with dead and rage clicks as percentages with counts', () => {
    store.friction.data = data
    const t = make().text()
    expect(t).toContain('/give')
    expect(t).toContain('PhotoUpload')
    expect(t).toContain('20.0% (40/200)')
    expect(t).toContain('5.0% (10/200)')
  })

  it('shows the previous period alongside', () => {
    store.friction.data = data
    expect(make().text()).toContain('5.0% (9/180)')
  })

  it('shows the signed change', () => {
    store.friction.data = data
    expect(make().text()).toContain('+3.5')
  })

  it('shows an empty message with no components', () => {
    store.friction.data = { components: [] }
    expect(make().text()).toContain('No friction data')
  })

  it('shows loading and error states', () => {
    store.friction.loading = true
    expect(make().find('.spinner').exists()).toBe(true)
    store.friction.loading = false
    store.friction.error = 'bad'
    expect(make().text()).toContain('bad')
  })
})
