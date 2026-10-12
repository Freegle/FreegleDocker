import { describe, it, expect } from 'vitest'
import {
  pct,
  withCount,
  ms,
  signed,
  sumBy,
  stepsWithPrevious,
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
})
