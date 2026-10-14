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
          ModSysAdminUxProblems: {
            name: 'ProblemsStub',
            template: '<div class="problems-stub">{{ title }}</div>',
            props: ['title', 'items', 'base', 'mode', 'note', 'emptyText'],
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
    expect(charts[0].props('previousReach')).toEqual({ give: 1, 'stage:0': 1 })
  })

  it('keeps the previous period of a stage with ways in apart from each way in', () => {
    const way = (name, entered) =>
      step(name, { entered, visits: entered, completed: entered, abandoned: 0 })
    store.funnels.data = {
      start: '2026-10-01',
      end: '2026-10-07',
      flows: [{ flow: 'login', steps: [way('signup', 40), way('signin', 60)] }],
      previous: {
        flows: [
          { flow: 'login', steps: [way('signup', 60), way('signin', 40)] },
        ],
      },
    }
    const reach = make()
      .findAllComponents({ name: 'ModSysAdminUxFunnelChart' })[0]
      .props('previousReach')
    // The heading is the whole stage (everyone), not the 60% who joined last time.
    expect(reach['stage:0']).toBe(1)
    expect(reach.signup).toBeCloseTo(0.6)
    expect(reach.signin).toBeCloseTo(0.4)
  })

  it('lists member suggestions with no funnel apart, and gives them no funnel section', () => {
    store.experiments.data.general_ideas = [
      {
        id: 9,
        flow: 'general',
        title: 'Let me hide my phone number',
        rationale: '1 member suggested this in their own words.',
        source: 'member',
      },
    ]
    const w = make()
    expect(w.text()).toContain('Suggestions not tied to one flow')
    expect(w.text()).toContain('Let me hide my phone number')
    expect(w.text()).toContain('suggested by a member')
    expect(w.text()).not.toContain('general: nobody started')
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

  it('explains every mark on the chart, including the dashed outline of the period before', () => {
    const t = make().text()
    expect(t).toContain('dashed outline is the same step in the period before')
    expect(t).toContain('hatched red end')
  })

  it('shows the empty message when neither funnels nor experiments list a flow', () => {
    store.funnels.data = { flows: [] }
    store.experiments.data = null
    expect(make().text()).toContain('No flow data')
  })

  describe('a flow with no traffic in the period', () => {
    const quiet = () => ({
      flow: 'settings',
      running: [{ id: 9, title: 'A quiet one', status: 'running' }],
      history: [],
      ideas: [],
      problems: [],
    })

    it('still shows its section and its running experiment', () => {
      store.experiments.data.flows.push(quiet())
      const w = make()
      expect(w.text()).toContain('Settings')
      const stubs = w.findAllComponents({ name: 'ExperimentsStub' })
      expect(stubs.map((e) => e.props('flowData').flow)).toEqual([
        'give',
        'settings',
      ])
      expect(w.text()).toContain('Settings: nobody started in this period.')
    })

    it('shows experiment sections and the slot count even with no funnel rows at all', () => {
      store.funnels.data = { flows: [] }
      store.outcomes.data = { flows: [] }
      store.experiments.data.flows.push(quiet())
      const w = make()
      expect(w.text()).not.toContain('No flow data')
      expect(w.text()).toContain('1 of 5 experiment slots in use.')
      expect(w.findAllComponents({ name: 'ExperimentsStub' }).length).toBe(2)
    })
  })

  describe('problems members raised', () => {
    const problem = {
      id: 4,
      title: 'Photos take ages to upload',
      description: 'On slow phones.',
      source: 'members',
      mentions: 14,
      weight: 0.2,
      status: 'open',
      ideas: [],
      experiments: [],
    }

    beforeEach(() => {
      store.experiments.data.flows[0].problems = [problem]
      store.experiments.data.surveys = [
        { prompt_id: 1, kind: 'open', analysed: true, responses: 120 },
        { prompt_id: 2, kind: 'open', analysed: false, responses: 50 },
        { prompt_id: 3, kind: 'survey', analysed: false, responses: 10 },
      ]
    })

    it('puts them under the funnel and above the experiments, each problem keeping the base of its own question', () => {
      const w = make()
      const html = w.html()
      const p = w.findAllComponents({ name: 'ProblemsStub' })[0]
      expect(p.props('title')).toBe('Problems members raised')
      expect(p.props('items')).toEqual([problem])
      // The base is not summed over questions here: each item carries its own, so two analysed
      // questions cannot lend each other their answer counts.
      expect(p.props('base')).toBeUndefined()
      expect(html.indexOf('problems-stub')).toBeLessThan(
        html.indexOf('experiments-stub')
      )
    })

    it('shows what members value and problems not tied to one flow after the funnels', () => {
      store.experiments.data.values = [
        { id: 1, title: 'It is free', mentions: 30 },
      ]
      store.experiments.data.general_problems = [
        { ...problem, id: 8, title: 'Too many emails' },
      ]
      const w = make()
      const all = w.findAllComponents({ name: 'ProblemsStub' })
      const value = all.find((c) => c.props('mode') === 'values')
      expect(value.props('title')).toBe('What members value')
      expect(value.props('items')).toEqual([
        { id: 1, title: 'It is free', mentions: 30 },
      ])
      const general = all.find(
        (c) => c.props('title') === 'Problems not tied to one flow'
      )
      expect(general.props('items')[0].title).toBe('Too many emails')
      expect(w.html().indexOf('Across all of Freegle')).toBeGreaterThan(
        w.html().indexOf('experiments-stub')
      )
    })

    it('leaves the general section out when there is nothing in it', () => {
      expect(make().text()).not.toContain('Across all of Freegle')
    })
  })

  describe('readable breakdown keys', () => {
    async function table(selectStep = 'give') {
      store.funnels.data.flows[0].steps[0].by = {
        device_class: [
          {
            key: 'app-ios',
            entered: 10,
            visits: 10,
            completed: 5,
            abandoned: 2,
          },
          {
            key: 'mobile',
            entered: 10,
            visits: 10,
            completed: 5,
            abandoned: 2,
          },
        ],
        logged_in: [
          { key: 'true', entered: 10, visits: 10, completed: 5, abandoned: 2 },
          { key: 'false', entered: 10, visits: 10, completed: 5, abandoned: 2 },
        ],
        variant: [
          { key: '', entered: 10, visits: 10, completed: 5, abandoned: 2 },
          {
            key: 'photos-skip:b,show-hint:control',
            entered: 10,
            visits: 10,
            completed: 5,
            abandoned: 2,
          },
        ],
      }
      const w = make()
      w.findAllComponents({ name: 'ModSysAdminUxFunnelChart' })[0].vm.$emit(
        'select',
        selectStep
      )
      await w.vm.$nextTick()
      return w.text()
    }

    it('says the iOS app, not app-ios', async () => {
      const t = await table()
      expect(t).toContain('the iOS app')
      expect(t).not.toContain('app-ios')
      expect(t).toContain('mobile web')
    })

    it('says logged in and not logged in, not true and false', async () => {
      const t = await table()
      expect(t).toContain('Logged in')
      expect(t).toContain('Not logged in')
    })

    it('says not in an experiment for an empty variant, and spaces out arms', async () => {
      const t = await table()
      expect(t).toContain('Not in an experiment')
      expect(t).toContain('photos-skip: b; show-hint: control')
    })
  })

  it('hands the chart the exits of a flow, so a password reset is not drawn as a loss', () => {
    store.funnels.data.flows = [
      {
        flow: 'login',
        steps: [
          step('signin', { entered: 100, visits: 120, abandoned: 30 }),
          step('forgot', { entered: 40, visits: 45, abandoned: 40 }),
        ],
      },
    ]
    store.outcomes.data = {
      flows: [{ flow: 'login', started: 100, completed: 70 }],
    }
    const w = make()
    const chart = w.findAllComponents({ name: 'ModSysAdminUxFunnelChart' })[0]
    expect(chart.props('steps').map((x) => x.step)).toEqual(['signin'])
    expect(chart.props('exits')).toEqual([
      {
        step: 'forgot',
        label: 'Went to reset their password',
        entered: 40,
        visits: 45,
      },
    ])
    expect(w.text()).not.toContain('Forgotten password ended there')
  })

  it('shows an error from either request', () => {
    store.funnels.data = null
    store.outcomes.error = 'outcomes broke'
    expect(make().text()).toContain('outcomes broke')
  })
})
