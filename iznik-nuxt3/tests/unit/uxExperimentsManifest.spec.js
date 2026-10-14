/**
 * Corpse control for UX experiments (docs/developers/reference/ux-telemetry.md section 8).
 *
 * Every `useExperiment('<name>')` switch in the source must be listed in
 * ux-experiments.manifest.json with the day it was opened, every listed switch must still exist
 * in the source, and none may be older than max_age_days unless its keep_until is still ahead.
 * An experiment decided and never cleaned up therefore turns the build red rather than rotting.
 */
import { describe, it, expect } from 'vitest'
import fs from 'node:fs'
import path from 'node:path'
import { fileURLToPath } from 'node:url'

const root = path.resolve(
  path.dirname(fileURLToPath(import.meta.url)),
  '..',
  '..'
)
const MANIFEST = path.join(root, 'ux-experiments.manifest.json')
const OWNER = path.join('composables', 'useExperiment.js')
const SKIP_DIRS = new Set([
  'node_modules',
  '.nuxt',
  '.output',
  '.git',
  'dist',
  'coverage',
  'tests',
  'ios',
  'android',
  'public',
  'docs',
])
const EXTENSIONS = new Set(['.js', '.mjs', '.ts', '.vue'])
const DAY_MS = 24 * 60 * 60 * 1000

// useExperiment('name') / useExperiment("name") / useExperiment(`name`)
const CALL = /\buseExperiment\(\s*['"`]([A-Za-z0-9_-]{1,64})['"`]/g

export function switchesIn(source) {
  const names = new Set()
  for (const m of source.matchAll(CALL)) names.add(m[1])
  return [...names]
}

export function manifestProblems(manifest, switches, today = new Date()) {
  const problems = []
  const maxAge = Number(manifest.max_age_days) || 14
  const listed = new Map()
  for (const e of manifest.experiments || []) {
    if (!e || typeof e.name !== 'string') {
      problems.push('a manifest entry has no name')
      continue
    }
    listed.set(e.name, e)
    if (!/^\d{4}-\d{2}-\d{2}$/.test(e.opened || '')) {
      problems.push(`${e.name}: opened must be YYYY-MM-DD`)
      continue
    }
    const ageDays = Math.floor(
      (today - new Date(e.opened + 'T00:00:00Z')) / DAY_MS
    )
    const kept =
      typeof e.keep_until === 'string' &&
      new Date(e.keep_until + 'T00:00:00Z') >= today
    if (ageDays > maxAge && !kept) {
      problems.push(
        `${e.name}: opened ${e.opened}, ${ageDays} days ago, past ${maxAge}; clean it up or set keep_until with a reason`
      )
    }
  }
  const inCode = new Set(switches.map((s) => s.name))
  for (const s of switches) {
    if (!listed.has(s.name)) {
      problems.push(
        `${s.name}: used in ${s.file} but not in ux-experiments.manifest.json`
      )
    }
  }
  for (const name of listed.keys()) {
    if (!inCode.has(name)) {
      problems.push(
        `${name}: in the manifest but no useExperiment('${name}') left in the code; remove the entry`
      )
    }
  }
  return problems
}

function* walk(dir) {
  for (const entry of fs.readdirSync(dir, { withFileTypes: true })) {
    if (entry.isDirectory()) {
      if (!SKIP_DIRS.has(entry.name)) yield* walk(path.join(dir, entry.name))
    } else if (EXTENSIONS.has(path.extname(entry.name))) {
      yield path.join(dir, entry.name)
    }
  }
}

function switchesInTree() {
  const out = []
  for (const file of walk(root)) {
    const rel = path.relative(root, file)
    if (rel === OWNER) continue
    const source = fs.readFileSync(file, 'utf8')
    if (!source.includes('useExperiment(')) continue
    for (const name of switchesIn(source)) out.push({ name, file: rel })
  }
  return out
}

describe('UX experiment switch manifest', () => {
  describe('the checker', () => {
    const today = new Date('2026-10-14T12:00:00Z')

    it('finds switches in their spellings', () => {
      expect(
        switchesIn("const arm = useExperiment('give-photos-skip')")
      ).toEqual(['give-photos-skip'])
      expect(switchesIn('useExperiment("a_b") useExperiment(`c`)')).toEqual([
        'a_b',
        'c',
      ])
      expect(switchesIn('useExperiment(name)')).toEqual([])
    })

    it('passes a fresh, listed switch', () => {
      const m = {
        max_age_days: 14,
        experiments: [
          {
            name: 'x',
            opened: '2026-10-10',
            pr: 'https://github.com/Freegle/Iznik/pull/1',
          },
        ],
      }
      expect(
        manifestProblems(m, [{ name: 'x', file: 'a.vue' }], today)
      ).toEqual([])
    })

    it('fails a switch missing from the manifest', () => {
      expect(
        manifestProblems(
          { experiments: [] },
          [{ name: 'x', file: 'a.vue' }],
          today
        )
      ).toEqual(['x: used in a.vue but not in ux-experiments.manifest.json'])
    })

    it('fails a manifest entry with no switch left', () => {
      const m = { experiments: [{ name: 'gone', opened: '2026-10-10' }] }
      expect(manifestProblems(m, [], today)[0]).toContain('no useExperiment')
    })

    it('fails a switch older than the limit', () => {
      const m = {
        max_age_days: 14,
        experiments: [{ name: 'old', opened: '2026-09-01' }],
      }
      const p = manifestProblems(m, [{ name: 'old', file: 'a.vue' }], today)
      expect(p).toHaveLength(1)
      expect(p[0]).toContain('past 14')
    })

    it('allows an old switch kept deliberately until a future date', () => {
      const m = {
        max_age_days: 14,
        experiments: [
          {
            name: 'old',
            opened: '2026-09-01',
            keep_until: '2026-11-01',
            reason: 'holdout',
          },
        ],
      }
      expect(
        manifestProblems(m, [{ name: 'old', file: 'a.vue' }], today)
      ).toEqual([])
    })

    it('does not allow keep_until in the past', () => {
      const m = {
        max_age_days: 14,
        experiments: [
          { name: 'old', opened: '2026-09-01', keep_until: '2026-10-01' },
        ],
      }
      expect(
        manifestProblems(m, [{ name: 'old', file: 'a.vue' }], today)
      ).toHaveLength(1)
    })

    it('fails a bad opened date', () => {
      const m = { experiments: [{ name: 'x', opened: 'last week' }] }
      expect(
        manifestProblems(m, [{ name: 'x', file: 'a.vue' }], today)
      ).toEqual(['x: opened must be YYYY-MM-DD'])
    })
  })

  it('every switch in the code is listed, fresh or kept, and nothing listed is dead', () => {
    const manifest = JSON.parse(fs.readFileSync(MANIFEST, 'utf8'))
    const problems = manifestProblems(manifest, switchesInTree())
    expect(problems).toEqual([])
  })
})
