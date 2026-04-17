import { describe, it, expect } from 'vitest'
import { normalizeAuthoritySearch } from '~/composables/useAuthoritySearch'

describe('normalizeAuthoritySearch', () => {
  const a = (id, name) => ({ id, name, area_code: 'Ward' })

  it('accepts the V2 Go shape (bare array) without crashing', () => {
    const results = [a(1, 'Essex County'), a(2, 'Wessex Ward')]
    expect(normalizeAuthoritySearch(results)).toEqual(results)
  })

  it('accepts the V1 PHP shape ({ authorities: [...] })', () => {
    const results = { authorities: [a(1, 'Essex County'), a(2, 'Wessex Ward')] }
    expect(normalizeAuthoritySearch(results)).toEqual(results.authorities)
  })

  it('caps the number of results at the default limit of 5', () => {
    const big = Array.from({ length: 12 }, (_, i) => a(i, 'Authority ' + i))
    expect(normalizeAuthoritySearch(big)).toHaveLength(5)
  })

  it('honours a custom limit', () => {
    const big = Array.from({ length: 12 }, (_, i) => a(i, 'Authority ' + i))
    expect(normalizeAuthoritySearch(big, 3)).toHaveLength(3)
  })

  it('filters out entries missing a name', () => {
    const results = [a(1, 'Essex County'), { id: 2 }, null, a(3, 'Wessex Ward')]
    const ret = normalizeAuthoritySearch(results)
    expect(ret).toHaveLength(2)
    expect(ret.map((r) => r.id)).toEqual([1, 3])
  })

  it('returns an empty array for null/undefined input', () => {
    expect(normalizeAuthoritySearch(null)).toEqual([])
    expect(normalizeAuthoritySearch(undefined)).toEqual([])
  })

  it('returns an empty array for unexpected shapes', () => {
    expect(normalizeAuthoritySearch({})).toEqual([])
    expect(normalizeAuthoritySearch({ authorities: null })).toEqual([])
    expect(normalizeAuthoritySearch('Essex')).toEqual([])
  })

  it('returns an empty array when the array is empty', () => {
    expect(normalizeAuthoritySearch([])).toEqual([])
    expect(normalizeAuthoritySearch({ authorities: [] })).toEqual([])
  })
})
