'use strict'

/**
 * The live UX idea generator: POST /internal/ux-ideas in server.js, asked hourly by the batch's
 * ux:ideas-refresh. It gathers the evidence the batch sends (figures, survey answers, what has been
 * tried) with what only this container has (the code as it is now and six months of its history),
 * asks Claude for ideas through the same SDK path and subscription token as the support agent, and
 * returns validated ideas. The prompt and the output contract live in ux-ideas-prompt.js, which the
 * backtest shares, so this file is only plumbing: nothing here decides what a good idea is.
 *
 * docs/developers/reference/ux-telemetry.md section 8.
 */

const { execFile: execFileCb } = require('node:child_process')
const { promisify } = require('node:util')
const fs = require('node:fs')
const path = require('node:path')

const { FLOW_PATHS, IDEAS_SCHEMA, CATEGORIES, buildIdeasPrompt, clip } = require('./ux-ideas-prompt')
const { deepenHistory } = require('./codebase')
const { driverMode, billableCostUsd } = require('./auth')

const execFile = promisify(execFileCb)

const CODEBASE = process.env.CODEBASE_DIR || '/app/codebase'
// Same unpinned alias the support agent uses, for the same reason: dated snapshots get retired.
const MODEL = process.env.UX_IDEAS_MODEL || process.env.SUPPORT_AI_MODEL || 'opus'
const MAX_FILES = 16
const MAX_FILE_BYTES = 200 * 1024
const HISTORY_MONTHS = 6
const HISTORY_LIMIT = 150
const CODE_EXTENSIONS = new Set(['.vue', '.js', '.mjs', '.ts'])
const CATEGORY_KEYS = new Set(CATEGORIES.map((c) => c[0]))
const IDEA_REQUIRED = IDEAS_SCHEMA.properties.ideas.items.required
const JOURNEY_REQUIRED = IDEAS_SCHEMA.properties.journey.items.required

// ---------------------------------------------------------------------------
// Who may call the endpoint
// ---------------------------------------------------------------------------

/**
 * Is this a private (non-routable) address: loopback, RFC1918, an IPv6 loopback or unique-local
 * one, or an IPv4-mapped IPv6 form of those (Node reports `::ffff:10.0.0.5` for an IPv4 peer on a
 * dual-stack socket). Anything that is not a string we can parse is not private.
 */
function isPrivateAddress(ip) {
  if (typeof ip !== 'string') return false

  let addr = ip.trim().toLowerCase()
  // A zone id (fe80::1%eth0) is not part of the address.
  addr = addr.replace(/%.*$/, '')

  const mapped = addr.match(/^::ffff:(\d{1,3}(?:\.\d{1,3}){3})$/)
  if (mapped) addr = mapped[1]

  const v4 = addr.match(/^(\d{1,3})\.(\d{1,3})\.(\d{1,3})\.(\d{1,3})$/)
  if (v4) {
    const o = v4.slice(1).map(Number)
    if (o.some((n) => n > 255)) return false

    return (
      o[0] === 10 ||
      o[0] === 127 ||
      (o[0] === 172 && o[1] >= 16 && o[1] <= 31) ||
      (o[0] === 192 && o[1] === 168)
    )
  }

  if (addr === '::1') return true

  // IPv6 unique-local, fc00::/7: the form a docker network gets when IPv6 is on.
  return /^f[cd][0-9a-f]{2}:/.test(addr)
}

/**
 * The route's guard, kept here so it can be tested without importing the heavy server.js.
 *
 * Two conditions, both required: the peer is on a private address, and the caller sent the
 * header. The header is not a secret; it stops a stray request from another service on the
 * network reaching a route that spends the subscription. The real barrier is that the load
 * balancer only forwards /api/ai-support, so this path is never routed from outside.
 *
 * @param {object} req - an Express request (only socket.remoteAddress and headers are read).
 * @returns {{ok:boolean, error?:string}}
 */
