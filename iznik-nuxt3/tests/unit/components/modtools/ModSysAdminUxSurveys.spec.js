import { describe, it, expect, beforeEach } from 'vitest'
import { mount } from '@vue/test-utils'
import { setActivePinia, createPinia } from 'pinia'
import ModSysAdminUxSurveys from '~/modtools/components/ModSysAdminUxSurveys.vue'
import { useUxStore } from '~/modtools/stores/ux'
import { uxStubs } from './uxStubs'

const surveys = [
  {
    prompt_id: 9,
    kind: 'survey',
    question: 'Which part of Freegle works best for you, and which most needs improving?',
    status: 'open',
    responses: 40,
    tallies: [
      { key: 'give', label: 'Offering something', best: 20, worst: 4, count: 0 },
      { key: 'login', label: 'Signing in or joining', best: 2, worst: 18, count: 0 },
    ],
    dont_care: 3,
    dont_understand: 1,
    comments: ['Signing in on my phone is a pain', 'Love it'],
  },
  {
    prompt_id: 10,
    kind: 'awareness',
    flow: 'chat_promise',
    question: 'Did you know you can promise an item to someone in a chat?',
    status: 'closed',
    responses: 10,
    tallies: [
      { key: 'use', label: 'Yes, and I use it', best: 0, worst: 0, count: 4 },
      { key: 'know', label: "Yes, but I don't use it", best: 0, worst: 0, count: 3 },
      { key: 'no', label: "No, I didn't know", best: 0, worst: 0, count: 3 },
    ],
    dont_care: 0,
    dont_understand: 0,
    comments: [],
  },
]

describe('ModSysAdminUxSurveys', () => {
  let store
  beforeEach(() => {
    setActivePinia(createPinia())
    store = useUxStore()
    store.experiments.data = { flows: [], surveys }
  })

  const make = () => mount(ModSysAdminUxSurveys, { global: { stubs: uxStubs } })

  it('tallies a best and worst survey, worst first, with percentages and the escapes', () => {
    const t = make().text()
    expect(t).toContain('40 answers, still open')
    expect(t).toContain('Signing in or joining')
    expect(t).toContain('18 (45%)')
    expect(t).toContain('20 (50%)')
    expect(t).toContain('3 said none of these bother them; 1 did not understand the question.')
    expect(t).toContain('Signing in on my phone is a pain')
    // Worst first: login (18) before give (4).
    expect(t.indexOf('Signing in or joining')).toBeLessThan(t.indexOf('Offering something'))
  })

  it('tallies an awareness question per answer', () => {
    const t = make().text()
    expect(t).toContain('Did you know you can promise')
    expect(t).toContain('10 answers')
    expect(t).toContain("No, I didn't know")
    expect(t).toContain('3 (30%)')
    expect(t).toContain('4 (40%)')
  })

  it('warns that members prefer what they know', () => {
    expect(make().text()).toContain('prefer what they know')
  })

  it('shows the empty message when nothing has been asked', () => {
    store.experiments.data = { flows: [], surveys: [] }
    expect(make().text()).toContain('No survey or awareness questions')
  })
})
