import { describe, it, expect, beforeEach } from 'vitest'
import { mount } from '@vue/test-utils'
import { setActivePinia, createPinia } from 'pinia'
import ModSysAdminUxFunnels from '~/modtools/components/ModSysAdminUxFunnels.vue'
import { useUxStore } from '~/modtools/stores/ux'
import { uxStubs } from './uxStubs'

const step = (name, over = {}) => ({
  step: name,
  entered: 100,
  reentered: 3,
  completed: 60,
  backed: 10,
  abandoned: 30,
  revisits: 5,
  validation_failures: 2,
  help_sought: 4,
  dwell_p50_ms: 8000,
  dwell_p90_ms: 45000,
  by: {
    device_class: [
      { key: 'mobile', entered: 70, completed: 35, abandoned: 25 },
      { key: 'desktop', entered: 30, completed: 25, abandoned: 5 },
    ],
    logged_in: [{ key: 'true', entered: 100, completed: 60, abandoned: 30 }],
    variant: [],
  },
  ...over,
})

const funnels = {
  start: '2026-10-01',
  end: '2026-10-07',
  flows: [
    {
      flow: 'post',
      steps: [step('item'), step('photos', { entered: 80, completed: 20 })],
    },
  ],
  previous: {
    flows: [
      { flow: 'post', steps: [step('item', { entered: 200, completed: 150 })] },
    ],
  },
}

const outcomes = {
  flows: [
    {
      flow: 'post',
      started: 100,
      submitted: 50,
      submit_ok: 45,
      submit_failed: 5,
      completed: 45,
      abandoned: 40,
      resumed: 3,
      total_dwell_p50_ms: 90000,
    },
  ],
}

describe('ModSysAdminUxFunnels', () => {
  let store
  beforeEach(() => {
    setActivePinia(createPinia())
    store = useUxStore()
    store.funnels.data = JSON.parse(JSON.stringify(funnels))
    store.outcomes.data = JSON.parse(JSON.stringify(outcomes))
  })
  const make = () =>
    mount(ModSysAdminUxFunnels, {
      global: {
        stubs: {
          ...uxStubs,
          ModSysAdminUxSamples: {
            name: 'SamplesStub',
            template: '<div class="samples-stub" />',
            props: ['modelValue', 'flow', 'step', 'date'],
          },
        },
      },
    })

  it('shows one strip per flow with a card per step', () => {
    const w = make()
    expect(w.findAll('.flow-strip')).toHaveLength(1)
    expect(w.findAll('.step-card')).toHaveLength(2)
    expect(w.text()).toContain('post')
  })

  it('shows conversion as a percentage with its counts', () => {
    const w = make()
    expect(w.findAll('.step-card')[0].text()).toContain('60.0% (60/100)')
    expect(w.findAll('.step-card')[1].text()).toContain('25.0% (20/80)')
  })

  it('shows the previous period alongside', () => {
    const first = make().findAll('.step-card')[0].text()
    expect(first).toContain('75.0% (150/200)')
  })

  it('shows no previous figure when the previous period has no such step', () => {
    const second = make().findAll('.step-card')[1].text()
    expect(second).toContain('Previous: –')
  })

  it('summarises the flow outcome with counts', () => {
    const text = make().text()
    expect(text).toContain('Started 100')
    expect(text).toContain('45.0% (45/100) completed')
  })

  it('shows no step detail until a step is clicked', () => {
    expect(make().find('.step-detail').exists()).toBe(false)
  })

  it('shows the breakdown and dwell times for the clicked step', async () => {
    const w = make()
    await w.findAll('.step-card')[0].trigger('click')
    const d = w.find('.step-detail')
    expect(d.exists()).toBe(true)
    expect(d.text()).toContain('item')
    expect(d.text()).toContain('8.0 s')
    expect(d.text()).toContain('45.0 s')
    expect(d.text()).toContain('mobile')
    expect(d.text()).toContain('50.0% (35/70)')
    expect(d.text()).toContain('desktop')
  })

  it('marks the selected step and clicking it again closes the detail', async () => {
    const w = make()
    await w.findAll('.step-card')[0].trigger('click')
    expect(w.findAll('.step-card')[0].classes()).toContain('active')
    await w.findAll('.step-card')[0].trigger('click')
    expect(w.find('.step-detail').exists()).toBe(false)
  })

  it('opens the samples modal for the selected flow, step and end date', async () => {
    const w = make()
    await w.findAll('.step-card')[1].trigger('click')
    await w.find('.sample-btn').trigger('click')
    const s = w.findComponent({ name: 'SamplesStub' })
    expect(s.props('modelValue')).toBe(true)
    expect(s.props('flow')).toBe('post')
    expect(s.props('step')).toBe('photos')
    expect(s.props('date')).toBe('2026-10-07')
  })

  it('shows the empty message with no flows', () => {
    store.funnels.data = { flows: [] }
    expect(make().text()).toContain('No flow data')
  })

  it('shows an error from either request', () => {
    store.funnels.data = null
    store.outcomes.error = 'outcomes broke'
    expect(make().text()).toContain('outcomes broke')
  })
})
