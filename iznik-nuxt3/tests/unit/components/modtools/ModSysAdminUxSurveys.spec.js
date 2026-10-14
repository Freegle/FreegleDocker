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
    question:
      'Which part of Freegle works best for you, and which most needs improving?',
    status: 'open',
    responses: 40,
    tallies: [
      {
        key: 'give',
        label: 'Offering something',
        best: 20,
        worst: 4,
        count: 0,
      },
      {
        key: 'login',
        label: 'Signing in or joining',
        best: 2,
        worst: 18,
        count: 0,
      },
    ],
    dont_care: 3,
    dont_understand: 1,
    skipped: 5,
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
      {
        key: 'know',
        label: "Yes, but I don't use it",
        best: 0,
        worst: 0,
        count: 3,
      },
      { key: 'no', label: "No, I didn't know", best: 0, worst: 0, count: 2 },
      {
        key: 'dont_understand',
        label: "Don't understand",
        best: 0,
        worst: 0,
        count: 0,
      },
    ],
    dont_care: 0,
    dont_understand: 1,
    comments: [],
  },
  {
    prompt_id: 11,
    kind: 'open',
    question: 'Was there anything that got in your way?',
    status: 'closed',
    responses: 100,
    nothing: 30,
    dont_understand: 10,
    skipped: 4,
    analysed: true,
    tallies: [],
    comments: [],
  },
  {
    prompt_id: 12,
    kind: 'survey',
    question: 'Which of these gets in your way most, and which least?',
    status: 'open',
    responses: 20,
    tallies: [
      { key: 'problem:1', label: 'Slow photos', best: 9, worst: 1, count: 0 },
      { key: 'problem:2', label: 'Many emails', best: 3, worst: 8, count: 0 },
    ],
    dont_care: 2,
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
    expect(t).toContain(
      '3 said none of these stood out; 1 did not understand the question.'
    )
    expect(t).toContain('5 skipped it.')
    // Worst first: login (18) before give (4).
    expect(t.indexOf('Signing in or joining')).toBeLessThan(
      t.indexOf('Offering something')
    )
  })

  it('tallies an awareness question per answer', () => {
    const t = make().text()
    expect(t).toContain('Did you know you can promise')
    expect(t).toContain('10 answers')
    expect(t).toContain("No, I didn't know")
    expect(t).toContain('2 (20%)')
    expect(t).toContain('4 (40%)')
  })

  it('counts "don\'t understand" in an awareness table, matching the note beneath it', () => {
    const w = make()
    const survey = w.findAll('.survey')[1]
    // The tally row carries 0 from the server, the figure comes from dont_understand: 1 of 10.
    expect(survey.text()).toContain("Don't understand")
    expect(survey.text()).toContain('1 (10%)')
    expect(survey.text()).toContain('1 did not understand the question.')
    // The "none of these bother them" sentence means nothing for awareness.
    expect(survey.text()).not.toContain('none of these')
  })

  it('shows an open question as counts only, never the text, with its analysis state', () => {
    const w = make()
    const open = w.findAll('.survey')[2]
    expect(open.find('.open-counts').text()).toBe(
      '60 wrote something, 30 said nothing came to mind, 10 did not understand the question. The answers have been analysed; see the problems above.'
    )
    expect(open.text()).toContain('4 skipped it.')
    expect(open.text()).toContain('100 answers')
    expect(open.text()).not.toContain('none of these')
  })

  it('says when an open question has not been analysed', () => {
    store.experiments.data.surveys[2].analysed = false
    expect(make().text()).toContain('The answers have not been analysed yet.')
  })

  it('words the follow-up survey in its own terms and lists the biggest nuisance first', () => {
    const w = make()
    const follow = w.findAll('.survey')[3]
    const tables = w.findAllComponents(uxStubs['b-table'])
    // The first survey table is the best and worst one; the follow-up's is the last.
    expect(tables[0].props('fields').map((f) => f.label)).toEqual([
      'Area',
      'Works best',
      'Most needs work',
    ])
    expect(
      tables
        .at(-1)
        .props('fields')
        .map((f) => f.label)
    ).toEqual(['Problem', 'Gets in the way most', 'Bothers least'])
    expect(follow.text().indexOf('Slow photos')).toBeLessThan(
      follow.text().indexOf('Many emails')
    )
  })

  it('keeps member comments behind a toggle, with the count', async () => {
    const w = make()
    expect(w.text()).not.toContain('Signing in on my phone is a pain')
    const toggle = w.find('.survey .said .toggle')
    expect(toggle.text()).toContain('What members wrote (2)')
    await toggle.trigger('click')
    expect(w.text()).toContain('Signing in on my phone is a pain')
  })

  it('escapes what members wrote', async () => {
    store.experiments.data.surveys[0].comments = [
      '<img src=x onerror=alert(1)>',
    ]
    const w = make()
    await w.find('.survey .said .toggle').trigger('click')
    expect(w.find('.survey img').exists()).toBe(false)
    expect(w.text()).toContain('<img src=x onerror=alert(1)>')
  })

  it('says how many are shown when there are more than are listed', async () => {
    store.experiments.data.surveys[0].comments = Array.from(
      { length: 20 },
      (_, i) => `comment ${i}`
    )
    const w = make()
    await w.find('.survey .said .toggle').trigger('click')
    expect(w.text()).toContain('Showing 8 of 20.')
    expect(w.text()).toContain('The newest 20 are all that is kept here.')
    expect(w.text()).not.toContain('comment 8')
  })

  it('warns that members prefer what they know', () => {
    expect(make().text()).toContain('prefer what they know')
  })

  it('shows the empty message when nothing has been asked', () => {
    store.experiments.data = { flows: [], surveys: [] }
    expect(make().text()).toContain('No survey or awareness questions')
  })
})
