import { describe, it, expect } from 'vitest'
import { formatLogins } from '~/composables/useMergeLogins'

describe('formatLogins', () => {
  it('handles a user whose logins array is undefined without throwing (Sentry 7384446789)', () => {
    // The API sometimes returns a user with no `logins` field at all —
    // this previously crashed the merge page with
    // "Cannot read properties of undefined (reading 'forEach')".
    expect(() => formatLogins({ id: 1 })).not.toThrow()
    expect(formatLogins({ id: 1 })).toBe('')
  })

  it('handles a null user without throwing', () => {
    expect(() => formatLogins(null)).not.toThrow()
    expect(formatLogins(null)).toBe('')
  })

  it('handles an empty logins array', () => {
    expect(formatLogins({ logins: [] })).toBe('')
  })

  it('maps a single Native login to "Email/Password"', () => {
    expect(formatLogins({ logins: [{ type: 'Native' }] })).toBe('Email/Password')
  })

  it('maps each known provider type', () => {
    const user = {
      logins: [
        { type: 'Native' },
        { type: 'Facebook' },
        { type: 'Yahoo' },
        { type: 'Google' },
      ],
    }
    expect(formatLogins(user)).toBe('Email/Password, Facebook, Yahoo, Google')
  })

  it('deduplicates repeated providers', () => {
    const user = {
      logins: [
        { type: 'Native' },
        { type: 'Native' },
        { type: 'Google' },
        { type: 'Google' },
      ],
    }
    expect(formatLogins(user)).toBe('Email/Password, Google')
  })

  it('ignores unknown login types silently', () => {
    const user = {
      logins: [
        { type: 'Native' },
        { type: 'Twitter' },
        { type: 'Apple' },
      ],
    }
    expect(formatLogins(user)).toBe('Email/Password')
  })

  it('returns empty string when logins is a non-array truthy value', () => {
    // Defensive: if the API returns a wrong-shaped value, don't crash.
    expect(formatLogins({ logins: 'Native' })).toBe('')
    expect(formatLogins({ logins: { type: 'Native' } })).toBe('')
  })
})