function internalGuard(req) {
  const peer = req && req.socket && req.socket.remoteAddress

  if (!isPrivateAddress(peer)) {
    return { ok: false, error: 'FORBIDDEN' }
  }

  const header = req.headers && req.headers['x-freegle-internal']
  if (header !== '1') {
    return { ok: false, error: 'FORBIDDEN' }
  }

  return { ok: true }
}

/**
 * Turn a request body into the pieces generateIdeas takes, with sizes bounded. Returns an error
 * string for an unknown flow, which the route answers with 400.
 */
function cleanRequest(body) {
  const b = body && typeof body === 'object' ? body : {}

  if (typeof b.flow !== 'string' || !Object.prototype.hasOwnProperty.call(FLOW_PATHS, b.flow)) {
    return { error: 'flow must be one of ' + Object.keys(FLOW_PATHS).join(', ') }
  }

  const text = (v) => (typeof v === 'string' ? v.slice(0, 40000) : '')
  const tried = (Array.isArray(b.tried) ? b.tried : [])
    .filter((t) => t && typeof t === 'object' && typeof t.title === 'string' && t.title.trim())
    .slice(0, 200)
    .map((t) => ({
      title: t.title.slice(0, 200),
      status: typeof t.status === 'string' ? t.status.slice(0, 40) : '',
      decision: typeof t.decision === 'string' ? t.decision.slice(0, 600) : '',
    }))
  const n = Math.round(Number(b.count))
  const count = Number.isFinite(n) && n >= 1 ? Math.min(n, 30) : 12

  return { flow: b.flow, figures: text(b.figures), surveys: text(b.surveys), tried, count }
}

// ---------------------------------------------------------------------------
// What the container knows that the batch does not: the code and its history
// ---------------------------------------------------------------------------

function isoDate(d) {
  return d.toISOString().slice(0, 10)
}

function monthsAgo(months, now = new Date()) {
  const d = new Date(now.getTime())
  d.setUTCMonth(d.getUTCMonth() - months)

  return isoDate(d)
}

/**
 * Commit subjects touching a flow's files over the last six months, newest first, as
 * '2026-06-06 feat(compose): ...' lines (the form the prompt's history section uses).
 *
 * The container's checkout is shallow, so the history is fetched on demand first. git is run with
 * execFile and an argument list, never a shell string. Returns [] for an unknown flow, a missing
 * checkout or any git failure: no history is a thinner brief, not a failed run.
 *
 * @param {string} flow - a key of FLOW_PATHS.
 * @param {object} [opts]
 * @param {string} [opts.since] - YYYY-MM-DD; default six months back.
 * @param {string} [opts.codebaseDir]
 * @param {boolean} [opts.deepen] - fetch more history into a shallow checkout (default true).
 * @param {function} [opts.execFile] - injectable for tests.
 * @returns {Promise<string[]>}
 */
async function areaHistory(flow, opts = {}) {
  const paths = FLOW_PATHS[flow]
  if (!paths) return []

  const dir = opts.codebaseDir || CODEBASE
  const run = opts.execFile || execFile
  const since = /^\d{4}-\d{2}-\d{2}$/.test(opts.since || '') ? opts.since : monthsAgo(HISTORY_MONTHS)

  if (opts.deepen !== false) {
    await deepenHistory({ dir, since, execFile: run })
  }

  try {
    // --no-renames and no diff output: a path-limited log needs only trees, which matters when
    // the checkout was fetched without blobs.
    const { stdout } = await run(
      'git',
      ['log', `--since=${since}`, '--no-renames', '--format=%as %s', `-n${HISTORY_LIMIT}`, '--', ...paths],
      { cwd: dir, timeout: 30000, maxBuffer: 4 * 1024 * 1024 }
    )

    return String(stdout)
      .split('\n')
      .map((l) => l.trim())
      .filter(Boolean)
  } catch (e) {
    return []
  }
}

