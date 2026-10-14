'use strict'

/**
 * The live UX idea generator: POST /internal/ux-ideas in server.js, asked daily by the batch's
 * ux:ideas-refresh. It gathers the evidence the batch sends (figures, survey answers, the problems
 * members raised and what they value, reports, what has been tried) with what only this container
 * has (the code as it is now and six months of its history), asks Claude for problems and then
 * ideas that solve them through the same SDK path and subscription token as the support agent, and
 * returns validated problems and ideas. The prompt and the output contract live in ux-ideas-prompt.js, which the
 * backtest shares, so this file is only plumbing: nothing here decides what a good idea is.
 *
 * docs/developers/reference/ux-telemetry.md section 8.
 */

const { execFile: execFileCb } = require('node:child_process')
const { promisify } = require('node:util')
const fs = require('node:fs')
const path = require('node:path')

const crypto = require('node:crypto')

const { FLOW_PATHS, IDEAS_SCHEMA, CATEGORIES, SOURCES, buildIdeasPrompt, clip } = require('./ux-ideas-prompt')
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
const IDEA_TYPES = new Set(['fix', 'experiment'])
const SOURCE_KEYS = new Set(SOURCES)
const PROBLEM_REQUIRED = IDEAS_SCHEMA.properties.problems.items.required
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
 * Is the presented secret the configured one. Compared as SHA-256 digests with timingSafeEqual,
 * so neither the length nor the first differing byte leaks through timing. A secret that is unset
 * or empty never matches anything: an unconfigured helper refuses everyone rather than admitting
 * every caller.
 */
function secretMatches(presented, expected) {
  if (typeof expected !== 'string' || expected === '') return false
  if (typeof presented !== 'string' || presented === '') return false

  const digest = (v) => crypto.createHash('sha256').update(v).digest()

  return crypto.timingSafeEqual(digest(presented), digest(expected))
}

// Headers a reverse proxy or load balancer adds. This route is only for the batch, calling
// straight across the docker network. A request that carries one has been forwarded by something,
// so its peer address is the proxy's and says nothing about who really sent it.
const FORWARDING_HEADERS = ['x-forwarded-for', 'x-forwarded-host', 'x-forwarded-proto', 'x-real-ip', 'forwarded']

/**
 * The guard for both /internal routes, kept here so it can be tested without importing the heavy
 * server.js. All three must hold:
 *
 *  - the peer is on a private address;
 *  - the request was not forwarded (no X-Forwarded-For, Forwarded or the like), because a proxy
 *    that forwards to this container has a private source address itself, so the peer check alone
 *    passes whatever the real client was;
 *  - the X-Freegle-Internal-Secret header equals the UX_INTERNAL_SECRET environment variable. An
 *    unset variable refuses everyone. Each accepted call spends the subscription on a long Opus
 *    run, so network position is not enough.
 *
 * @param {object} req - an Express request (only socket.remoteAddress and headers are read).
 * @returns {{ok:boolean, error?:string}}
 */
