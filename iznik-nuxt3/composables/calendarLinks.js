import { escapeIcsText } from '~/composables/useCalendarEvent'

// Builds "add to calendar" links and .ics files for a single event.
// Event shape: { name, description, location, startDate: 'YYYY-MM-DD',
// startTime: 'HH:MM' (empty = all-day), endTime: 'HH:MM' (optional),
// timeZone: IANA name }.

const pad = (n, len = 2) => String(n).padStart(len, '0')

function parseDate(s) {
  const m = /^(\d{4})-(\d{2})-(\d{2})$/.exec(s || '')
  return m ? { y: +m[1], mo: +m[2], d: +m[3] } : null
}

function parseTime(s) {
  const m = /^(\d{1,2}):(\d{2})/.exec(s || '')
  return m ? { h: +m[1], mi: +m[2] } : null
}

// Offset (ms) of a time zone from UTC at a given instant.
function zoneOffset(ms, timeZone) {
  const parts = new Intl.DateTimeFormat('en-US', {
    timeZone,
    hourCycle: 'h23',
    year: 'numeric',
    month: 'numeric',
    day: 'numeric',
    hour: 'numeric',
    minute: 'numeric',
    second: 'numeric',
  }).formatToParts(new Date(ms))
  const p = {}
  parts.forEach((x) => {
    p[x.type] = +x.value
  })
  const asUtc = Date.UTC(p.year, p.month - 1, p.day, p.hour, p.minute, p.second)
  return asUtc - Math.floor(ms / 1000) * 1000
}

// Wall-clock time in a zone -> UTC Date. Iterates so DST changes resolve.
export function zonedToUtc(date, time, timeZone) {
  const wall = Date.UTC(date.y, date.mo - 1, date.d, time.h, time.mi)
  const tz = timeZone || 'Europe/London'
  let utc = wall
  try {
    utc = wall - zoneOffset(wall, tz)
    utc = wall - zoneOffset(utc, tz)
  } catch (e) {
    // Unknown zone: treat the wall-clock time as UTC.
  }
  return new Date(utc)
}

// UTC Date -> YYYYMMDDTHHMMSSZ
export function formatUtc(d) {
  return (
    pad(d.getUTCFullYear(), 4) +
    pad(d.getUTCMonth() + 1) +
    pad(d.getUTCDate()) +
    'T' +
    pad(d.getUTCHours()) +
    pad(d.getUTCMinutes()) +
    pad(d.getUTCSeconds()) +
    'Z'
  )
}

function dayString(d) {
  return (
    pad(d.getUTCFullYear(), 4) + pad(d.getUTCMonth() + 1) + pad(d.getUTCDate())
  )
}

/**
 * Work out the event's start and end. All-day events (no start time) have
 * allDay: true, start at midnight UTC of the date, and an exclusive end the
 * next day. Timed events are converted from the event's zone to UTC; a missing
 * or non-later end time becomes start + 1 hour.
 * Returns null if the date is unusable.
 */
export function eventTimes(ev) {
  const date = parseDate(ev.startDate)
  if (!date) return null

  const st = parseTime(ev.startTime)
  if (!st) {
    const start = new Date(Date.UTC(date.y, date.mo - 1, date.d))
    return {
      allDay: true,
      start,
      end: new Date(start.getTime() + 24 * 3600 * 1000),
    }
  }

  const start = zonedToUtc(date, st, ev.timeZone)
  const et = parseTime(ev.endTime)
  let end = et ? zonedToUtc(date, et, ev.timeZone) : null
  if (!end || end <= start) {
    end = new Date(start.getTime() + 3600 * 1000)
  }
  return { allDay: false, start, end }
}

// RFC 5545 TEXT escaping is shared with the chat-link button.
export const icsEscape = escapeIcsText

