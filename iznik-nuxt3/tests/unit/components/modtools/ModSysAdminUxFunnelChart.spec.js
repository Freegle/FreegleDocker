import { describe, it, expect } from 'vitest'
import { mount } from '@vue/test-utils'
import ModSysAdminUxFunnelChart from '~/modtools/components/ModSysAdminUxFunnelChart.vue'
import { uxStubs } from './uxStubs'

const steps = [
  {
    step: 'give',
    label: 'Item and photos',
    entered: 300,
    visits: 310,
    abandoned: 93,
    reach: 1,
    lossRate: 0.3,
  },
  {
    step: 'give-whereami',
    label: 'Location',
    entered: 150,
    visits: 160,
    abandoned: 16,
    reach: 0.5,
    lossRate: 0.1,
  },
  {
    step: 'give-whoami',
    label: 'Who you are',
    entered: 120,
    visits: 125,
    abandoned: 0,
    reach: 0.4,
    lossRate: 0,
  },
]

// Different ways into one stage: signing in or joining.
const stageWithWays = [
  {
    step: 'signin',
    label: 'Signing in or Joining',
    entered: 400,
    visits: 500,
    abandoned: 100,
    reach: 1,
    lossRate: 0.2,
    alternatives: [
      {
        step: 'signin',
        label: 'Signing in',
        entered: 300,
        visits: 380,
        abandoned: 60,
        reach: 0.75,
        lossRate: 60 / 380,
      },
      {
        step: 'signup',
        label: 'Joining',
        entered: 100,
        visits: 120,
        abandoned: 40,
        reach: 0.25,
        lossRate: 40 / 120,
      },
    ],
  },
]

const make = (props = {}) =>
  mount(ModSysAdminUxFunnelChart, {
    props: { title: 'Desktop pages', steps, ...props },
    global: { stubs: uxStubs },
  })

