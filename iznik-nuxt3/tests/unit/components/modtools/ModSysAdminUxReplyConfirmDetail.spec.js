import { describe, it, expect } from 'vitest'
import { mount } from '@vue/test-utils'
import ModSysAdminUxReplyConfirmDetail from '~/modtools/components/ModSysAdminUxReplyConfirmDetail.vue'
import { uxStubs } from './uxStubs'

const cell = (o) => ({
  replies: 0,
  asked: 0,
  yes_button: 0,
  yes_message: 0,
  sent_twice: 0,
  no: 0,
  no_answer: 0,
  waiting: 0,
  reached: 0,
  counted: 0,
  handed_over: 0,
  ...o,
})

// The strata add up to all. For ask the asked are the yes, the wrote-again, the sent-twice (none here), the no, the
// no answer and the waiting; reached equals the yes.
const reasons = {
  all: {
    control: cell({ replies: 100, reached: 100, counted: 60, handed_over: 15 }),
    ask: cell({
      replies: 100,
      asked: 100,
      yes_button: 40,
      yes_message: 10,
      no: 30,
      no_answer: 15,
      waiting: 5,
      reached: 50,
      counted: 60,
      handed_over: 14,
    }),
  },
  damaged: {
    control: cell({ replies: 50, reached: 50, counted: 30, handed_over: 9 }),
    ask: cell({
      replies: 50,
      asked: 50,
      yes_button: 20,
      yes_message: 5,
      no: 15,
      no_answer: 8,
      waiting: 2,
      reached: 25,
      counted: 30,
      handed_over: 8,
    }),
  },
  car: {
    control: cell({ replies: 30, reached: 30, counted: 18, handed_over: 4 }),
    ask: cell({
      replies: 30,
      asked: 30,
      yes_button: 12,
      yes_message: 3,
      no: 9,
      no_answer: 4,
      waiting: 2,
      reached: 15,
      counted: 18,
      handed_over: 4,
    }),
  },
  both: {
    control: cell({ replies: 20, reached: 20, counted: 12, handed_over: 2 }),
    ask: cell({
      replies: 20,
      asked: 20,
      yes_button: 8,
      yes_message: 2,
      no: 6,
      no_answer: 3,
      waiting: 1,
      reached: 10,
      counted: 12,
      handed_over: 2,
    }),
  },
}

const experiment = (over = {}) => ({
  id: 12,
  flow: 'reply',
  name: 'confirm-reply',
  title: 'Ask before passing on a reply to a damaged or bulky offer',
  status: 'running',
  design: {
    channel: 'server',
    source: 'confirm_reply',
    window_hours: 168,
    intake_hours: 168,
    max_hours: 336,
  },
  result: {
    detail: {
      exposed: { control: 100, ask: 100 },
      counted: { control: 60, ask: 60 },
      reasons,
    },
  },
  ...over,
})

// The shared table stub draws cells and no header row, so the labels of the columns never reach the text of the
// component. This one also draws them.
const tableWithHeaders = {
  template:
    '<table class="table"><thead><tr><th v-for="f in fields" :key="f.key">{{ f.label }}</th></tr></thead>' +
    '<tbody><tr v-for="(item, i) in items" :key="i"><td v-for="f in fields" :key="f.key">{{ item[f.key] }}</td></tr></tbody></table>',
  props: ['items', 'fields'],
}

const make = (exp, stubs = uxStubs) =>
  mount(ModSysAdminUxReplyConfirmDetail, {
    props: { experiment: exp },
    global: { stubs },
  })

