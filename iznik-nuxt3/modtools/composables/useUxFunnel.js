// Turns the per-step rollup rows of one flow into funnels a person can read: steps in the order
// members meet them, one funnel per route family (the desktop and mobile Give and Ask flows are
// different pages), the loss at each step, and a headline sentence naming the worst step.
import { FLOW_ROUTES } from '~/composables/useFlowRoutes'

// Flows whose steps are states rather than routes, in the order they happen. An entry that is an
// array is a set of alternatives: members take one of them, not each in turn, so they share a rank
// and are drawn as one stage (see funnelsFor).
const STATE_ORDER = {
  // The order of useReplyStateMachine: the flow starts when the member types, they are only
  // joined to the community and given a chat after sending and, if need be, signing in.
  reply: [
    'COMPOSING',
    'VALIDATING',
    'AUTHENTICATING',
    'JOINING_GROUP',
    'CREATING_CHAT',
    'SHOWING_WELCOME',
    'COMPLETED',
  ],
  login: [['signup', 'signin']],
  chat_promise: ['choose'],
  chat_outcome: ['choose'],
  settings: ['settings'],
}

// Steps that are a way out of the flow rather than a step along it, and what to call the exit.
// Someone who goes to reset their password has not been lost: they are on their way back. They are
// drawn beside the funnel and are never named as the worst step.
export const EXIT_STEPS = {
  login: { forgot: 'Went to reset their password' },
}

// What to call a step on the chart. Route names are for developers; these are for the people
// reading the tab.
const STEP_LABELS = {
  give: 'Item and photos',
  'give-mobile': 'Start',
  'give-mobile-photos': 'Photos',
  'give-clearance': 'Clearance start',
  'give-whereami': 'Location',
  'give-mobile-details': 'Details',
  'give-options': 'Options',
  'give-mobile-options': 'Options',
  'give-whoami': 'Who you are',
  'give-mobile-whereami': 'Location and who you are',
  ask: 'Item and photos',
  'ask-mobile': 'Start',
  'ask-mobile-photos': 'Photos',
  'ask-whereami': 'Location',
  'ask-mobile-details': 'Details',
  'ask-whoami': 'Who you are',
  'ask-mobile-whereami': 'Location and who you are',
  JOINING_GROUP: 'Joining the community',
  CREATING_CHAT: 'Opening the chat',
  COMPOSING: 'Writing the reply',
  VALIDATING: 'Checking the reply',
  AUTHENTICATING: 'Signing in',
  SHOWING_WELCOME: 'Welcome',
  COMPLETED: 'Sent',
  signup: 'Joining',
  signin: 'Signing in',
  forgot: 'Forgotten password',
  choose: 'Choosing',
  settings: 'Settings',
}

export const FLOW_TITLES = {
  give: 'Offering something',
  ask: 'Asking for something',
  reply: 'Replying to a post',
  login: 'Signing in or joining',
  chat_promise: 'Promising an item',
  chat_outcome: 'Marking taken or received',
  settings: 'Settings',
}

export function flowTitle(flow) {
  return FLOW_TITLES[flow] || flow
}

export function stepLabel(step) {
  return STEP_LABELS[step] || step
}

// The family a routed step belongs to: 'mobile' for the mobile pages, 'desktop' otherwise. Flows
// driven by states have one family, ''.
function family(flow, step) {
  if (!FLOW_ROUTES[flow]) return ''
  return step.includes('mobile') ? 'mobile' : 'desktop'
}

function rank(flow, step) {
  if (FLOW_ROUTES[flow]) {
    const r = FLOW_ROUTES[flow][step]
    return r === undefined ? 1000 : r
  }
  const order = STATE_ORDER[flow] || []
  const i = order.findIndex((o) =>
    Array.isArray(o) ? o.includes(step) : o === step
  )
  return i === -1 ? 1000 : i + 1
}

const num = (v) => v || 0

function figures(s) {
  return {
    step: s.step,
    label: stepLabel(s.step),
    entered: num(s.entered),
    visits: num(s.visits),
    abandoned: num(s.abandoned),
    backed: num(s.backed),
    completed: num(s.completed),
    lossRate: s.visits ? num(s.abandoned) / s.visits : 0,
  }
}

// Steps that share a rank are different ways into the same stage, not stages in a row. Their
// figures are added up for the stage, and each way in is kept in `alternatives` with its own
// figures, so a reader never sees one drawn as a drop-off from the other.
function stageOf(members, base) {
  if (members.length === 1) {
    const m = figures(members[0])
    return { ...m, reach: base ? Math.min(1, m.entered / base) : 0 }
  }
  const alts = members
    .map(figures)
    .sort((a, b) => b.entered - a.entered)
    .map((m) => ({ ...m, reach: base ? Math.min(1, m.entered / base) : 0 }))
  const sum = (k) => alts.reduce((t, a) => t + a[k], 0)
  const visits = sum('visits')
  const abandoned = sum('abandoned')
  const entered = sum('entered')
  return {
    // The commonest way in stands for the stage when something needs one key.
    step: alts[0].step,
    label: alts.map((a) => a.label).join(' or '),
    entered,
    visits,
    abandoned,
    backed: sum('backed'),
    completed: sum('completed'),
    reach: base ? Math.min(1, entered / base) : 0,
    lossRate: visits ? abandoned / visits : 0,
    alternatives: alts,
  }
}

