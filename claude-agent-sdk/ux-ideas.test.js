'use strict'

const test = require('node:test')
const assert = require('node:assert/strict')
const fs = require('node:fs')
const os = require('node:os')
const path = require('node:path')
const { execFileSync } = require('node:child_process')

const { FLOW_PATHS, IDEAS_SCHEMA } = require('./ux-ideas-prompt')
const {
  isPrivateAddress,
  internalGuard,
  cleanRequest,
  areaHistory,
  areaCode,
  parseIdeasJson,
  validateIdea,
  normaliseAnswer,
  generateIdeas,
  serialise,
} = require('./ux-ideas')
const { deepenHistory } = require('./codebase')

// ---------------------------------------------------------------------------
// A throwaway checkout: two commits touching a Give file, one touching nothing in any flow.
// Dates are relative to now so the six-month window always holds them.
// ---------------------------------------------------------------------------

function git(dir, args, date) {
  const env = { ...process.env, GIT_AUTHOR_NAME: 't', GIT_AUTHOR_EMAIL: 't@t', GIT_COMMITTER_NAME: 't', GIT_COMMITTER_EMAIL: 't@t' }
  if (date) {
    env.GIT_AUTHOR_DATE = date
    env.GIT_COMMITTER_DATE = date
  }
  execFileSync('git', args, { cwd: dir, env, stdio: 'pipe' })
}

function daysAgo(n) {
  return new Date(Date.now() - n * 86400000).toISOString()
}

function write(dir, rel, content) {
  const abs = path.join(dir, rel)
  fs.mkdirSync(path.dirname(abs), { recursive: true })
  fs.writeFileSync(abs, content)
}

function makeRepo() {
  const dir = fs.mkdtempSync(path.join(os.tmpdir(), 'ux-ideas-test-'))
  git(dir, ['init', '-q'])

  write(dir, 'iznik-nuxt3/pages/give/index.vue', '<template>give one</template>')
  git(dir, ['add', '-A'])
  git(dir, ['commit', '-q', '-m', 'feat(give): first version of the give page'], daysAgo(60))

  write(dir, 'iznik-nuxt3/pages/give/index.vue', '<template>give two</template>')
  write(dir, 'iznik-nuxt3/components/PostPhoto.vue', '<template>photo</template>')
  git(dir, ['add', '-A'])
  git(dir, ['commit', '-q', '-m', 'fix(give): keep the photo when the upload stalls'], daysAgo(10))

  write(dir, 'iznik-nuxt3/pages/settings/index.vue', '<template>settings</template>')
  git(dir, ['add', '-A'])
  git(dir, ['commit', '-q', '-m', 'chore(settings): unrelated to give'], daysAgo(5))

  return dir
}

const repo = makeRepo()
test.after(() => fs.rmSync(repo, { recursive: true, force: true }))

// ---------------------------------------------------------------------------

test('isPrivateAddress accepts the private ranges and their IPv4-mapped forms', () => {
  for (const ip of [
    '10.0.0.1', '10.255.255.255',
    '172.16.0.1', '172.31.255.255',
    '192.168.1.1', '192.168.0.0',
    '127.0.0.1', '127.1.2.3',
    '::1',
    '::ffff:10.1.2.3', '::ffff:172.18.0.5', '::ffff:192.168.0.9', '::ffff:127.0.0.1',
    'fd12:3456::1',
  ]) {
    assert.equal(isPrivateAddress(ip), true, ip)
  }
})

test('isPrivateAddress refuses public, near-miss and unparseable addresses', () => {
  for (const ip of [
    '8.8.8.8', '1.1.1.1',
    '172.15.0.1', '172.32.0.1', // just outside 172.16/12
    '192.169.0.1', '11.0.0.1',
    '10.0.0.256', '300.1.1.1', '10.0.0',
    '::ffff:8.8.8.8',
    '2001:db8::1',
    '', undefined, null, 42, {},
  ]) {
    assert.equal(isPrivateAddress(ip), false, String(ip))
  }
})

test('the route guard needs a private peer and the header, both', () => {
  const req = (ip, header) => ({ socket: { remoteAddress: ip }, headers: header === undefined ? {} : { 'x-freegle-internal': header } })

  assert.deepEqual(internalGuard(req('172.18.0.4', '1')), { ok: true })
  assert.deepEqual(internalGuard(req('::ffff:172.18.0.4', '1')), { ok: true })
  assert.equal(internalGuard(req('172.18.0.4')).ok, false, 'no header')
  assert.equal(internalGuard(req('172.18.0.4', '0')).ok, false, 'wrong header value')
  assert.equal(internalGuard(req('172.18.0.4', 'true')).ok, false, 'header must be exactly 1')
  assert.equal(internalGuard(req('203.0.113.9', '1')).ok, false, 'public peer')
  assert.equal(internalGuard({}).ok, false, 'no socket')
  assert.equal(internalGuard({ socket: {} , headers: { 'x-freegle-internal': '1' } }).ok, false, 'no address')
})