describe('ModSysAdminUxReplyConfirmDetail', () => {
  it('draws nothing for any experiment but confirm-reply', () => {
    expect(
      make(experiment({ name: 'digest-subject' }))
        .find('[data-testid="ux-reply-confirm"]')
        .exists()
    ).toBe(false)
    expect(make(experiment({ name: 'reply-web' })).html()).not.toContain(
      'Still want it?'
    )
    expect(
      make(experiment()).find('[data-testid="ux-reply-confirm"]').exists()
    ).toBe(true)
  })

  it('draws the heading and the sentence before the first read, with no replies counted yet', () => {
    const w = make(experiment({ result: null }))

    expect(w.find('.ux-exp-h').text()).toBe('Still want it?')
    expect(w.find('.what').text()).toContain(
      'Freegle asks the replier whether they still want it'
    )
    expect(w.find('.what').text()).toContain('sent twice')
    expect(w.find('.none').text()).toBe('No replies counted yet.')
    expect(w.find('table').exists()).toBe(false)
    expect(w.find('.reasons-toggle').exists()).toBe(false)

    // A read with no reasons in it, or the wrong shape, is the same.
    for (const result of [
      { detail: {} },
      { detail: { reasons: [] } },
      { detail: { reasons: 'x' } },
    ]) {
      expect(make(experiment({ result })).find('.none').exists()).toBe(true)
    }
  })

  it('shows each measure for the two arms with the count behind it', () => {
    const text = make(experiment()).find('.all').text()

    for (const want of [
      '40.0% (40/100)',
      '10.0% (10/100)',
      '30.0% (30/100)',
      '15.0% (15/100)',
      '50.0% (50/100)',
      '100.0% (100/100)',
      '25.0% (15/60)',
      '23.3% (14/60)',
    ]) {
      expect(text, want).toContain(want)
    }
    // Every measure is named in words a person reads.
    for (const name of [
      'Replies',
      'Asked',
      'Said yes',
      'Wrote again instead, which counts as yes',
      'Sent twice, which counts as yes',
      'Said no',
      'Did not answer',
      'Still waiting',
      'Reached the poster',
      'Counted',
      'Promised or taken',
    ]) {
      expect(text, name).toContain(name)
    }
    // No asked and a share of none is 0.0%, not a blank.
    expect(text).toContain('0.0% (0/100)')
  })

  it('shows a dash for the answer rows of the arm that was not asked', () => {
    const rows = make(experiment())
      .find('.all')
      .findAll('tr')
      .map((r) => r.findAll('td').map((c) => c.text()))
    const row = (name) => rows.find((r) => r[0] === name)

    // Control has no one asked, so a share of the asked has nothing to divide by.
    for (const name of [
      'Said yes',
      'Wrote again instead, which counts as yes',
      'Sent twice, which counts as yes',
      'Said no',
      'Did not answer',
    ]) {
      expect(row(name)[1], name).toBe('–')
    }
    expect(row('Sent twice, which counts as yes')[2]).toBe('0.0% (0/100)')
    expect(row('Replies')).toEqual(['Replies', '100', '100'])
  })

  it('puts the arm that is not asked first and names the arms in words', () => {
    // The read lists the asked arm first. The columns put the arm that is not asked first.
    const flipped = {
      ...reasons,
      all: { ask: reasons.all.ask, control: reasons.all.control },
    }
    const w = make(experiment({ result: { detail: { reasons: flipped } } }), {
      ...uxStubs,
      'b-table': tableWithHeaders,
    })

    const heads = w
      .find('.all')
      .findAll('th')
      .map((h) => h.text())
    expect(heads).toEqual(['', 'Without the question', 'With the question'])
  })

  it('shows a table for each reason after the toggle, in the order damaged, car, both, and none before it', async () => {
    const w = make(experiment())

    expect(w.findAll('.reason')).toHaveLength(0)
    expect(w.find('.reasons-toggle').text()).toBe('Show each reason')
    expect(w.find('.reasons-toggle').attributes('aria-expanded')).toBe('false')

    await w.find('.reasons-toggle').trigger('click')

    expect(w.findAll('.reason-name').map((n) => n.text())).toEqual([
      'Looks damaged',
      'Needs a car',
      'Both',
    ])
    expect(w.findAll('.reason')).toHaveLength(3)
    expect(w.find('.reasons-toggle').text()).toBe('Hide the reasons')
    expect(w.find('.reasons-toggle').attributes('aria-expanded')).toBe('true')
    // The first reason's own figures: 20 said yes of the 50 asked.
    expect(w.findAll('.reason')[0].text()).toContain('40.0% (20/50)')

    await w.find('.reasons-toggle').trigger('click')
    expect(w.findAll('.reason')).toHaveLength(0)

    // A reason the read does not have is left out, and with only the total there is no toggle.
    const onlyDamaged = { all: reasons.all, damaged: reasons.damaged }
    const partial = make(
      experiment({ result: { detail: { reasons: onlyDamaged } } })
    )
    await partial.find('.reasons-toggle').trigger('click')
    expect(partial.findAll('.reason-name').map((n) => n.text())).toEqual([
      'Looks damaged',
    ])
    const total = make(
      experiment({ result: { detail: { reasons: { all: reasons.all } } } })
    )
    expect(total.find('.all').exists()).toBe(true)
    expect(total.find('.reasons-toggle').exists()).toBe(false)
  })

  it('does not say when members are reached, which the block above it says', () => {
    const w = make(experiment())

    expect(w.text()).not.toMatch(/served until/i)
    expect(w.text()).not.toMatch(/decision on/i)
  })

  it('needs no store: it mounts and reads with no pinia installed', () => {
    // No plugins at all: a store would fail to resolve.
    const w = mount(ModSysAdminUxReplyConfirmDetail, {
      props: { experiment: experiment() },
      global: { stubs: uxStubs, plugins: [] },
    })

    expect(w.find('.all').exists()).toBe(true)
    expect(w.text()).toContain('40.0% (40/100)')
  })
})
