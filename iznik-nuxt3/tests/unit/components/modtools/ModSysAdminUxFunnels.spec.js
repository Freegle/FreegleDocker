import { describe, it, expect, beforeEach } from 'vitest'
import { mount } from '@vue/test-utils'
import { setActivePinia, createPinia } from 'pinia'
import ModSysAdminUxFunnels from '~/modtools/components/ModSysAdminUxFunnels.vue'
import { useUxStore } from '~/modtools/stores/ux'
import { uxStubs } from './uxStubs'

const step = (name, over = {}) => ({
  step: name,
  entered: 100,
  reentered: 0,
  visits: 100,
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
      { key: 'mobile', entered: 70, visits: 70, completed: 35, abandoned: 25 },
      { key: 'desktop', entered: 30, visits: 30, completed: 25, abandoned: 5 },
    ],
    logged_in: [
      { key: 'true', entered: 100, visits: 100, completed: 60, abandoned: 30 },
    ],
    variant: [],
  },
  ...over,
})

const funnels = {
  start: '2026-10-01',
  end: '2026-10-07',
  flows: [
    {
      flow: 'give',
      steps: [
        step('give'),
        step('give-whereami', {
          entered: 80,
          visits: 80,
          completed: 20,
          abandoned: 10,
        }),
        step('give-mobile-photos', {
          entered: 120,
          visits: 130,
          completed: 70,
          abandoned: 50,
        }),
      ],
    },
  ],
  previous: {
    flows: [
      {
        flow: 'give',
        steps: [step('give', { entered: 200, visits: 200, completed: 150 })],
      },
    ],
  },
}

const outcomes = {
  flows: [
    {
      flow: 'give',
      started: 220,
      submitted: 110,
      submit_ok: 105,
      submit_failed: 5,
      completed: 100,
      abandoned: 90,
      resumed: 3,
    },
  ],
  previous: { flows: [{ flow: 'give', started: 200, completed: 80 }] },
}

const experiments = {
  running_count: 1,
  max_running: 5,
  surveys: [],
  flows: [
    {
      flow: 'give',
      running: [],
      history: [],
      ideas: [
        {
          id: 1,
          title: 'An idea',
          rationale: 'r',
          expected: 'e',
          score: 5,
          source: 'ai',
        },
      ],
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
    store.experiments.data = JSON.parse(JSON.stringify(experiments))
  })

  const make = () =>
    mount(ModSysAdminUxFunnels, {
      global: {
        stubs: {
          ...uxStubs,
          ModSysAdminUxSamples: {
            name: 'SamplesStub',
            template: '<div class="samples-stub" />',
            props: ['modelValue', 'flow', 'step', 'start', 'end'],
          },
          ModSysAdminUxExperiments: {
            name: 'ExperimentsStub',
            template: '<div class="experiments-stub" />',
            props: ['flowData', 'full'],
          },
        },
      },
    })

  it('leads each flow with a headline sentence a person can act on', () => {
    const t = make().text()
    expect(t).toContain(
      'Offering something: 220 started, 100 finished (45%, before that 40%).'
    )
    expect(t).toContain(
      'Biggest loss: 38% of the 130 visits to Photos ended there'
    )
  })

  it('draws a desktop and a mobile funnel for give, with bars in route order', () => {
    const w = make()
    const charts = w.findAllComponents({ name: 'ModSysAdminUxFunnelChart' })
    expect(charts).toHaveLength(2)
    expect(charts[0].props('title')).toBe('Desktop pages')
    expect(charts[0].props('steps').map((s) => s.step)).toEqual([
      'give',
      'give-whereami',
    ])
    expect(charts[1].props('title')).toBe('Mobile pages')
    expect(charts[0].props('previousReach')).toEqual({ give: 1 })
  })

  it('says how many experiment slots are in use and hands each flow its experiments', () => {
    const w = make()
    expect(w.text()).toContain('1 of 5 experiment slots in use.')
    const exp = w.findComponent({ name: 'ExperimentsStub' })
    expect(exp.props('flowData').flow).toBe('give')
    expect(exp.props('full')).toBe(false)
  })

  it('marks the cap as full at the limit', () => {
    store.experiments.data.running_count = 5
    expect(
      make().findComponent({ name: 'ExperimentsStub' }).props('full')
    ).toBe(true)
  })

  it('warns when the experiment history could not be loaded, but still shows the funnels', () => {
    store.experiments.data = null
    store.experiments.error = 'experiments broke'
    const w = make()
    expect(w.text()).toContain('experiments broke')
    expect(w.text()).toContain('Offering something: 220 started')
    expect(w.findComponent({ name: 'ExperimentsStub' }).exists()).toBe(false)
  })

  it('shows no step detail until a bar is chosen, then the breakdown, dwell and previous figure', async () => {
    const w = make()
    expect(w.find('.step-detail').exists()).toBe(false)
    w.findAllComponents({ name: 'ModSysAdminUxFunnelChart' })[0].vm.$emit(
      'select',
      'give'
    )
    await w.vm.$nextTick()
    const d = w.find('.step-detail')
    expect(d.exists()).toBe(true)
    expect(d.text()).toContain('Item and photos')
    expect(d.text()).toContain('8.0 s')
    expect(d.text()).toContain('45.0 s')
    expect(d.text()).toContain('50.0% (35/70)')
    expect(d.text()).toContain('Previous period: 75.0% (150/200) completed')
  })

  it('choosing the same bar again closes the detail', async () => {
    const w = make()
    const chart = w.findAllComponents({ name: 'ModSysAdminUxFunnelChart' })[0]
    chart.vm.$emit('select', 'give')
    await w.vm.$nextTick()
    chart.vm.$emit('select', 'give')
    await w.vm.$nextTick()
    expect(w.find('.step-detail').exists()).toBe(false)
  })

  it('opens the samples modal for the selected flow, step and period', async () => {
    const w = make()
    w.findAllComponents({ name: 'ModSysAdminUxFunnelChart' })[1].vm.$emit(
      'select',
      'give-mobile-photos'
    )
    await w.vm.$nextTick()
    await w.find('.sample-btn').trigger('click')
    const s = w.findComponent({ name: 'SamplesStub' })
    expect(s.props('modelValue')).toBe(true)
    expect(s.props('flow')).toBe('give')
    expect(s.props('step')).toBe('give-mobile-photos')
    expect(s.props('start')).toBe('2026-10-01')
    expect(s.props('end')).toBe('2026-10-07')
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