function internalGuard(req) {
  const peer = req && req.socket && req.socket.remoteAddress

  if (!isPrivateAddress(peer)) {
    return { ok: false, error: 'FORBIDDEN' }
  }

  const headers = (req && req.headers) || {}
  if (FORWARDING_HEADERS.some((h) => headers[h] !== undefined)) {
    return { ok: false, error: 'FORBIDDEN' }
  }

  if (!secretMatches(headers['x-freegle-internal-secret'], process.env.UX_INTERNAL_SECRET)) {
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

  return {
    flow: b.flow,
    figures: text(b.figures),
    surveys: text(b.surveys),
    problems: text(b.problems),
    values: text(b.values),
    reports: text(b.reports),
    tried,
    count,
  }
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

  const problemId = num(raw.problem_id)
  const type = str(raw.type)
  if (problemId === null || !IDEA_TYPES.has(type)) return null

  idea.problem_id = Math.round(problemId)
  idea.type = type
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
 * One problem the model found, or null when it does not pass. A problem is something a member
 * would feel, not a solution, so only the fields the schema asks for are kept. The source must be
 * one of the known sources, so a made-up source cannot reach the table.
 */
function validateProblem(raw) {
  if (!raw || typeof raw !== 'object') return null

  for (const key of PROBLEM_REQUIRED) {
    if (raw[key] === undefined || raw[key] === null) return null
  }

  const id = num(raw.id)
  const title = str(raw.title).replace(/\s+/g, ' ').slice(0, 100)
  const source = str(raw.source)
  if (id === null || !title || !SOURCE_KEYS.has(source)) return null

  return {
    id: Math.round(id),
    title,
    where: str(raw.where).slice(0, 300),
    source,
    evidence: str(raw.evidence).slice(0, 1000),
  }
}

/**
 * Validate a parsed answer: keeps the well-formed problems, ideas and journey entries, drops the
 * rest, and never throws on one bad entry. Throws only when no idea is left, because an answer
 * with no ideas is a failed run and the caller should see a failure, not an empty success.
 *
 * An idea whose problem_id names no kept problem keeps its place but loses the link (problem_id
 * null): the idea may still be good, and a link to nothing would only fail later in the batch.
 * Problem ids are unique; the first one with an id wins.
 */
function normaliseAnswer(parsed, count = 12) {
  if (!parsed || typeof parsed !== 'object') {
    throw new Error('the model did not return a JSON object')
  }

  const problems = []
  const problemIds = new Set()
  for (const p of (Array.isArray(parsed.problems) ? parsed.problems : []).map(validateProblem)) {
    if (!p || problemIds.has(p.id)) continue
    problemIds.add(p.id)
    problems.push(p)
  }

  const ideas = (Array.isArray(parsed.ideas) ? parsed.ideas : [])
    .map(validateIdea)
    .filter(Boolean)
    .slice(0, count)
    .map((idea) => (problemIds.has(idea.problem_id) ? idea : { ...idea, problem_id: null }))
  if (!ideas.length) {
    throw new Error('the model returned no valid ideas')
  }

  return {
    journey: (Array.isArray(parsed.journey) ? parsed.journey : []).map(validateJourneyEntry).filter(Boolean),
    problems,
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
  'glob it to check how a screen behaves, but only the front-end source under iznik-nuxt3/ (components,',
  'composables, stores, pages, layouts, plugins, utils, mixins, api); always give Grep and Glob a path',
  'inside those folders. You cannot change anything and must not try to.',
  'Your final answer is a single JSON object matching the schema in the request, and nothing else.',
  'If you cannot return it as structured output, put it in one ```json fenced block.',
].join(' ')

// ---------------------------------------------------------------------------
// What the model may read
// ---------------------------------------------------------------------------

// The prompt carries text anyone can write (issue titles, forum posts, members' themes), so the
// run's tools are held to the front-end source it is meant to read: not .env files, server
// configuration, other repositories' files, or anything a planted instruction might ask it to
// quote back into an idea. The hook runs even in bypassPermissions mode.
const READABLE_DIRS = ['components', 'composables', 'stores', 'pages', 'layouts', 'plugins', 'utils', 'mixins', 'api']
const READABLE_EXTENSIONS = new Set(['.vue', '.js', '.mjs', '.ts', '.css', '.scss'])

function realOrResolved(p) {
  try {
    return fs.realpathSync(p)
  } catch (e) {
    return path.resolve(p)
  }
}

/**
 * Is this path (absolute, or relative to the checkout) inside the source directories? A file must
 * also have a source extension; a directory is fine, because Grep and Glob search under it.
 */
function readablePath(target, codebaseDir = CODEBASE) {
  if (typeof target !== 'string' || !target || target.includes('\0')) return false

  const root = realOrResolved(codebaseDir)
  const abs = realOrResolved(path.resolve(root, target))
  const under = READABLE_DIRS.map((d) => path.join(root, 'iznik-nuxt3', d))
  const inside = under.some((dir) => abs === dir || abs.startsWith(dir + path.sep))
  if (!inside) return false

  const ext = path.extname(abs)
  return ext === '' || READABLE_EXTENSIONS.has(ext)
}

/**
 * The reason a Read, Grep or Glob call is refused, or '' when it may go ahead. Grep and Glob must
 * name a directory to search, so a search of the whole checkout is not possible by leaving it out.
 */
function toolRefusal(toolName, input, codebaseDir = CODEBASE) {
  const i = input && typeof input === 'object' ? input : {}
  const hint = ' Only front-end source under iznik-nuxt3/' + READABLE_DIRS.join(', iznik-nuxt3/') + ' may be read.'

  if (toolName === 'Read') {
    return readablePath(i.file_path, codebaseDir) ? '' : 'That file is not readable.' + hint
  }
  if (toolName === 'Grep' || toolName === 'Glob') {
    if (!readablePath(i.path, codebaseDir)) return 'Give a path inside the source directories.' + hint
    if (toolName === 'Glob' && (typeof i.pattern !== 'string' || i.pattern.includes('..') || i.pattern.startsWith('/'))) {
      return 'That pattern is not allowed.' + hint
    }
    return ''
  }
  return 'That tool is not available.'
}

/** The PreToolUse hook that applies toolRefusal. */
function guardToolUse(codebaseDir = CODEBASE) {
  return async (input) => {
    const reason = toolRefusal(input && input.tool_name, input && input.tool_input, codebaseDir)
    if (!reason) return {}

    return { hookSpecificOutput: { hookEventName: 'PreToolUse', permissionDecision: 'deny', permissionDecisionReason: reason } }
  }
}

/**
 * One model call through the Claude Agent SDK, with Read/Grep/Glob confined to the checkout as the
 * support agent does and no other tools. Authentication is whichever credential auth.js finds, in
 * practice the geeks subscription token the helper already holds.
 *
 * @returns {Promise<{answer:object|null, text:string, costUsd:number, model:string}>}
 */
async function runIdeasQuery({ prompt, codebaseDir = CODEBASE, queryImpl, timeoutMs, signal }) {
  // Required here, not at the top, so this module loads (and its tests run) where the SDK is not
  // installed, as in the bare `node --test` CI step.
  const query = queryImpl || require('@anthropic-ai/claude-agent-sdk').query

  const abort = new AbortController()
  const limit = timeoutMs || Number(process.env.UX_IDEAS_TIMEOUT_MS || 15 * 60 * 1000)
  const timer = setTimeout(() => abort.abort(), limit)
  // Stop when the caller goes away. Without this a batch that gave up after its own timeout would
  // leave the run going, spending the subscription on an answer nobody will read while the next
  // request queues behind it.
  const onCallerGone = () => abort.abort()
  if (signal) {
    if (signal.aborted) abort.abort()
    else signal.addEventListener('abort', onCallerGone, { once: true })
  }

  const options = {
    model: MODEL,
    systemPrompt: SYSTEM_PROMPT,
    // The built-in set is cut to three read-only tools, and the write tools are refused as well in
    // case a future SDK default widens it. No MCP servers: this run needs no member data at all.
    tools: ['Read', 'Grep', 'Glob'],
    allowedTools: ['Read', 'Grep', 'Glob'],
    disallowedTools: ['Write', 'Edit', 'Bash', 'NotebookEdit', 'WebFetch', 'WebSearch', 'Task'],
    permissionMode: 'bypassPermissions',
    hooks: { PreToolUse: [{ matcher: 'Read|Grep|Glob', hooks: [guardToolUse(codebaseDir)] }] },
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
    if (signal && signal.aborted) throw new Error('the caller went away, so the model run was stopped')
    throw new Error(abort.signal.aborted ? `the model run timed out after ${limit} ms` : e.message || String(e))
  } finally {
    clearTimeout(timer)
    if (signal) signal.removeEventListener('abort', onCallerGone)
  }

  if (failure) throw new Error('the model run failed: ' + failure)
  if (signal && signal.aborted) throw new Error('the caller went away, so the model run was stopped')

  return { answer: structured || parseIdeasJson(text), text, costUsd, model }
}

/**
 * Generate ideas for one funnel.
 *
 * @param {object} o
 * @param {string} o.flow - a key of FLOW_PATHS.
 * @param {string} [o.figures] - plain-text rollup lines.
 * @param {string} [o.surveys] - plain-text survey and awareness lines.
 * @param {string} [o.problems] - plain-text lines: problems members raised, with mentions and rank.
 * @param {string} [o.values] - plain-text lines: what members value.
 * @param {string} [o.reports] - plain-text lines: reports and open issues.
 * @param {AbortSignal} [o.signal] - aborts the run (the caller disconnected).
 * @param {Array} [o.tried] - [{title, status, decision}] already run or rejected.
 * @param {number} [o.count] - ideas wanted (default 12).
 * @param {string} [o.codebaseDir]
 * @param {function} [o.queryImpl] - the SDK's query(), injectable for tests.
 * @param {function} [o.now] - () => Date, injectable for tests.
 * @returns {Promise<{journey:Array, problems:Array, ideas:Array, costUsd:number, model:string}>}
 */
async function generateIdeas({ flow, figures, surveys, problems, values, reports, tried, count, codebaseDir, queryImpl, now, signal }) {
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
    problems: clip(problems, 40000),
    values: clip(values, 40000),
    reports: clip(reports, 40000),
    tried: tried || [],
    recentCommits: await areaHistory(flow, { codebaseDir: dir }),
    code,
  })

  const run = await runIdeasQuery({ prompt, codebaseDir: dir, queryImpl, signal })
  const result = normaliseAnswer(run.answer, wanted)

  return { ...result, costUsd: run.costUsd, model: run.model }
}

// One generation at a time. Each is a long Opus run on the shared subscription token, which the
// support helper also uses, and the batch asks for the seven flows one after another; running
// them side by side would only make each slower and trip the rate limit sooner.
//
// maxPending bounds the queue: with a job already running or waiting, a new one is refused with
// an error whose code is BUSY instead of joining the line. The routes use 1, so a caller can never
// stack up work behind a long run, and the batch's next request is told to come back rather than
// spend its own timeout waiting.
let chain = Promise.resolve()
let pending = 0
function serialise(fn, opts = {}) {
  const max = opts.maxPending === undefined ? Infinity : opts.maxPending
  if (pending >= max) {
    const busy = new Error('another run is in progress')
    busy.code = 'BUSY'

    return Promise.reject(busy)
  }

  pending++
  const next = chain.then(() => fn())
  const done = () => {
    pending--
  }
  next.then(done, done)
  chain = next.catch(() => {})

  return next
}

/**
 * An Express handler for one of the /internal routes: guard, clean the body, run one job at a
 * time, and stop the job if the caller disconnects. server.js builds both routes from this, and it
 * is separate from server.js so a test can drive it with plain objects.
 *
 * @param {object} o
 * @param {string} o.tag - the log prefix, e.g. 'UxIdeas'.
 * @param {function} o.clean - body => {error} | the arguments for run.
 * @param {function} o.run - (cleaned, {signal}) => Promise<result>.
 * @param {function} [o.summary] - result => extra text for the log line.
 */
function internalHandler({ tag, clean, run, summary }) {
  return async (req, res) => {
    const guard = internalGuard(req)
    if (!guard.ok) {
      return res.status(403).json({ error: guard.error })
    }

    const request = clean(req.body)
    if (request.error) {
      return res.status(400).json({ error: request.error })
    }

    // Aborted if the connection closes before the answer is sent, so a caller that timed out does
    // not leave a run going.
    const controller = new AbortController()
    const onClose = () => {
      if (!res.writableFinished) controller.abort()
    }
    res.on('close', onClose)

    try {
      const result = await serialise(() => run(request, { signal: controller.signal }), { maxPending: 1 })
      console.log(`[${tag}] ok ${summary ? summary(result, request) : ''} cost=$${(result.costUsd || 0).toFixed(4)} model=${result.model}`)
      res.json(result)
    } catch (error) {
      if (error && error.code === 'BUSY') {
        return res.status(429).json({ error: 'BUSY', message: error.message })
      }
      console.error(`[${tag}] FAILED:`, error.message)
      res.status(502).json({ error: error.message })
    }
  }
}

module.exports = {
  isPrivateAddress,
  internalGuard,
  cleanRequest,
  areaHistory,
  areaCode,
  parseIdeasJson,
  validateIdea,
  validateProblem,
  normaliseAnswer,
  runIdeasQuery,
  readablePath,
  toolRefusal,
  guardToolUse,
  generateIdeas,
  serialise,
  internalHandler,
  secretMatches,
}