describe('ModSysAdminUxFunnelChart', () => {
  it('draws one row per step, sized by reach, labelled for people', () => {
    const w = make()
    const rows = w.findAll('li.funnel-row')
    expect(rows).toHaveLength(3)
    const widths = w
      .findAll('.bar')
      .map((b) =>
        parseFloat(b.attributes('style').match(/width:\s*([\d.]+)%/)[1])
      )
    expect(widths[0]).toBeGreaterThan(widths[1])
    expect(widths[1]).toBeCloseTo(widths[0] / 2, 0)
    expect(w.text()).toContain('Item and photos')
  })

  it('uses one denominator on a line and says what it is', () => {
    const t = make().text()
    // 93 of the 310 visits, not of the 300 who reached: the reader can check 93/310 = 30%.
    expect(t).toContain('300 reached. 93 of 310 visits ended here (30%)')
    expect(t).toContain('120 reached')
    expect(t).not.toContain('120 reached.')
  })

  it('tags the worst step in words, not only in colour, and draws the loss', () => {
    const w = make()
    const rows = w.findAll('li.funnel-row')
    expect(rows[0].find('.worst-tag').text()).toBe('Biggest loss')
    expect(rows[0].classes()).toContain('worst')
    expect(rows[1].find('.worst-tag').exists()).toBe(false)
    expect(w.findAll('.bar-loss')).toHaveLength(2)
  })

  it('does not call a step with few visits the worst', () => {
    const w = make({
      steps: [
        {
          step: 'a',
          label: 'A',
          entered: 100,
          visits: 100,
          abandoned: 10,
          reach: 1,
          lossRate: 0.1,
        },
        {
          step: 'b',
          label: 'B',
          entered: 5,
          visits: 5,
          abandoned: 5,
          reach: 0.05,
          lossRate: 1,
        },
      ],
    })
    const rows = w.findAll('li.funnel-row')
    expect(rows[0].classes()).toContain('worst')
    expect(rows[1].classes()).not.toContain('worst')
  })

  it('outlines the previous period when given', () => {
    const w = make({ previousReach: { give: 1, 'give-whereami': 0.7 } })
    const prev = w.findAll('.bar-prev')
    expect(prev).toHaveLength(2)
    expect(prev[1].attributes('style')).toContain('width: 70%')
  })

  it('explains the dashed outline on the chart itself', () => {
    expect(make().text()).toContain('Grey dashed outline')
    expect(make().text()).toContain("that period's first step")
  })

  describe('as buttons a keyboard and a screen reader can use', () => {
    it('makes each step a real button with a name and a pressed state', () => {
      const w = make({ selected: 'give-whereami' })
      const buttons = w.findAll('button.line')
      expect(buttons).toHaveLength(3)
      for (const b of buttons) expect(b.attributes('type')).toBe('button')
      expect(buttons[0].attributes('aria-pressed')).toBe('false')
      expect(buttons[1].attributes('aria-pressed')).toBe('true')
      expect(buttons[0].attributes('aria-label')).toContain(
        'Item and photos: 300 reached'
      )
      expect(buttons[0].attributes('aria-label')).toContain('Biggest loss')
    })

    it('has no svg pretending to be one image', () => {
      expect(make().find('svg').exists()).toBe(false)
    })

    it('emits the step on a click and marks the chosen row', async () => {
      const w = make({ selected: 'give-whereami' })
      await w.findAll('button.line')[0].trigger('click')
      expect(w.emitted('select')[0]).toEqual(['give'])
      expect(w.findAll('li.funnel-row')[1].classes()).toContain('active')
    })

    it('labels the list', () => {
      expect(make().find('ul.rows').attributes('aria-label')).toBe(
        'Funnel: Desktop pages'
      )
    })
  })

  describe('as a table', () => {
    it('can show the same figures as a table, and back', async () => {
      const w = make()
      expect(w.find('table').exists()).toBe(false)
      await w.find('[data-testid="chart-view-toggle"]').trigger('click')
      expect(w.find('ul.rows').exists()).toBe(false)
      const t = w.find('table')
      expect(t.exists()).toBe(true)
      expect(t.text()).toContain('Item and photos')
      expect(t.text()).toContain('310')
      expect(t.text()).toContain('93')
      expect(t.text()).toContain('30%')
      expect(t.find('th[scope="col"]').exists()).toBe(true)
      expect(w.find('[data-testid="chart-view-toggle"]').text()).toBe(
        'Show as bars'
      )
      await w.find('[data-testid="chart-view-toggle"]').trigger('click')
      expect(w.find('table').exists()).toBe(false)
    })

    it('tags the worst step in the table too', async () => {
      const w = make()
      await w.find('[data-testid="chart-view-toggle"]').trigger('click')
      expect(w.find('table').text()).toContain('Biggest loss')
    })
  })

  describe('a stage with different ways in', () => {
    it('shows the stage once, and each way in under it as its own row', () => {
      const w = make({ steps: stageWithWays })
      const rows = w.findAll('li.funnel-row')
      expect(rows).toHaveLength(3)
      expect(rows[0].text()).toContain('Signing in or Joining')
      expect(rows[0].find('button').exists()).toBe(false)
      expect(rows[1].classes()).toContain('indent')
      expect(rows[1].text()).toContain('came in through Signing in')
      expect(rows[2].text()).toContain('came in through Joining')
    })

    it('lets a way in be chosen, but not the stage heading', async () => {
      const w = make({ steps: stageWithWays })
      expect(w.findAll('button.line')).toHaveLength(2)
      await w.findAll('button.line')[1].trigger('click')
      expect(w.emitted('select')[0]).toEqual(['signup'])
    })

    it('names the worst way in, not the stage', () => {
      const w = make({ steps: stageWithWays })
      const rows = w.findAll('li.funnel-row')
      expect(rows[0].find('.worst-tag').exists()).toBe(false)
      // 40 of 120 (33%) is worse than 60 of 380 (16%).
      expect(rows[2].find('.worst-tag').exists()).toBe(true)
      expect(rows[1].find('.worst-tag').exists()).toBe(false)
    })

    it('gives each way in a share of everyone who started, so they add up', () => {
      const w = make({ steps: stageWithWays })
      const widths = w
        .findAll('.bar')
        .map((b) =>
          parseFloat(b.attributes('style').match(/width:\s*([\d.]+)%/)[1])
        )
      expect(widths[1] + widths[2]).toBeCloseTo(widths[0], 0)
    })
  })

  describe('a way out that is not a step', () => {
    const exits = [
      {
        step: 'forgot',
        label: 'Went to reset their password',
        entered: 40,
        visits: 45,
      },
    ]

    it('is drawn beside the funnel, labelled, and not counted as lost', () => {
      const w = make({ steps: stageWithWays, exits })
      const row = w.findAll('li.funnel-row').at(-1)
      expect(row.classes()).toContain('exit')
      expect(row.text()).toContain('Went to reset their password')
      expect(row.text()).toContain('40 people. Not counted as lost.')
      expect(row.find('button').exists()).toBe(false)
      expect(row.find('.bar-loss').exists()).toBe(false)
      expect(row.find('.worst-tag').exists()).toBe(false)
    })
  })
})
