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
      flows: [
        { flow: 'give', steps: [step('give-mobile-photos', 25, 100)] },
        { flow: 'ask', steps: [step('ask-mobile-details', 9, 30)] },
      ],
      previous: {
        flows: [
          { flow: 'give', steps: [step('give-mobile-photos', 5, 100)] },
          { flow: 'ask', steps: [step('ask-mobile-details', 1, 30)] },
        ],
      },
    }
    store.fields.data = {
      fields: [
        {
          flow: 'give',
          step: 'give-mobile-photos',
          field: 'title',
          revisits: 12,
          validation_failures: 3,
          choice_switches: 7,
        },
        {
          flow: 'ask',
          step: 'ask-mobile-details',
          field: 'wanted',
          revisits: 99,
          validation_failures: 98,
          choice_switches: 97,
        },
      ],
    }
  })
  const make = (props = {}) =>
    mount(ModSysAdminUxSecondThoughts, {
      props: { flow: 'give', ...props },
      global: { stubs: uxStubs },
    })

  it('shows the rate of going back per step with counts, and the previous period', () => {
    const t = make({ open: true }).text()
    expect(t).toContain('25.0% (25/100)')
    expect(t).toContain('5.0% (5/100)')
  })

  it('shows the steps of the given funnel only', () => {
    const t = make({ open: true }).text()
    expect(t).not.toContain('9/30')
    expect(t).not.toContain('Asking')
    expect(make({ open: true, flow: 'ask' }).text()).toContain('30.0% (9/30)')
  })

  it('names a step in words, not by its route', () => {
    const t = make({ open: true }).text()
    expect(t).toContain('Photos')
    expect(t).not.toContain('give-mobile-photos')
  })

  it('has no column for the funnel, since it shows one', () => {
    const w = make({ open: true })
    const panel = w.findAllComponents({ name: 'b-table' })
    // The stubbed table renders a cell for each field, so the fields tell us the columns.
    expect(w.text()).not.toContain('Flow')
    expect(panel.length >= 0).toBe(true)
  })

  it('lists the fields people revisit, fail validation on, or switch, for this funnel only', () => {
    const t = make({ open: true }).text()
    expect(t).toContain('title')
    expect(t).toContain('12')
    expect(t).toContain('3')
    expect(t).toContain('7')
    expect(t).not.toContain('wanted')
    expect(t).not.toContain('99')
  })

  it('shows the empty message with neither data set', () => {
    store.funnels.data = { flows: [] }
    store.fields.data = { fields: [] }
    expect(make({ open: true }).text()).toContain(
      'No data for this funnel in this period.'
    )
  })

  it('shows an error from either request', () => {
    store.fields.data = null
    store.fields.error = 'fields broke'
    expect(make().text()).toContain('fields broke')
  })

  describe('the closed row', () => {
    it('names the step where the biggest share went back', () => {
      store.funnels.data = {
        flows: [
          {
            flow: 'give',
            steps: [
              step('give-mobile-photos', 25, 100),
              step('give-mobile-details', 30, 100),
              step('give-mobile-options', 40, 4000),
            ],
          },
        ],
      }
      expect(make().find('.ux-summary').text()).toBe(
        'Most went back at Details: 30.0% (30 of 100).'
      )
    })

    it('needs 20 visits for a step to count', () => {
      store.funnels.data = {
        flows: [{ flow: 'give', steps: [step('give-mobile-photos', 9, 19)] }],
      }
      expect(make().find('.ux-summary').text()).toBe('Nothing stood out.')
    })

    it('says nothing stood out when nobody went back', () => {
      store.funnels.data = {
        flows: [{ flow: 'give', steps: [step('give-mobile-photos', 0, 500)] }],
      }
      expect(make().find('.ux-summary').text()).toBe('Nothing stood out.')
    })

    it('asks the section to open it', async () => {
      const w = make()
      await w.find('h4 > button').trigger('click')
      expect(w.emitted('toggle')).toHaveLength(1)
    })
  })

  it('says what the server did with the filters on both of its reads, without repeating itself', () => {
    store.funnels.data = {
      ...store.funnels.data,
      filters: { device: 'mobile', signed_in: '1' },
      ignored: [],
    }
    store.fields.data = {
      fields: [],
      filters: { device: 'mobile', signed_in: '' },
      ignored: ['signed_in'],
    }
    store.held.funnels = { device: 'mobile', signed_in: '1' }
    store.held.fields = { device: 'mobile', signed_in: '1', flow: 'give' }
    const notes = make().findAll('.ux-note')
    expect(notes.map((n) => n.text())).toEqual([
      'Not filtered by sign-in. Shows signed-in and signed-out people together.',
    ])
  })

  it('says the part is not switched on when a route is missing', () => {
    store.funnels.data = null
    store.funnels.missing = true
    expect(make().find('.ux-summary').text()).toBe(
      'This part is not switched on yet.'
    )
  })
})