test('cleanRequest validates the flow and bounds the rest', () => {
  assert.match(cleanRequest({ flow: 'mystery' }).error, /flow must be one of/)
  assert.match(cleanRequest(null).error, /flow must be one of/)
  assert.match(cleanRequest({ flow: '__proto__' }).error, /flow must be one of/)
  assert.match(cleanRequest({ flow: 'toString' }).error, /flow must be one of/)

  const ok = cleanRequest({
    flow: 'give',
    figures: 'a',
    surveys: 7,
    tried: [{ title: 'T', status: 'open', decision: 'd' }, { nope: 1 }, null, { title: '  ' }],
    count: 500,
  })
  assert.equal(ok.flow, 'give')
  assert.equal(ok.surveys, '')
  assert.deepEqual(ok.tried, [{ title: 'T', status: 'open', decision: 'd' }])
  assert.equal(ok.count, 30)
  assert.equal(cleanRequest({ flow: 'ask' }).count, 12)
})

test('areaHistory returns the flow\'s commits, newest first, and nothing from other areas', async () => {
  const history = await areaHistory('give', { codebaseDir: repo })

  assert.equal(history.length, 2)
  assert.match(history[0], /^\d{4}-\d{2}-\d{2} fix\(give\): keep the photo when the upload stalls$/)
  assert.match(history[1], /first version of the give page$/)
  assert.ok(!history.some((h) => h.includes('unrelated')))
})

test('areaHistory honours since, and is empty for an unknown flow or a missing checkout', async () => {
  const recent = await areaHistory('give', { codebaseDir: repo, since: daysAgo(30).slice(0, 10) })
  assert.equal(recent.length, 1)
  assert.match(recent[0], /upload stalls/)

  assert.deepEqual(await areaHistory('mystery', { codebaseDir: repo }), [])
  assert.deepEqual(await areaHistory('give', { codebaseDir: path.join(repo, 'nope') }), [])
})

test('areaHistory passes git an argument list, never a shell string, and a bad since cannot become an option', async () => {
  const calls = []
  const fake = async (file, args, opts) => {
    calls.push({ file, args, opts })
    return { stdout: '2026-01-02 something\n\n' }
  }

  const out = await areaHistory('give', { codebaseDir: '/x', since: '--output=/tmp/evil', execFile: fake, deepen: false })
  assert.deepEqual(out, ['2026-01-02 something'])
  assert.equal(calls.length, 1)
  assert.equal(calls[0].file, 'git')
  assert.ok(Array.isArray(calls[0].args))
  assert.match(calls[0].args.find((a) => a.startsWith('--since=')), /^--since=\d{4}-\d{2}-\d{2}$/)
  const dashdash = calls[0].args.indexOf('--')
  assert.deepEqual(calls[0].args.slice(dashdash + 1), FLOW_PATHS.give)
})

test('deepenHistory fetches only into a shallow checkout, and never throws', async () => {
  const seen = []
  const shallow = async (file, args) => {
    seen.push(args.join(' '))
    return { stdout: args[0] === 'rev-parse' ? 'true\n' : '' }
  }
  assert.equal(await deepenHistory({ dir: '/x', since: '2026-04-01', execFile: shallow }), true)
  assert.match(seen[1], /^fetch --quiet --filter=blob:none --shallow-since=2026-04-01 origin$/)

  seen.length = 0
  const full = async (file, args) => {
    seen.push(args[0])
    return { stdout: 'false\n' }
  }
  assert.equal(await deepenHistory({ dir: '/x', since: '2026-04-01', execFile: full }), false)
  assert.deepEqual(seen, ['rev-parse'])

  const broken = async () => {
    throw new Error('no network')
  }
  assert.equal(await deepenHistory({ dir: '/x', since: '2026-04-01', execFile: broken }), false)
})

test('areaCode returns the flow\'s existing files, pages first, as checkout-relative paths', () => {
  const code = areaCode('give', { codebaseDir: repo })
  const paths = code.map((f) => f.path)

  // PostItem.vue and the rest of the list do not exist in the throwaway repo and are skipped.
  assert.deepEqual(paths, ['iznik-nuxt3/pages/give/index.vue', 'iznik-nuxt3/components/PostPhoto.vue'])
  assert.equal(code[0].content, '<template>give two</template>')
})

