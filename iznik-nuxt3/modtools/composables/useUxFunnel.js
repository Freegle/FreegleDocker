// Turns the per-step rollup rows of one flow into funnels a person can read: steps in the order
// members meet them, one funnel per route family (the desktop and mobile Give and Ask flows are
// different pages), the loss at each step, and a headline sentence naming the worst step.
import { FLOW_ROUTES } from '~/composables/useFlowRoutes'

// Flows whose steps are states rather than routes, in the order they happen.
const STATE_ORDER = {
  reply: [
    'JOINING_GROUP',
    'CREATING_CHAT',
    'COMPOSING',
    'VALIDATING',
    'AUTHENTICATING',
    'SHOWING_WELCOME',
    'COMPLETED',
  ],
  login: ['signup', 'signin', 'forgot'],
  chat_promise: ['choose'],
  chat_outcome: ['choose'],
  settings: ['settings'],
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
  const i = order.indexOf(step)
  return i === -1 ? 1000 : i + 1
}

/**
 * The funnels for one flow: [{ family, steps: [{ step, label, entered, visits, abandoned, backed,
 * completed, reach, lossRate }] }], steps ordered as members meet them. `reach` is the share of
 * the first step's first visits that reached this step, which is what the bar widths show.
 * Steps nobody entered are left out.
 */
export function funnelsFor(flow, steps) {
  const groups = {}
  for (const s of steps || []) {
    if (!s.entered && !s.visits) continue
    const f = family(flow, s.step)
    groups[f] ??= []
    groups[f].push(s)
  }
  const out = []
  for (const f of ['', 'desktop', 'mobile']) {
    if (!groups[f]) continue
    const ordered = groups[f]
      .slice()
      .sort(
        (a, b) =>
          rank(flow, a.step) - rank(flow, b.step) || b.entered - a.entered
      )
    const base = ordered[0]?.entered || 0
    out.push({
      family: f,
      steps: ordered.map((s) => ({
        step: s.step,
        label: stepLabel(s.step),
        entered: s.entered || 0,
        visits: s.visits || 0,
        abandoned: s.abandoned || 0,
        backed: s.backed || 0,
        completed: s.completed || 0,
        reach: base ? Math.min(1, (s.entered || 0) / base) : 0,
        lossRate: s.visits ? (s.abandoned || 0) / s.visits : 0,
      })),
    })
  }
  return out
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

  const candidates = (steps || []).filter((s) => (s.visits || 0) >= 20)
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