// Every code file under a directory, in a stable order, without following symlinks out of it.
function walk(absDir, depth = 0) {
  if (depth > 3) return []

  let entries
  try {
    entries = fs.readdirSync(absDir, { withFileTypes: true })
  } catch (e) {
    return []
  }

  entries.sort((a, b) => a.name.localeCompare(b.name))

  const files = []
  for (const e of entries) {
    const full = path.join(absDir, e.name)
    if (e.isDirectory()) {
      if (e.name === 'node_modules' || e.name.startsWith('.')) continue
      files.push(...walk(full, depth + 1))
    } else if (e.isFile() && CODE_EXTENSIONS.has(path.extname(e.name))) {
      files.push(full)
    }
  }

  return files
}

/**
 * The flow's files as they are in the checkout: [{path, content}], path relative to the checkout
 * root, pages first (the screens are what the ideas are about), at most 16 files. A directory in
 * FLOW_PATHS contributes every code file under it. Paths that do not exist are skipped, which is
 * how the old Find names stay harmless.
 */
function areaCode(flow, opts = {}) {
  const paths = FLOW_PATHS[flow]
  if (!paths) return []

  const root = path.resolve(opts.codebaseDir || CODEBASE)
  const pageFirst = [...paths].sort((a, b) => Number(b.includes('/pages/')) - Number(a.includes('/pages/')))
  const seen = new Set()
  const out = []

  for (const rel of pageFirst) {
    const abs = path.resolve(root, rel)
    // FLOW_PATHS is constants, but the guard is cheap and keeps a bad entry inside the checkout.
    if (abs !== root && !abs.startsWith(root + path.sep)) continue

    let stat
    try {
      stat = fs.statSync(abs)
    } catch (e) {
      continue
    }

    const candidates = stat.isDirectory() ? walk(abs) : stat.isFile() ? [abs] : []
    for (const file of candidates) {
      const relPath = path.relative(root, file).split(path.sep).join('/')
      if (seen.has(relPath)) continue

      let content
      try {
        if (fs.statSync(file).size > MAX_FILE_BYTES) continue
        content = fs.readFileSync(file, 'utf8')
      } catch (e) {
        continue
      }

      seen.add(relPath)
      out.push({ path: relPath, content })
      if (out.length >= MAX_FILES) return out
    }
  }

  return out
}

// ---------------------------------------------------------------------------
// Reading the model's answer
// ---------------------------------------------------------------------------

/**
 * Pull a JSON object out of a model reply. With the SDK's structured output this is not needed,
 * but if that option is ignored or fails the reply is text, and models wrap JSON in prose and
 * fences. Tries, in order: the whole text, each fenced block (last first, since the answer comes
 * after any thinking out loud), then the outermost braces. Returns null if nothing parses to an
 * object.
 */
function parseIdeasJson(text) {
  if (typeof text !== 'string') return null

  const tryParse = (s) => {
    try {
      const v = JSON.parse(s)

      return v && typeof v === 'object' && !Array.isArray(v) ? v : null
    } catch (e) {
      return null
    }
  }

  const whole = text.replace(/^﻿/, '').trim()
  let v = tryParse(whole)
  if (v) return v

  const fences = [...whole.matchAll(/```(?:json|JSON)?\s*\n?([\s\S]*?)```/g)].map((m) => m[1].trim())
  for (const f of fences.reverse()) {
    v = tryParse(f)
    if (v) return v
  }

  const first = whole.indexOf('{')
  const last = whole.lastIndexOf('}')
  if (first !== -1 && last > first) {
    v = tryParse(whole.slice(first, last + 1))
    if (v) return v
  }

  return null
}

const str = (v) => (typeof v === 'string' ? v.trim() : '')

function num(v) {
  if (typeof v === 'number') return Number.isFinite(v) ? v : null
  if (typeof v === 'string' && v.trim() !== '' && Number.isFinite(Number(v))) return Number(v)

  return null
}

/**
 * One idea, checked against IDEAS_SCHEMA's required keys and types, or null when it does not
 * pass. Numbers that arrive as strings are accepted; out-of-range scores are clamped rather than
 * rejected, because the model's estimate being 1.2 is not a reason to lose the idea.
 */
