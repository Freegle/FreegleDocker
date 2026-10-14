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
    expect(w.text()).toContain('mentioned by 14 of 120 who answered')
    expect(w.text()).toContain('mentioned by 9 of 100 who answered')
    expect(w.text()).not.toContain('220')
  })

  it('shows a value against the answers to the question it came from', () => {
    const w = make({
      mode: 'values',
      items: [{ id: 5, title: 'It is free', mentions: 30, answers: 100 }],
    })
    expect(w.text()).toContain('mentioned by 30 of 100 who answered')
  })

  it('says how many members were asked in the ranking line', () => {
    const w = make({
      items: [problem({ weight: 0.4, asked: 80 })],
    })
    expect(w.text()).toContain(
      '40% more of the 80 members asked said it gets in their way most than least'
    )
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
