import { describe, it, expect } from 'vitest'
import { mount } from '@vue/test-utils'
import ModSysAdminUxProblems from '~/modtools/components/ModSysAdminUxProblems.vue'
import { uxStubs } from './uxStubs'

const make = (props) =>
  mount(ModSysAdminUxProblems, {
    props: { title: 'Problems members raised', ...props },
    global: { stubs: uxStubs },
  })

const problem = (over) => ({
  id: 1,
  title: 'Photos are slow',
  description: '',
  source: 'members',
  mentions: 14,
  answers: 120,
  asked: 0,
  weight: null,
  status: 'open',
  ideas: [],
  experiments: [],
  ...over,
})

describe('ModSysAdminUxProblems', () => {
  it('shows each problem against the answers to its own question', () => {
    // A problems question with 120 answers and a values question with 100: neither borrows the
    // other's count, and the sum (220) never appears.
    const w = make({
      items: [
        problem({ id: 1, mentions: 14, answers: 120 }),
        problem({ id: 2, title: 'Too many emails', mentions: 9, answers: 100 }),
      ],
    })
    expect(w.text()).toContain('mentioned by 14 of the 120 who answered (12%)')
    expect(w.text()).toContain('mentioned by 9 of the 100 who answered (9%)')
    expect(w.text()).not.toContain('220')
  })

  it('shows a value against the answers to the question it came from', () => {
    const w = make({
      mode: 'values',
      items: [{ id: 5, title: 'It is free', mentions: 30, answers: 100 }],
    })
    expect(w.text()).toContain('mentioned by 30 of the 100 who answered (30%)')
  })

  it('gives counts without percentages when fewer than 100 answered', () => {
    const w = make({
      items: [problem({ id: 1, mentions: 14, answers: 45 })],
    })
    expect(w.text()).toContain('mentioned by 14 of the 45 who answered')
    expect(w.text()).not.toContain('%')
  })

  it('never prints a margin of error', () => {
    const w = make({ items: [problem({ mentions: 14, answers: 120 })] })
    expect(w.text()).not.toMatch(/margin|confidence|±|plus or minus/i)
  })

  it('says how many members were asked in the ranking line, from 100 answers', () => {
    const w = make({
      items: [problem({ weight: 0.4, asked: 150 })],
    })
    expect(w.text()).toContain(
      'picked as getting in their way most 40% more than least, per time it was shown to the 150 members asked'
    )
  })

  it('gives no percentage in the ranking line below 100 answers', () => {
    const w = make({
      items: [problem({ weight: 0.4, asked: 80 })],
    })
    expect(w.text()).toContain('ranked by the 80 members asked')
    expect(w.text()).not.toContain('40%')
  })

  it('gives the counts behind a ranking below 100 answers when the server has them', () => {
    const w = make({
      items: [
        problem({
          weight: 0.4,
          asked: 80,
          most: 12,
          least: 2,
          times_shown: 40,
        }),
      ],
    })
    expect(w.text()).toContain(
      '12 said it got in their way most and 2 least in 40 showings, of the 80 asked'
    )
  })

  describe('quotes', () => {
    const quoted = problem({
      quotes: [
        { answer_id: 4, span: 'photos take ages to upload' },
        { answer_id: 9, span: 'the upload hangs on my phone' },
      ],
    })

    it('keeps quotes behind a toggle, with the count', async () => {
      const w = make({ items: [quoted] })
      expect(w.text()).not.toContain('photos take ages to upload')
      const toggle = w.find('.said .toggle')
      expect(toggle.text()).toContain('What members wrote (2)')
      await toggle.trigger('click')
      expect(w.text()).toContain('photos take ages to upload')
      expect(w.text()).toContain('the upload hangs on my phone')
    })

    it('escapes a quote', async () => {
      const w = make({
        items: [
          problem({
            quotes: [{ answer_id: 1, span: '<img src=x onerror=alert(1)>' }],
          }),
        ],
      })
      await w.find('.said .toggle').trigger('click')
      expect(w.find('.problem img').exists()).toBe(false)
      expect(w.text()).toContain('<img src=x onerror=alert(1)>')
    })

    it('reads quotes from evidence too, and shows none when there are none', () => {
      const viaEvidence = make({
        items: [
          problem({
            evidence: { quotes: [{ answer_id: 2, span: 'it froze' }] },
          }),
        ],
      })
      expect(viaEvidence.find('.said').exists()).toBe(true)
      const none = make({ items: [problem({ quotes: [] })] })
      expect(none.find('.said').exists()).toBe(false)
    })

    it('shows quotes for a value as well', async () => {
      const w = make({
        mode: 'values',
        items: [
          {
            id: 5,
            title: 'It is free',
            mentions: 30,
            answers: 100,
            quotes: [{ answer_id: 3, span: 'free is the whole point' }],
          },
        ],
      })
      await w.find('.said .toggle').trigger('click')
      expect(w.text()).toContain('free is the whole point')
    })
  })

  it('does not invent a base for a problem that did not come from a question', () => {
    const w = make({
      items: [problem({ source: 'ai', mentions: 0, answers: 0 })],
    })
    expect(w.text()).not.toContain('who answered')
    expect(w.text()).toContain('noticed by the idea generator')
  })

  it('says when a problem has not been ranked yet', () => {
    const w = make({ items: [problem()] })
    expect(w.text()).toContain('not ranked by other members yet')
  })
})
