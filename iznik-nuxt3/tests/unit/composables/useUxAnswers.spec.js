import { describe, it, expect } from 'vitest'
import {
  PERCENT_MIN_ANSWERS,
  ofAnswered,
  countOf,
  whoAnswered,
  mentionedBy,
  rankedLine,
  kindOf,
  kindLabel,
  KIND_LABELS,
} from '~/modtools/composables/useUxAnswers'

describe('useUxAnswers', () => {
  it('gives a percentage only from 100 answers', () => {
    expect(PERCENT_MIN_ANSWERS).toBe(100)
  })

  it('ofAnswered shows a count only below 100 answers and a percentage from 100', () => {
    expect(ofAnswered(18, 40)).toBe('18 of 40')
    expect(ofAnswered(18, 99)).toBe('18 of 99')
    expect(ofAnswered(18, 100)).toBe('18 of 100 (18%)')
    expect(ofAnswered(1200, 4800)).toBe('1,200 of 4,800 (25%)')
    expect(ofAnswered(3, 0)).toBe('3')
    expect(ofAnswered(undefined, undefined)).toBe('0')
    // The base can differ from the answers: a percentage is decided by how many members answered.
    expect(ofAnswered(30, 150, 60)).toBe('30 of 150')
    expect(ofAnswered(30, 150, 150)).toBe('30 of 150 (20%)')
  })

  describe('countOf', () => {
    it('uses the singular for exactly one and the plural for the rest, including none', () => {
      expect(countOf(1, 'problem')).toBe('1 problem')
      expect(countOf(0, 'problem')).toBe('0 problems')
      expect(countOf(3, 'problem')).toBe('3 problems')
    })

    it('puts a separator in a large number, and takes a plural of its own', () => {
      expect(countOf(1200, 'answer')).toBe('1,200 answers')
      expect(countOf(2, 'person', 'people')).toBe('2 people')
      expect(countOf(1, 'person', 'people')).toBe('1 person')
    })

    it('reads a missing or bad number as none', () => {
      expect(countOf(undefined, 'time')).toBe('0 times')
      expect(countOf('x', 'time')).toBe('0 times')
    })
  })

  describe('whoAnswered', () => {
    it('gives a count out of the members who answered below 100 answers', () => {
      expect(whoAnswered(14, 45)).toBe('14 of 45 who answered')
    })

    it('adds the percentage after a comma from 100 answers, not in brackets of its own', () => {
      expect(whoAnswered(14, 120)).toBe('14 of 120 who answered, 12%')
      expect(whoAnswered(30, 100)).toBe('30 of 100 who answered, 30%')
      expect(whoAnswered(14, 120)).not.toContain('(')
    })

    it('has no "of" without a base', () => {
      expect(whoAnswered(14, 0)).toBe('14 members')
      expect(whoAnswered(1, undefined)).toBe('1 member')
    })

    it('separates thousands', () => {
      expect(whoAnswered(1200, 4800)).toBe('1,200 of 4,800 who answered, 25%')
    })
  })

  describe('mentionedBy', () => {
    it('gives a count out of the members who answered below 100 answers, with no percentage', () => {
      expect(mentionedBy(14, 45)).toBe(
        'Mentioned by 14 of the 45 members who answered.'
      )
    })

    it('adds the percentage from 100 answers', () => {
      expect(mentionedBy(14, 120)).toBe(
        'Mentioned by 14 of the 120 members who answered (12%).'
      )
      expect(mentionedBy(30, 100)).toBe(
        'Mentioned by 30 of the 100 members who answered (30%).'
      )
    })

    it('has no "of" when the problem did not come from a question', () => {
      expect(mentionedBy(14, 0)).toBe('Mentioned by 14 members.')
      expect(mentionedBy(1, 0)).toBe('Mentioned by 1 member.')
      expect(mentionedBy(undefined, undefined)).toBe('Mentioned by 0 members.')
    })

    it('separates thousands', () => {
      expect(mentionedBy(1200, 4800)).toBe(
        'Mentioned by 1,200 of the 4,800 members who answered (25%).'
      )
    })
  })

  describe('rankedLine', () => {
    it('says nothing while nobody has been asked about the problem', () => {
      expect(rankedLine({ times_shown: null, most: null, asked: 0 })).toBe('')
      expect(rankedLine({})).toBe('')
      expect(rankedLine(undefined)).toBe('')
      expect(rankedLine({ times_shown: 0, most: 0, asked: 20 })).toBe('')
    })

    it('gives the counts below 100 members asked, with no percentage', () => {
      expect(rankedLine({ times_shown: 40, most: 12, asked: 80 })).toBe(
        'Other members were shown it 40 times and 12 picked it as the biggest problem.'
      )
    })

    it('adds the percentage of the times it was shown from 100 members asked', () => {
      expect(rankedLine({ times_shown: 40, most: 12, asked: 100 })).toBe(
        'Other members were shown it 40 times and 12 picked it as the biggest problem (30%).'
      )
    })

    it('says "1 time" for one showing and keeps a count of none', () => {
      expect(rankedLine({ times_shown: 1, most: 0, asked: 3 })).toBe(
        'Other members were shown it 1 time and 0 picked it as the biggest problem.'
      )
    })
  })

  describe('kindOf', () => {
    const survey = (keys) => ({
      kind: 'survey',
      tallies: keys.map((key) => ({ key })),
    })

    it('calls a survey whose options are keyed problem: the problem ranking', () => {
      expect(kindOf(survey(['problem:1', 'problem:2']))).toBe('problems')
      expect(kindLabel(survey(['problem:1']))).toBe('Problem ranking')
    })

    it('calls any other survey the area ranking', () => {
      expect(kindOf(survey(['give', 'ask']))).toBe('areas')
      expect(kindOf({ kind: 'survey' })).toBe('areas')
      expect(kindLabel(survey(['give']))).toBe('Area ranking')
    })

    it('knows an awareness question and an open question', () => {
      expect(kindOf({ kind: 'awareness', tallies: [] })).toBe('awareness')
      expect(kindLabel({ kind: 'awareness' })).toBe('Know about it')
      expect(kindOf({ kind: 'open', tallies: [] })).toBe('open')
      expect(kindLabel({ kind: 'open' })).toBe('Open question')
    })

    it('does not read an awareness question by its option keys', () => {
      expect(
        kindOf({ kind: 'awareness', tallies: [{ key: 'problem:1' }] })
      ).toBe('awareness')
    })

    it('has plain labels with no funnel key or the word flow in them', () => {
      expect(Object.values(KIND_LABELS)).toEqual([
        'Problem ranking',
        'Area ranking',
        'Know about it',
        'Open question',
      ])
      for (const label of Object.values(KIND_LABELS)) {
        expect(label).not.toMatch(/\bflow\b/i)
      }
    })
  })
})
