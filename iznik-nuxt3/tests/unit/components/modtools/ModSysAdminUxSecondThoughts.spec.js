import { describe, it, expect, beforeEach } from 'vitest'
import { mount } from '@vue/test-utils'
import { setActivePinia, createPinia } from 'pinia'
import ModSysAdminUxSecondThoughts from '~/modtools/components/ModSysAdminUxSecondThoughts.vue'
import { useUxStore } from '~/modtools/stores/ux'
import { uxStubs } from './uxStubs'

const step = (name, backed, visits) => ({
  step: name,
  entered: visits,
  visits,
  backed,
})

describe('ModSysAdminUxSecondThoughts', () => {
  let store
  beforeEach(() => {
    setActivePinia(createPinia())
    store = useUxStore()
    store.funnels.data = {
      flows: [{ flow: 'post', steps: [step('item', 25, 100)] }],
      previous: { flows: [{ flow: 'post', steps: [step('item', 5, 100)] }] },
    }
    store.fields.data = {
      fields: [
        {
          flow: 'post',
          step: 'item',
          field: 'title',
          revisits: 12,
          validation_failures: 3,
          choice_switches: 7,
        },
      ],
    }
  })
  const make = () =>
    mount(ModSysAdminUxSecondThoughts, { global: { stubs: uxStubs } })

  it('shows the rate of going back per step with counts, and the previous period', () => {
    const t = make().text()
    expect(t).toContain('25.0% (25/100)')
    expect(t).toContain('5.0% (5/100)')
  })

  it('lists the fields people revisit, fail validation on, or switch', () => {
    const t = make().text()
    expect(t).toContain('title')
    expect(t).toContain('12')
    expect(t).toContain('3')
    expect(t).toContain('7')
  })

  it('shows the empty message with neither data set', () => {
    store.funnels.data = { flows: [] }
    store.fields.data = { fields: [] }
    expect(make().text()).toContain('No second-thoughts data')
  })

  it('shows an error from either request', () => {
    store.fields.data = null
    store.fields.error = 'fields broke'
    expect(make().text()).toContain('fields broke')
  })
})