function validateIdea(raw) {
  if (!raw || typeof raw !== 'object') return null

  for (const key of IDEA_REQUIRED) {
    if (raw[key] === undefined || raw[key] === null) return null
  }

  const idea = {
    title: str(raw.title).slice(0, 80),
    category: str(raw.category),
    change: str(raw.change),
    rationale: str(raw.rationale),
    expected: str(raw.expected),
    risk: str(raw.risk),
  }
  for (const k of Object.keys(idea)) {
    if (!idea[k]) return null
  }
  if (!CATEGORY_KEYS.has(idea.category)) return null

  const points = num(raw.expected_points)
  const confidence = num(raw.confidence)
  const locality = num(raw.locality)
  if (points === null || confidence === null || locality === null) return null
  if (!Array.isArray(raw.files)) return null

  idea.expected_points = points
  idea.confidence = Math.min(1, Math.max(0, confidence))
  idea.locality = Math.min(5, Math.max(1, Math.round(locality)))
  idea.files = raw.files.filter((f) => typeof f === 'string' && f.trim()).map((f) => f.trim())
  idea.like_tried = str(raw.like_tried)

  return idea
}

function validateJourneyEntry(raw) {
  if (!raw || typeof raw !== 'object') return null

  const entry = {}
  for (const key of JOURNEY_REQUIRED) {
    entry[key] = str(raw[key])
    if (!entry[key]) return null
  }

  return entry
}

/**
 * Validate a parsed answer: keeps the well-formed ideas and journey entries, drops the rest, and
 * never throws on one bad entry. Throws only when nothing usable is left, because an answer with
 * no ideas is a failed run and the caller should see a failure, not an empty success.
 */
function normaliseAnswer(parsed, count = 12) {
  if (!parsed || typeof parsed !== 'object') {
    throw new Error('the model did not return a JSON object')
  }

  const ideas = (Array.isArray(parsed.ideas) ? parsed.ideas : []).map(validateIdea).filter(Boolean).slice(0, count)
  if (!ideas.length) {
    throw new Error('the model returned no valid ideas')
  }

  return {
    problem: str(parsed.problem),
    journey: (Array.isArray(parsed.journey) ? parsed.journey : []).map(validateJourneyEntry).filter(Boolean),
    ideas,
  }
}

// ---------------------------------------------------------------------------
// Asking the model
// ---------------------------------------------------------------------------

const SYSTEM_PROMPT = [
  'You are a product designer and front-end engineer helping Freegle, a UK reuse charity, find',
  'changes to its member-facing website that would help more members finish what they start.',
  'The Freegle code is checked out read-only in your working directory; you may read, grep and',
  'glob it to check how a screen behaves. You cannot change anything and must not try to.',
  'Your final answer is a single JSON object matching the schema in the request, and nothing else.',
  'If you cannot return it as structured output, put it in one ```json fenced block.',
].join(' ')

/**
 * One model call through the Claude Agent SDK, with Read/Grep/Glob confined to the checkout as the
 * support agent does and no other tools. Authentication is whichever credential auth.js finds, in
 * practice the geeks subscription token the helper already holds.
 *
 * @returns {Promise<{answer:object|null, text:string, costUsd:number, model:string}>}
 */
