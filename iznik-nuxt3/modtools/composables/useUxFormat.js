// Formatting shared by the SysAdmin UX panels. Percentages are always shown
// with the counts behind them so a small base is obvious.

export function pct(n, d) {
  if (!d) return '–'
  return `${(((n || 0) / d) * 100).toFixed(1)}%`
}

export function withCount(n, d) {
  return `${pct(n, d)} (${(n || 0).toLocaleString()}/${(d || 0).toLocaleString()})`
}

export function ms(v) {
  if (v === null || v === undefined) return '–'
  if (v < 1000) return `${Math.round(v)} ms`
  return `${(v / 1000).toFixed(1)} s`
}

export function signed(v) {
  if (v === null || v === undefined || Number.isNaN(v)) return '–'
  const s = Number(v).toFixed(1)
  return v > 0 ? `+${s}` : s
}

export function sumBy(rows, key) {
  return (rows || []).reduce((t, r) => t + (r?.[key] || 0), 0)
}

// Every { flow, ...step } in a funnels response, paired with the same step in
// the previous period (or null when the previous period has no such step).
export function stepsWithPrevious(funnels) {
  const out = []
  for (const f of funnels?.flows || []) {
    const pf = funnels?.previous?.flows?.find((p) => p.flow === f.flow)
    for (const s of f.steps || []) {
      out.push({
        flow: f.flow,
        ...s,
        prev: pf?.steps?.find((p) => p.step === s.step) || null,
      })
    }
  }
  return out
}

// Members who answer a prompt chose to, and tend to be keen and to prefer what they know, so a
// share of them is not a share of members. Below this many answers the next answer still often
// adds something new, so a count is shown and a percentage would claim more than it knows. No
// margin of error is ever printed: it does not apply to a sample that picked itself.
export const PERCENT_MIN_ANSWERS = 100

// "18 of 40" below the threshold, "18 of 120 (15%)" at or above it. The base is the number who
// answered (or, for one option in a ranking, the number of times it was shown, in which case
// `answers` is still how many members answered, because that is what decides whether a percentage
// is fair).
export function ofAnswered(n, total, answers = total) {
  const count = Number(n) || 0
  const base = Number(total) || 0
  if (!base) return String(count)
  const of = `${count.toLocaleString()} of ${base.toLocaleString()}`
  if ((Number(answers) || 0) < PERCENT_MIN_ANSWERS) return of
  return `${of} (${Math.round((count / base) * 100)}%)`
}
