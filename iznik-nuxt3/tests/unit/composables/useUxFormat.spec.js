import { describe, it, expect } from 'vitest'
import {
  pct,
  withCount,
  ms,
  signed,
  sumBy,
  stepsWithPrevious,
  ofAnswered,
  ideaSourceLabel,
} from '~/modtools/composables/useUxFormat'

describe('useUxFormat', () => {
  it('pct gives one decimal, or a dash when there is no denominator', () => {
    expect(pct(1, 3)).toBe('33.3%')
    expect(pct(0, 10)).toBe('0.0%')
    expect(pct(5, 0)).toBe('–')
    expect(pct(undefined, undefined)).toBe('–')
  })

  it('withCount always shows the counts beside the percentage', () => {
    expect(withCount(1200, 4800)).toBe('25.0% (1,200/4,800)')
    expect(withCount(0, 0)).toBe('– (0/0)')
    expect(withCount(null, null)).toBe('– (0/0)')
  })

  it('ms shows milliseconds under a second and seconds above', () => {
    expect(ms(850)).toBe('850 ms')
    expect(ms(12500)).toBe('12.5 s')
    expect(ms(null)).toBe('–')
    expect(ms(undefined)).toBe('–')
  })

  it('signed puts a sign on a number', () => {
    expect(signed(12.34)).toBe('+12.3')
    expect(signed(-3)).toBe('-3.0')
    expect(signed(0)).toBe('0.0')
    expect(signed(null)).toBe('–')
  })

  it('sumBy adds a field across rows, ignoring missing values', () => {
    expect(sumBy([{ a: 1 }, { a: 2 }, {}], 'a')).toBe(3)
    expect(sumBy(undefined, 'a')).toBe(0)
  })

  it('stepsWithPrevious pairs each step with the same step last period', () => {
    const rows = stepsWithPrevious({
      flows: [
        {
          flow: 'post',
          steps: [
            { step: 'a', entered: 5 },
            { step: 'b', entered: 6 },
          ],
        },
      ],
      previous: {
        flows: [{ flow: 'post', steps: [{ step: 'a', entered: 9 }] }],
      },
    })
    expect(rows).toHaveLength(2)
    expect(rows[0]).toMatchObject({ flow: 'post', step: 'a', entered: 5 })
    expect(rows[0].prev.entered).toBe(9)
    expect(rows[1].prev).toBeNull()
    expect(stepsWithPrevious(undefined)).toEqual([])
  })

  it('ofAnswered shows a count only below 100 answers and a percentage from 100', () => {
    expect(ofAnswered(18, 40)).toBe('18 of 40')
    expect(ofAnswered(18, 99)).toBe('18 of 99')
    expect(ofAnswered(18, 100)).toBe('18 of 100 (18%)')
    expect(ofAnswered(1200, 4800)).toBe('1,200 of 4,800 (25%)')
    expect(ofAnswered(3, 0)).toBe('3')
    expect(ofAnswered(undefined, undefined)).toBe('0')
    // A ranking's base is how often an option was shown; the threshold is still the answers.
    expect(ofAnswered(30, 150, 60)).toBe('30 of 150')
    expect(ofAnswered(30, 150, 150)).toBe('30 of 150 (20%)')
  })

  it('ideaSourceLabel names every source an idea can have, members included', () => {
    expect(ideaSourceLabel('ai')).toBe('suggested by the loop')
    expect(ideaSourceLabel('survey')).toBe('from the survey')
    expect(ideaSourceLabel('human')).toBe('from a person')
    expect(ideaSourceLabel('member')).toBe('suggested by a member')
    expect(ideaSourceLabel('other')).toBe('other')
  })
})
