import { describe, it, expect, beforeEach } from 'vitest'
import { mount } from '@vue/test-utils'
import { setActivePinia, createPinia } from 'pinia'
import ModSysAdminUxHelp from '~/modtools/components/ModSysAdminUxHelp.vue'
import { useUxStore } from '~/modtools/stores/ux'
import { uxStubs } from './uxStubs'

describe('ModSysAdminUxHelp', () => {
  let store
  beforeEach(() => {
    setActivePinia(createPinia())
    store = useUxStore()
    store.funnels.data = {
      flows: [
        { flow: 'post', steps: [{ step: 'photos', entered: 200, help_sought: 30 }] },
      ],
      previous: {
        flows: [{ flow: 'post', steps: [{ step: 'photos', entered: 100, help_sought: 5 }] }],
      },
    }
  })
  const make = () => mount(ModSysAdminUxHelp, { global: { stubs: uxStubs } })

  it('shows help sought per step as a percentage with counts', () => {
    const t = make().text()
    expect(t).toContain('photos')
    expect(t).toContain('15.0% (30/200)')
  })

  it('shows the previous period', () => {
    expect(make().text()).toContain('5.0% (5/100)')
  })

  it('shows a dash for previous when the step is new', () => {
    store.funnels.data.previous = { flows: [] }
    expect(make().findAll('td').map((c) => c.text())).toContain('–')
  })

  it('shows the empty message', () => {
    store.funnels.data = { flows: [] }
    expect(make().text()).toContain('No help data')
  })

  it('shows an error', () => {
    store.funnels.data = null
    store.funnels.error = 'oops'
    expect(make().text()).toContain('oops')
  })
})