test('areaCode is empty for an unknown flow or missing checkout, and capped at 16 files', () => {
  assert.deepEqual(areaCode('mystery', { codebaseDir: repo }), [])
  assert.deepEqual(areaCode('give', { codebaseDir: path.join(repo, 'nope') }), [])

  const big = fs.mkdtempSync(path.join(os.tmpdir(), 'ux-ideas-big-'))
  try {
    for (let i = 0; i < 30; i++) write(big, `iznik-nuxt3/pages/give/p${String(i).padStart(2, '0')}.vue`, 'x')
    write(big, 'iznik-nuxt3/pages/give/node_modules/skip.js', 'x')
    write(big, 'iznik-nuxt3/pages/give/notes.md', 'not code')
    const code = areaCode('give', { codebaseDir: big })
    assert.equal(code.length, 16)
    assert.equal(code[0].path, 'iznik-nuxt3/pages/give/p00.vue')
    assert.ok(code.every((f) => f.path.endsWith('.vue')))
  } finally {
    fs.rmSync(big, { recursive: true, force: true })
  }
})

// ---------------------------------------------------------------------------

const goodIdea = {
  title: 'Show Add photos later as a link on the mobile photos step',
  category: 'disclosure',
  change: 'The photos step leads with the item name.',
  rationale: 'The photos step loses 31% of visitors.',
  expected: 'Give completion up about 2 points.',
  expected_points: 2,
  confidence: 0.4,
  locality: 4,
  risk: 'Fewer posts have photos.',
  files: ['iznik-nuxt3/components/PostPhoto.vue'],
}

test('parseIdeasJson reads plain JSON, fenced JSON, prose around JSON, and gives up cleanly', () => {
  const obj = { problem: 'p', journey: [], ideas: [goodIdea] }
  const json = JSON.stringify(obj)

  assert.deepEqual(parseIdeasJson(json), obj)
  assert.deepEqual(parseIdeasJson('﻿  ' + json + '\n'), obj)
  assert.deepEqual(parseIdeasJson('Here you go:\n```json\n' + json + '\n```\nHope that helps.'), obj)
  assert.deepEqual(parseIdeasJson('```\n' + json + '\n```'), obj)
  assert.deepEqual(parseIdeasJson('Sure. ' + json + ' Done.'), obj)

  // The answer is the last fence; an earlier one may be a quoted snippet.
  assert.deepEqual(parseIdeasJson('```json\n{"a": 1}\n```\nthinking...\n```json\n' + json + '\n```'), obj)

  assert.equal(parseIdeasJson(''), null)
  assert.equal(parseIdeasJson('no json here'), null)
  assert.equal(parseIdeasJson('{ not: valid'), null)
  assert.equal(parseIdeasJson('[1,2,3]'), null, 'an array is not an answer')
  assert.equal(parseIdeasJson(undefined), null)
})

test('validateIdea keeps good ideas, clamps scores and rejects malformed ones', () => {
  assert.deepEqual(validateIdea(goodIdea), { ...goodIdea, like_tried: '' })

  const clamped = validateIdea({ ...goodIdea, confidence: 1.4, locality: 9, expected_points: '3.5', title: 'x'.repeat(200) })
  assert.equal(clamped.confidence, 1)
  assert.equal(clamped.locality, 5)
  assert.equal(clamped.expected_points, 3.5)
  assert.equal(clamped.title.length, 80)

  for (const key of IDEAS_SCHEMA.properties.ideas.items.required) {
    const missing = { ...goodIdea }
    delete missing[key]
    assert.equal(validateIdea(missing), null, 'missing ' + key)
  }
  assert.equal(validateIdea({ ...goodIdea, category: 'sparkle' }), null, 'category outside the enum')
  assert.equal(validateIdea({ ...goodIdea, confidence: 'high' }), null)
  assert.equal(validateIdea({ ...goodIdea, files: 'one.vue' }), null)
  assert.equal(validateIdea({ ...goodIdea, title: '   ' }), null)
  assert.equal(validateIdea(null), null)
  assert.equal(validateIdea('idea'), null)
  assert.deepEqual(validateIdea({ ...goodIdea, files: ['a.vue', 3, ' '] }).files, ['a.vue'])
})

test('normaliseAnswer drops bad entries without throwing, caps at count, and fails only when nothing is left', () => {
  const answer = normaliseAnswer(
    {
      problem: ' Photos lose people. ',
      journey: [{ screen: 'Photos', asks: 'Add a photo', friction: 'Upload stalls' }, { screen: 'No asks' }, 7],
      ideas: [goodIdea, { title: 'broken' }, null, { ...goodIdea, title: 'Second' }, { ...goodIdea, title: 'Third' }],
    },
    2
  )

  assert.equal(answer.problem, 'Photos lose people.')
  assert.equal(answer.journey.length, 1)
  assert.deepEqual(answer.ideas.map((i) => i.title), [goodIdea.title, 'Second'])

  assert.throws(() => normaliseAnswer(null), /JSON object/)
  assert.throws(() => normaliseAnswer({ problem: 'p', journey: [], ideas: [{ title: 'x' }] }), /no valid ideas/)
  assert.throws(() => normaliseAnswer({ problem: 'p' }), /no valid ideas/)
})

