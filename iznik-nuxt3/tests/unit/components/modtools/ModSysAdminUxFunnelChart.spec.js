import { describe, it, expect } from 'vitest'
import { mount } from '@vue/test-utils'
import ModSysAdminUxFunnelChart from '~/modtools/components/ModSysAdminUxFunnelChart.vue'

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

const make = (props = {}) =>
  mount(ModSysAdminUxFunnelChart, {
    props: { title: 'Desktop pages', steps, ...props },
  })

describe('ModSysAdminUxFunnelChart', () => {
  it('draws one bar per step, sized by reach, labelled for people', () => {
    const w = make()
    const bars = w.findAll('rect.bar')
    expect(bars).toHaveLength(3)
    expect(Number(bars[0].attributes('width'))).toBeGreaterThan(
      Number(bars[1].attributes('width'))
    )
    expect(Number(bars[1].attributes('width'))).toBeCloseTo(
      Number(bars[0].attributes('width')) / 2,
      0
    )
    expect(w.text()).toContain('Item and photos')
    expect(w.text()).toContain('300 reached, 93 left here (30%)')
    expect(w.text()).toContain('120 reached')
    expect(w.text()).not.toContain('120 reached,')
  })

  it('marks the step with the worst loss and draws the loss in red', () => {
    const w = make()
    const bars = w.findAll('rect.bar')
    expect(bars[0].classes()).toContain('worst')
    expect(bars[1].classes()).not.toContain('worst')
    expect(w.findAll('rect.bar-loss')).toHaveLength(2)
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
    const bars = w.findAll('rect.bar')
    expect(bars[0].classes()).toContain('worst')
    expect(bars[1].classes()).not.toContain('worst')
  })

  it('outlines the previous period when given', () => {
    const w = make({ previousReach: { give: 1, 'give-whereami': 0.7 } })
    const prev = w.findAll('rect.bar-prev')
    expect(prev).toHaveLength(2)
    expect(Number(prev[1].attributes('width'))).toBeCloseTo(0.7 * 320, 0)
  })

  it('emits the step when a bar is clicked or chosen with the keyboard, and marks the selection', async () => {
    const w = make({ selected: 'give-whereami' })
    await w.findAll('rect.bar')[0].trigger('click')
    expect(w.emitted('select')[0]).toEqual(['give'])
    await w.findAll('rect.bar')[2].trigger('keydown.enter')
    expect(w.emitted('select')[1]).toEqual(['give-whoami'])
    expect(w.findAll('rect.bar')[1].classes()).toContain('active')
  })

  it('describes itself for screen readers', () => {
    expect(make().find('svg').attributes('aria-label')).toContain(
      'Item and photos 300 reached, 93 left'
    )
  })
})