/**
 * The funnels for one flow: [{ family, steps: [stage], exits: [{ step, label, entered, visits }] }],
 * stages ordered as members meet them. A stage is { step, label, entered, visits, abandoned, backed,
 * completed, reach, lossRate, alternatives? }. `reach` is the share of the first stage's first
 * arrivals that reached this stage, which is what the bar widths show. `lossRate` is abandoned over
 * visits. A stage with `alternatives` is several steps members take instead of one another (the
 * normal Give start and the clearance start; signing in and joining), shown as one stage.
 * Exits (forgotten password) are not stages. Steps nobody entered are left out.
 */
export function funnelsFor(flow, steps) {
  const exitNames = EXIT_STEPS[flow] || {}
  const groups = {}
  const exitGroups = {}
  for (const s of steps || []) {
    if (!s.entered && !s.visits) continue
    if (exitNames[s.step]) {
      exitGroups[''] ??= []
      exitGroups[''].push(s)
      continue
    }
    const f = family(flow, s.step)
    groups[f] ??= []
    groups[f].push(s)
  }
  const out = []
  for (const f of ['', 'desktop', 'mobile']) {
    if (!groups[f]) continue
    // Group by rank; unknown steps (rank 1000) each stand alone rather than being run together.
    const byRank = new Map()
    for (const s of groups[f]) {
      const r = rank(flow, s.step)
      const key = r === 1000 ? `x:${s.step}` : r
      if (!byRank.has(key)) byRank.set(key, { r, members: [] })
      byRank.get(key).members.push(s)
    }
    const slots = [...byRank.values()].sort(
      (a, b) =>
        a.r - b.r ||
        Math.max(...b.members.map((m) => num(m.entered))) -
          Math.max(...a.members.map((m) => num(m.entered)))
    )
    const base = slots[0].members.reduce((t, m) => t + num(m.entered), 0)
    out.push({
      family: f,
      steps: slots.map((slot) => stageOf(slot.members, base)),
      exits: (exitGroups[f] || []).map((s) => ({
        step: s.step,
        label: exitNames[s.step],
        entered: num(s.entered),
        visits: num(s.visits),
      })),
    })
  }
  // A flow with only an exit step still shows it.
  if (!out.length && exitGroups['']) {
    out.push({
      family: '',
      steps: [],
      exits: exitGroups[''].map((s) => ({
        step: s.step,
        label: exitNames[s.step],
        entered: num(s.entered),
        visits: num(s.visits),
      })),
    })
  }
  return out.filter((fn) => fn.steps.length || fn.exits.length)
}

function pct(v) {
  return `${Math.round(v * 100)}%`
}

/**
 * One sentence per flow, from the outcomes (started, completed) and the step with the worst loss
 * (abandoned over visits, at least 20 visits so a tiny step cannot win), with its worst device.
 * `previous` is the same flow's outcome in the period before, or null.
 */
export function headline(flow, outcome, steps, previous) {
  if (!outcome || !outcome.started) {
    return `${flowTitle(flow)}: nobody started in this period.`
  }
  let text = `${flowTitle(flow)}: ${outcome.started.toLocaleString()} started, ${outcome.completed.toLocaleString()} finished (${pct(
    outcome.completed / outcome.started
  )}`
  if (previous?.started) {
    text += `, before that ${pct(previous.completed / previous.started)}`
  }
  text += ').'

  // Exits are not steps that lose people, so they never win. Steps that are alternatives to one
  // another each count on their own figures.
  const exits = EXIT_STEPS[flow] || {}
  const candidates = (steps || []).filter(
    (s) => (s.visits || 0) >= 20 && !exits[s.step]
  )
  if (!candidates.length) return text
  const worst = candidates.reduce((a, b) =>
    (b.abandoned || 0) / b.visits > (a.abandoned || 0) / a.visits ? b : a
  )
  if (!worst.abandoned) {
    return `${text} No step lost many people.`
  }
  text += ` Biggest loss: ${pct(worst.abandoned / worst.visits)} of the ${worst.visits.toLocaleString()} visits to ${stepLabel(
    worst.step
  )} ended there`
  const byDevice = (worst.by?.device_class || []).filter(
    (d) => (d.visits || 0) >= 20
  )
  if (byDevice.length > 1) {
    const w = byDevice.reduce((a, b) =>
      (b.abandoned || 0) / b.visits > (a.abandoned || 0) / a.visits ? b : a
    )
    text += `, worst on ${deviceLabel(w.key)} (${pct(w.abandoned / w.visits)})`
  }
  return text + '.'
}

export function deviceLabel(key) {
  return (
    {
      mobile: 'mobile web',
      desktop: 'desktop',
      tablet: 'tablet',
      'app-ios': 'the iOS app',
      'app-android': 'the Android app',
    }[key] || key
  )
}

export function familyLabel(f) {
  return { desktop: 'Desktop pages', mobile: 'Mobile pages' }[f] || ''
}
