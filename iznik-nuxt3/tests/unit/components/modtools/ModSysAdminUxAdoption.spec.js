import { describe, it, expect, beforeEach } from 'vitest'
import { mount } from '@vue/test-utils'
import { setActivePinia, createPinia } from 'pinia'
import ModSysAdminUxAdoption from '~/modtools/components/ModSysAdminUxAdoption.vue'
import { useUxStore } from '~/modtools/stores/ux'
import { uxStubs } from './uxStubs'

describe('ModSysAdminUxAdoption', () => {
  let store
  beforeEach(() => {
    setActivePinia(createPinia())
    store = useUxStore()
    store.adoption.data = {
      features: [
        { feature: 'saved_search', active_users: 400, using_users: 40, sessions_using: 90, share: 0.1, previous_share: 0.05 },
      ],
    }
  })
  const make = () => mount(ModSysAdminUxAdoption, { global: { stubs: uxStubs } })

  it('shows users of the feature over active users, with counts', () => {
    const t = make().text()
    expect(t).toContain('saved_search')
    expect(t).toContain('10.0% (40/400)')
  })

  it('shows the previous share and the change in points', () => {
    const t = make().text()
    expect(t).toContain('5.0%')
    expect(t).toContain('+5.0 points')
  })

  it('shows the empty message', () => {
    store.adoption.data = { features: [] }
    expect(make().text()).toContain('No adoption data')
  })

  it('shows an error', () => {
    store.adoption.data = null
    store.adoption.error = 'down'
    expect(make().text()).toContain('down')
  })
})
