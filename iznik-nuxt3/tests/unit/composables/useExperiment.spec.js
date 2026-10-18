import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest'

const auth = vi.hoisted(() => ({ user: null }))
vi.mock('~/stores/auth', () => ({ useAuthStore: () => auth }))
vi.mock('~/composables/useTrace', () => ({
  getSessionId: () => 'sess-abc',
}))

let mod

function config(experiments) {
  globalThis.__testRuntimeConfig = () => ({
    public: { UX_EXPERIMENTS: experiments },
  })
}

describe('useExperiment', () => {
  beforeEach(async () => {
    vi.resetModules()
    auth.user = null
    config('{}')
    mod = await import('~/composables/useExperiment')
  })

  afterEach(() => {
    delete globalThis.__testRuntimeConfig
  })

  describe('crc32 matches PHP crc32()', () => {
    // Generated with: php -r 'echo crc32($s);' in the batch container.
    it.each([
      ['123|exp', 3831333189],
      ['sess-abc|ab', 4117368972],
      ['', 0],
      ['0|x', 108983158],
      ['42|give-hint', 4236068640],
      ['f47ac10b-58cc-4372-a567-0e02b2c3d479|checkout', 3452454329],
      ['7|ünï', 2444113647],
      ['99999999|longer-experiment-name', 2670517058],
    ])('%j', (input, expected) => {
      expect(mod.crc32(input)).toBe(expected)
    })
  })

  describe('bucket', () => {
    it('is crc32(id|name) % 100, like ExperimentBucket::bucket', () => {
      expect(mod.bucket('123', 'exp')).toBe(89)
      expect(mod.bucket('sess-abc', 'ab')).toBe(72)
      expect(mod.bucket('42', 'give-hint')).toBe(40)
    })
  })

  describe('useExperiment', () => {
    const exps = JSON.stringify({
      exp: { control: [0, 49], b: [50, 99] },
      'give-hint': { control: [0, 39], b: [40, 99] },
    })

    it('returns null for an experiment that is not configured', () => {
      config(exps)
      expect(mod.useExperiment('nope')).toBeNull()
    })

    it('returns null when nothing is configured at all', () => {
      expect(mod.useExperiment('exp')).toBeNull()
    })

    it('assigns by user id when logged in', () => {
      config(exps)
      auth.user = { id: 123 }
      // bucket 89 falls in b.
      expect(mod.useExperiment('exp')).toBe('b')
    })

    it('assigns by session id when logged out', () => {
      config(exps)
      // 'sess-abc|exp' is not one of the vectors; compute independently.
      const expected = mod.bucket('sess-abc', 'exp') <= 49 ? 'control' : 'b'
      expect(mod.useExperiment('exp')).toBe(expected)
    })

    it('treats the arm ranges as inclusive', () => {
      config(exps)
      auth.user = { id: 42 }
      // bucket 40 is the lower bound of b in give-hint.
      expect(mod.useExperiment('give-hint')).toBe('b')
    })

    it('treats the upper bound as inclusive too', () => {
      config(exps)
      // PHP: crc32("18|exp") % 100 === 49, the top of control.
      auth.user = { id: 18 }
      expect(mod.bucket('18', 'exp')).toBe(49)
      expect(mod.useExperiment('exp')).toBe('control')
    })

    it('returns null when the bucket is outside every range', () => {
      config(JSON.stringify({ exp: { control: [0, 10] } }))
      auth.user = { id: 123 }
      expect(mod.useExperiment('exp')).toBeNull()
    })

    it('accepts the config already parsed as an object', () => {
      config({ exp: { control: [0, 49], b: [50, 99] } })
      auth.user = { id: 123 }
      expect(mod.useExperiment('exp')).toBe('b')
    })

    it('survives invalid JSON config', () => {
      config('{not json')
      expect(mod.useExperiment('exp')).toBeNull()
    })
  })

  describe('experimentVariant', () => {
    it('is empty when there are no experiments', () => {
      expect(mod.experimentVariant()).toBe('')
    })

    it('lists name:arm sorted and comma joined', () => {
      config(
        JSON.stringify({
          'give-hint': { control: [0, 39], b: [40, 99] },
          exp: { control: [0, 49], b: [50, 99] },
        })
      )
      auth.user = { id: 123 }
      // exp -> 89 -> b ; give-hint for id 123 computed independently.
      const gh = mod.bucket('123', 'give-hint') <= 39 ? 'control' : 'b'
      expect(mod.experimentVariant()).toBe(`exp:b,give-hint:${gh}`)
    })

    it('leaves out experiments the member is in no arm of', () => {
      config(JSON.stringify({ exp: { control: [0, 10] } }))
      auth.user = { id: 123 }
      expect(mod.experimentVariant()).toBe('')
    })
  })
})