async function runIdeasQuery({ prompt, codebaseDir = CODEBASE, queryImpl, timeoutMs }) {
  // Required here, not at the top, so this module loads (and its tests run) where the SDK is not
  // installed, as in the bare `node --test` CI step.
  const query = queryImpl || require('@anthropic-ai/claude-agent-sdk').query

  const abort = new AbortController()
  const limit = timeoutMs || Number(process.env.UX_IDEAS_TIMEOUT_MS || 15 * 60 * 1000)
  const timer = setTimeout(() => abort.abort(), limit)

  const options = {
    model: MODEL,
    systemPrompt: SYSTEM_PROMPT,
    // The built-in set is cut to three read-only tools, and the write tools are refused as well in
    // case a future SDK default widens it. No MCP servers: this run needs no member data at all.
    tools: ['Read', 'Grep', 'Glob'],
    allowedTools: ['Read', 'Grep', 'Glob'],
    disallowedTools: ['Write', 'Edit', 'Bash', 'NotebookEdit', 'WebFetch', 'WebSearch', 'Task'],
    permissionMode: 'bypassPermissions',
    additionalDirectories: [codebaseDir],
    cwd: codebaseDir,
    // The prompt already carries the code, so a few lookups are all it should need.
    maxTurns: Number(process.env.UX_IDEAS_MAX_TURNS || 30),
    // Do not write a session file for an hourly job nobody will resume.
    persistSession: false,
    abortController: abort,
    outputFormat: { type: 'json_schema', schema: IDEAS_SCHEMA },
  }

  let text = ''
  let structured = null
  let costUsd = 0
  let model = MODEL
  let failure = ''

  try {
    for await (const message of query({ prompt, options })) {
      if (message.type === 'system' && message.subtype === 'init' && message.model) {
        model = message.model
      } else if (message.type === 'result') {
        if (message.subtype === 'success') {
          text = message.result || ''
          structured = message.structured_output && typeof message.structured_output === 'object' ? message.structured_output : null
          costUsd = billableCostUsd(driverMode(), message.total_cost_usd)
        } else {
          failure = (message.errors || []).map((e) => (e && e.message) || String(e)).join('; ') || message.subtype
        }
      }
    }
  } catch (e) {
    throw new Error(abort.signal.aborted ? `the model run timed out after ${limit} ms` : e.message || String(e))
  } finally {
    clearTimeout(timer)
  }

  if (failure) throw new Error('the model run failed: ' + failure)

  return { answer: structured || parseIdeasJson(text), text, costUsd, model }
}

/**
 * Generate ideas for one funnel.
 *
 * @param {object} o
 * @param {string} o.flow - a key of FLOW_PATHS.
 * @param {string} [o.figures] - plain-text rollup lines.
 * @param {string} [o.surveys] - plain-text survey and awareness lines.
 * @param {Array} [o.tried] - [{title, status, decision}] already run or rejected.
 * @param {number} [o.count] - ideas wanted (default 12).
 * @param {string} [o.codebaseDir]
 * @param {function} [o.queryImpl] - the SDK's query(), injectable for tests.
 * @param {function} [o.now] - () => Date, injectable for tests.
 * @returns {Promise<{problem:string, journey:Array, ideas:Array, costUsd:number, model:string}>}
 */
async function generateIdeas({ flow, figures, surveys, tried, count, codebaseDir, queryImpl, now }) {
  if (!Object.prototype.hasOwnProperty.call(FLOW_PATHS, flow)) {
    throw new Error('unknown flow: ' + flow)
  }

  const dir = codebaseDir || CODEBASE
  const wanted = count || 12
  const code = areaCode(flow, { codebaseDir: dir })
  if (!code.length) {
    // Ideas with no code to ground them are generic; failing says the checkout is missing.
    throw new Error('the codebase checkout has none of this flow\'s files: ' + dir)
  }

  const today = isoDate(now ? now() : new Date())
  const prompt = buildIdeasPrompt({
    flow,
    today,
    count: wanted,
    figures: clip(figures, 40000),
    surveys: clip(surveys, 40000),
    tried: tried || [],
    recentCommits: await areaHistory(flow, { codebaseDir: dir }),
    code,
  })

  const run = await runIdeasQuery({ prompt, codebaseDir: dir, queryImpl })
  const result = normaliseAnswer(run.answer, wanted)

  return { ...result, costUsd: run.costUsd, model: run.model }
}

// One generation at a time. Each is a long Opus run on the shared subscription token, which the
// support helper also uses, and the batch asks for eight flows in a row; running them side by
// side would only make each slower and trip the rate limit sooner.
let chain = Promise.resolve()
function serialise(fn) {
  const next = chain.then(() => fn())
  chain = next.catch(() => {})

  return next
}

module.exports = {
  isPrivateAddress,
  internalGuard,
  cleanRequest,
  areaHistory,
  areaCode,
  parseIdeasJson,
  validateIdea,
  normaliseAnswer,
  runIdeasQuery,
  generateIdeas,
  serialise,
}