test('generateIdeas assembles the brief and returns validated ideas from structured output', async () => {
  let seen
  const queryImpl = async function* (arg) {
    seen = arg
    yield { type: 'system', subtype: 'init', model: 'claude-test-1' }
    yield {
      type: 'result',
      subtype: 'success',
      result: '',
      total_cost_usd: 1.23,
      structured_output: { problem: 'P', journey: [], ideas: [goodIdea, { broken: true }] },
    }
  }

  const out = await generateIdeas({
    flow: 'give',
    figures: 'give: 822 started',
    surveys: 'login worst',
    tried: [{ title: 'Shorter first screen', status: 'open' }],
    count: 5,
    codebaseDir: repo,
    queryImpl,
    now: () => new Date('2026-10-14T12:00:00Z'),
  })

  assert.equal(out.problem, 'P')
  assert.equal(out.ideas.length, 1)
  assert.equal(out.model, 'claude-test-1')
  assert.ok(Object.keys(out).includes('costUsd'))

  assert.match(seen.prompt, /Today is 2026-10-14/)
  assert.match(seen.prompt, /give: 822 started/)
  assert.match(seen.prompt, /Shorter first screen \[open\]/)
  assert.match(seen.prompt, /keep the photo when the upload stalls/)
  assert.match(seen.prompt, /### iznik-nuxt3\/pages\/give\/index.vue/)
  assert.match(seen.prompt, /return the best 5/)

  // Read-only tools only, confined to the checkout, with the schema as the output format.
  assert.deepEqual(seen.options.tools, ['Read', 'Grep', 'Glob'])
  assert.deepEqual(seen.options.allowedTools, ['Read', 'Grep', 'Glob'])
  for (const t of ['Write', 'Edit', 'Bash']) assert.ok(seen.options.disallowedTools.includes(t), t)
  assert.equal(seen.options.cwd, repo)
  assert.deepEqual(seen.options.additionalDirectories, [repo])
  assert.equal(seen.options.mcpServers, undefined)
  assert.equal(seen.options.outputFormat.type, 'json_schema')
  assert.equal(seen.options.outputFormat.schema, IDEAS_SCHEMA)
})

test('generateIdeas falls back to parsing a fenced block when there is no structured output', async () => {
  const text = 'Thinking aloud.\n```json\n' + JSON.stringify({ problem: 'P', journey: [], ideas: [goodIdea] }) + '\n```'
  const queryImpl = async function* () {
    yield { type: 'result', subtype: 'success', result: text, total_cost_usd: 0 }
  }

  const out = await generateIdeas({ flow: 'give', codebaseDir: repo, queryImpl })
  assert.equal(out.ideas.length, 1)
})

test('generateIdeas fails loudly on an unknown flow, no code, an unparseable answer or a failed run', async () => {
  const ok = async function* () {
    yield { type: 'result', subtype: 'success', result: 'I could not do it.' }
  }
  await assert.rejects(generateIdeas({ flow: 'mystery', codebaseDir: repo, queryImpl: ok }), /unknown flow/)
  await assert.rejects(generateIdeas({ flow: 'give', codebaseDir: path.join(repo, 'nope'), queryImpl: ok }), /none of this flow/)
  await assert.rejects(generateIdeas({ flow: 'give', codebaseDir: repo, queryImpl: ok }), /JSON object/)

  const failed = async function* () {
    yield { type: 'result', subtype: 'error_max_turns', errors: [] }
  }
  await assert.rejects(generateIdeas({ flow: 'give', codebaseDir: repo, queryImpl: failed }), /error_max_turns/)

  const thrown = async function* () {
    throw new Error('rate limited')
    yield null
  }
  await assert.rejects(generateIdeas({ flow: 'give', codebaseDir: repo, queryImpl: thrown }), /rate limited/)
})

test('serialise runs one job at a time and survives a failing job', async () => {
  const order = []
  const slow = () => new Promise((r) => setTimeout(() => { order.push('slow'); r('a') }, 30))
  const fast = async () => { order.push('fast'); return 'b' }
  const bad = async () => { throw new Error('x') }

  const results = await Promise.allSettled([serialise(slow), serialise(bad), serialise(fast)])
  assert.deepEqual(order, ['slow', 'fast'])
  assert.equal(results[0].value, 'a')
  assert.equal(results[1].status, 'rejected')
  assert.equal(results[2].value, 'b')
})
