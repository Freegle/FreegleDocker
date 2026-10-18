import { describe, it, expect } from 'vitest'
import {
  buildIcs,
  eventTimes,
  foldLine,
  formatUtc,
  googleUrl,
  icsEscape,
  microsoft365Url,
  yahooUrl,
} from '~/composables/calendarLinks'

const ev = {
  name: 'Handover: Alice, Bob',
  description: 'Line one; line two\nLine three',
  location: '1 High St, Town',
  startDate: '2026-07-01',
  startTime: '14:00',
  endTime: '14:15',
  timeZone: 'Europe/London',
}

const NOW = new Date(Date.UTC(2026, 5, 1, 9, 30, 5))

describe('icsEscape', () => {
  it('escapes backslash, semicolon, comma and newlines', () => {
    expect(icsEscape('a,b;c\\d\ne\r\nf')).toBe('a\\,b\;c\\\\d\\ne\\nf')
  })
})

describe('foldLine', () => {
  it('leaves short lines alone', () => {
    expect(foldLine('SUMMARY:short')).toBe('SUMMARY:short')
  })

  it('folds at 75 octets with a leading space on continuations', () => {
    const folded = foldLine('X:' + 'a'.repeat(200))
    const parts = folded.split('\r\n')
    expect(parts.length).toBeGreaterThan(1)
    const enc = new TextEncoder()
    expect(enc.encode(parts[0]).length).toBe(75)
    parts.slice(1).forEach((p) => {
      expect(p.startsWith(' ')).toBe(true)
      expect(enc.encode(p).length).toBeLessThanOrEqual(75)
    })
    expect(parts.map((p, i) => (i ? p.slice(1) : p)).join('')).toBe(
      'X:' + 'a'.repeat(200)
    )
  })

  it('never splits a multi-byte character', () => {
    const line = 'X:' + 'é'.repeat(100)
    const parts = foldLine(line).split('\r\n')
    const enc = new TextEncoder()
    parts.forEach((p) => expect(enc.encode(p).length).toBeLessThanOrEqual(75))
    expect(parts.map((p, i) => (i ? p.slice(1) : p)).join('')).toBe(line)
  })
})

describe('eventTimes', () => {
  it('converts BST wall time to UTC', () => {
    const t = eventTimes(ev)
    expect(formatUtc(t.start)).toBe('20260701T130000Z')
    expect(formatUtc(t.end)).toBe('20260701T131500Z')
    expect(t.allDay).toBe(false)
  })

  it('converts GMT wall time to UTC', () => {
    const t = eventTimes({ ...ev, startDate: '2026-01-15' })
    expect(formatUtc(t.start)).toBe('20260115T140000Z')
  })

  it('uses other zones', () => {
    const t = eventTimes({ ...ev, timeZone: 'America/New_York' })
    expect(formatUtc(t.start)).toBe('20260701T180000Z')
  })

  it('defaults a missing or earlier end to one hour', () => {
    expect(formatUtc(eventTimes({ ...ev, endTime: '' }).end)).toBe(
      '20260701T140000Z'
    )
    expect(formatUtc(eventTimes({ ...ev, endTime: '13:00' }).end)).toBe(
      '20260701T140000Z'
    )
  })

  it('treats no start time as all-day with an exclusive end', () => {
    const t = eventTimes({ ...ev, startTime: '' })
    expect(t.allDay).toBe(true)
    expect(formatUtc(t.end)).toBe('20260702T000000Z')
  })

  it('returns null for a bad date', () => {
    expect(eventTimes({ ...ev, startDate: 'nope' })).toBeNull()
  })
})

describe('buildIcs', () => {
  it('builds a timed event with CRLF endings, UTC times, UID and DTSTAMP', () => {
    const ics = buildIcs(ev, NOW, 'abc@example')
    expect(ics.endsWith('\r\n')).toBe(true)
    expect(ics.replace(/\r\n/g, '')).not.toMatch(/[\r\n]/)
    const lines = ics.split('\r\n')
    expect(lines[0]).toBe('BEGIN:VCALENDAR')
    expect(lines).toContain('UID:abc@example')
    expect(lines).toContain('DTSTAMP:20260601T093005Z')
    expect(lines).toContain('DTSTART:20260701T130000Z')
    expect(lines).toContain('DTEND:20260701T131500Z')
    expect(lines).toContain('SUMMARY:Handover: Alice\\, Bob')
    expect(lines).toContain('DESCRIPTION:Line one\; line two\\nLine three')
    expect(lines).toContain('LOCATION:1 High St\\, Town')
  })

  it('builds an all-day event with DATE values', () => {
    const lines = buildIcs({ ...ev, startTime: '' }, NOW, 'x').split('\r\n')
    expect(lines).toContain('DTSTART;VALUE=DATE:20260701')
    expect(lines).toContain('DTEND;VALUE=DATE:20260702')
  })

  it('omits empty description and location', () => {
    const ics = buildIcs({ ...ev, description: '', location: '' }, NOW, 'x')
    expect(ics).not.toContain('DESCRIPTION')
    expect(ics).not.toContain('LOCATION')
  })

  it('folds long lines', () => {
    const ics = buildIcs({ ...ev, description: 'w '.repeat(100) }, NOW, 'x')
    ics
      .split('\r\n')
      .forEach((l) =>
        expect(new TextEncoder().encode(l).length).toBeLessThanOrEqual(75)
      )
  })

  it('generates a stable UID when none given', () => {
    expect(buildIcs(ev, NOW)).toMatch(
      /UID:20260701T130000Z-\d+@ilovefreegle\.org/
    )
  })
})

describe('web calendar links', () => {
  it('builds a Google link in UTC', () => {
    const u = new URL(googleUrl(ev))
    expect(u.origin + u.pathname).toBe(
      'https://calendar.google.com/calendar/render'
    )
    expect(u.searchParams.get('action')).toBe('TEMPLATE')
    expect(u.searchParams.get('text')).toBe(ev.name)
    expect(u.searchParams.get('dates')).toBe(
      '20260701T130000Z/20260701T131500Z'
    )
    expect(u.searchParams.get('details')).toBe(ev.description)
    expect(u.searchParams.get('location')).toBe(ev.location)
  })

  it('builds a Google all-day link', () => {
    const u = new URL(googleUrl({ ...ev, startTime: '' }))
    expect(u.searchParams.get('dates')).toBe('20260701/20260702')
  })

  it('builds a Microsoft 365 link', () => {
    const u = new URL(microsoft365Url(ev))
    expect(u.host).toBe('outlook.office.com')
    expect(u.searchParams.get('subject')).toBe(ev.name)
    expect(u.searchParams.get('startdt')).toBe('2026-07-01T13:00:00Z')
    expect(u.searchParams.get('enddt')).toBe('2026-07-01T13:15:00Z')
  })

  it('builds a Yahoo link', () => {
    const u = new URL(yahooUrl(ev))
    expect(u.host).toBe('calendar.yahoo.com')
    expect(u.searchParams.get('title')).toBe(ev.name)
    expect(u.searchParams.get('st')).toBe('20260701T130000Z')
    expect(u.searchParams.get('et')).toBe('20260701T131500Z')
    expect(u.searchParams.get('in_loc')).toBe(ev.location)
  })

  it('returns null for an unusable date', () => {
    expect(googleUrl({ ...ev, startDate: '' })).toBeNull()
  })
})
