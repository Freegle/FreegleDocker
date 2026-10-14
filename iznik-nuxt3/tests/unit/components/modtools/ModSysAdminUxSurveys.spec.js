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
    none: 3,
    dont_understand: 1,
    skipped: 5,
    comments: ['Signing in on my phone is a pain', 'Love it'],
  },
  {
    prompt_id: 10,
    kind: 'awareness',
    flow: 'chat_promise',
    question: 'Do you know how to promise an item in a chat?',
    status: 'closed',
    responses: 10,
    yes: 4,
    no: 5,
    not_sure: 1,
    decoy: false,
    tallies: [],
    dont_care: 0,
    dont_understand: 0,
    comments: [],
  },
  {
    prompt_id: 11,
    kind: 'open',
    question: 'Did anything cause you a problem?',
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
    question: 'Which of these has been the biggest problem for you?',
    status: 'open',
    responses: 20,
    tallies: [
      {
        key: 'problem:1',
        label: 'Slow photos',
        times_shown: 12,
        most: 9,
        least: 1,
        count: 0,
      },
      {
        key: 'problem:2',
        label: 'Many emails',
        times_shown: 10,
        most: 3,
        least: 8,
        count: 0,
      },
    ],
    none: 2,
    dont_understand: 0,
    comments: [],
  },
  {
    prompt_id: 13,
    kind: 'awareness',
    flow: 'general',
    question: 'Do you know how to sell an item for money?',
    status: 'closed',
    responses: 20,
    yes: 8,
    no: 10,
    not_sure: 2,
    decoy: true,
    tallies: [],
    dont_understand: 0,
    comments: [],
  },
  {
    prompt_id: 14,
    kind: 'survey',
    question: 'Which of these has been the biggest problem for you?',
    status: 'closed',
    responses: 240,
    tallies: [
      {
        key: 'problem:1',
        label: 'Slow photos',
        times_shown: 120,
        most: 60,
        least: 6,
        count: 0,
      },
    ],
    none: 0,
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

  it('tallies a best and worst survey, worst first, as counts below 100 answers', () => {
    const t = make().text()
    expect(t).toContain('40 answered, still open')
    expect(t).toContain('Of the 40 who answered')
    expect(t).toContain('Signing in or joining')
    expect(t).toContain('18 of 40')
    expect(t).toContain('20 of 40')
    expect(t).not.toContain('45%')
    expect(t).toContain(
      '3 said none of these; 1 did not understand the question.'
    )
    expect(t).toContain('5 skipped it.')
    // Worst first: login (18) before give (4).
    expect(t.indexOf('Signing in or joining')).toBeLessThan(
      t.indexOf('Offering something')
    )
  })

  it('tallies an awareness question as yes, no and not sure, counts only', () => {
    const survey = make().findAll('.survey')[1]
    const t = survey.text()
    expect(t).toContain('Do you know how to promise an item in a chat')
    expect(t).toContain('10 answered')
    expect(t).toContain('4 of 10')
    expect(t).toContain('5 of 10')
    expect(t).toContain('1 of 10')
    expect(t).not.toContain('%')
    // The "none of these" sentence means nothing for awareness.
    expect(t).not.toContain('none of these')
  })

  it('says what the right answer is and how many gave it, per kind of question', () => {
    const w = make()
    const real = w.findAll('.survey')[1]
    const decoy = w.findAll('.survey')[4]
    expect(real.find('.right-answer').text()).toBe(
      'Right answer (Yes): 4 of 10.'
    )
    expect(decoy.find('.right-answer').text()).toBe(
      'Right answer (No): 10 of 20.'
    )
    expect(decoy.text()).toContain('Decoy: Freegle does not do this')
    expect(real.text()).not.toContain('Decoy')
  })

  it('shows the yes-saying rate across decoys beside each real question, and not on a decoy', () => {
    const w = make()
    const real = w.findAll('.survey')[1]
    const decoy = w.findAll('.survey')[4]
    expect(real.find('[data-testid="decoy-check"]').text()).toContain(
      'across 1 decoy question about things Freegle does not do, 8 of 20 answers were Yes'
    )
    expect(decoy.find('[data-testid="decoy-check"]').exists()).toBe(false)
  })

  it('shows no decoy line when there are no decoy questions', () => {
    store.experiments.data.surveys = store.experiments.data.surveys.filter(
      (s) => !s.decoy
    )
    expect(make().find('[data-testid="decoy-check"]').exists()).toBe(false)
  })

  it('still reads an older awareness question made of tally rows', () => {
    store.experiments.data.surveys = [
      {
        prompt_id: 1,
        kind: 'awareness',
        question: 'Did you know you can promise?',
        status: 'closed',
        responses: 10,
        tallies: [
          { key: 'use', label: 'Yes, and I use it', count: 4 },
          { key: 'no', label: "No, I didn't know", count: 2 },
        ],
        dont_understand: 1,
        comments: [],
      },
    ]
    const t = make().text()
    expect(t).toContain("No, I didn't know")
    expect(t).toContain('2 of 10')
    expect(t).toContain('1 did not understand the question.')
  })

  it('adds percentages beside the counts from 100 answers, against the times shown', () => {
    const big = make().findAll('.survey')[5]
    expect(big.text()).toContain('Of the 240 who answered.')
    expect(big.text()).toContain('60 of 120 (50%)')
    expect(big.text()).toContain('6 of 120 (5%)')
  })

  it('never prints a margin of error', () => {
    expect(make().text()).not.toMatch(/margin of error|confidence interval|±/i)
  })

  it('shows an open question as counts only, never the text, with its analysis state', () => {
    const w = make()
    const open = w.findAll('.survey')[2]
    expect(open.find('.open-counts').text()).toBe(
      'Of the 100 who answered, 60 wrote something, 30 had nothing to tell us, 10 did not understand the question. The answers have been analysed; see the problems above.'
    )
    expect(open.text()).toContain('4 skipped it.')
    expect(open.text()).toContain('100 answered')
    expect(open.text()).not.toContain('none of these')
  })

  it('says how many written answers the analysis has not read', () => {
    store.experiments.data.surveys[2].analysed_answers = 25
    const t = make().findAll('.survey')[2].find('.open-counts').text()
    expect(t).toContain(
      'The problems above were drawn from 25 of the 60 written answers; 35 have arrived since and have not been read.'
    )
    expect(t).not.toContain('The answers have been analysed')
  })

  it('does not claim a gap when the analysis read every written answer', () => {
    store.experiments.data.surveys[2].analysed_answers = 60
    expect(make().findAll('.survey')[2].find('.open-counts').text()).toContain(
      'The answers have been analysed; see the problems above.'
    )
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
    ).toEqual(['Problem', 'Times shown', 'Biggest problem', 'Smallest problem'])
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