// Fold a content line at 75 octets (UTF-8), continuation lines start with a
// space. Never splits a multi-byte character.
export function foldLine(line) {
  const enc = new TextEncoder()
  if (enc.encode(line).length <= 75) return line

  const out = []
  let cur = ''
  let curBytes = 0
  let limit = 75
  for (const ch of line) {
    const b = enc.encode(ch).length
    if (curBytes + b > limit) {
      out.push(cur)
      cur = ''
      curBytes = 0
      limit = 74 // The leading space takes one octet.
    }
    cur += ch
    curBytes += b
  }
  out.push(cur)
  return out.join('\r\n ')
}

/**
 * Build an iCalendar document. `now` and `uid` are injectable for tests.
 */
export function buildIcs(ev, now = new Date(), uid = null) {
  const t = eventTimes(ev)
  if (!t) return null

  const id =
    uid ||
    formatUtc(t.start) +
      '-' +
      (hash(ev.name + ev.startDate) >>> 0) +
      '@ilovefreegle.org'

  const lines = [
    'BEGIN:VCALENDAR',
    'VERSION:2.0',
    'PRODID:-//Freegle//Add to calendar//EN',
    'CALSCALE:GREGORIAN',
    'METHOD:PUBLISH',
    'BEGIN:VEVENT',
    'UID:' + id,
    'DTSTAMP:' + formatUtc(now),
  ]

  if (t.allDay) {
    lines.push('DTSTART;VALUE=DATE:' + dayString(t.start))
    lines.push('DTEND;VALUE=DATE:' + dayString(t.end))
  } else {
    lines.push('DTSTART:' + formatUtc(t.start))
    lines.push('DTEND:' + formatUtc(t.end))
  }

  lines.push('SUMMARY:' + icsEscape(ev.name))
  if (ev.description) lines.push('DESCRIPTION:' + icsEscape(ev.description))
  if (ev.location) lines.push('LOCATION:' + icsEscape(ev.location))
  lines.push('END:VEVENT', 'END:VCALENDAR')

  return lines.map(foldLine).join('\r\n') + '\r\n'
}

function hash(s) {
  let h = 0
  for (let i = 0; i < s.length; i++) {
    h = (Math.imul(31, h) + s.charCodeAt(i)) | 0
  }
  return h
}

function query(params) {
  return Object.entries(params)
    .filter(([, v]) => v !== undefined && v !== null && v !== '')
    .map(([k, v]) => encodeURIComponent(k) + '=' + encodeURIComponent(v))
    .join('&')
}

export function googleUrl(ev) {
  const t = eventTimes(ev)
  if (!t) return null
  const dates = t.allDay
    ? dayString(t.start) + '/' + dayString(t.end)
    : formatUtc(t.start) + '/' + formatUtc(t.end)
  return (
    'https://calendar.google.com/calendar/render?' +
    query({
      action: 'TEMPLATE',
      text: ev.name,
      dates,
      details: ev.description,
      location: ev.location,
    })
  )
}

export function microsoft365Url(ev) {
  const t = eventTimes(ev)
  if (!t) return null
  const iso = (d) => d.toISOString().replace(/\.\d{3}Z$/, 'Z')
  return (
    'https://outlook.office.com/calendar/0/action/compose?' +
    query({
      rru: 'addevent',
      path: '/calendar/action/compose',
      subject: ev.name,
      startdt: iso(t.start),
      enddt: iso(t.end),
      allday: t.allDay ? 'true' : undefined,
      body: ev.description,
      location: ev.location,
    })
  )
}

export function yahooUrl(ev) {
  const t = eventTimes(ev)
  if (!t) return null
  const f = (d) => (t.allDay ? dayString(d) : formatUtc(d))
  return (
    'https://calendar.yahoo.com/?' +
    query({
      v: 60,
      title: ev.name,
      st: f(t.start),
      et: f(t.end),
      dur: t.allDay ? 'allday' : undefined,
      desc: ev.description,
      in_loc: ev.location,
    })
  )
}
