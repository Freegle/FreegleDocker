/**
 * Experiment assignment for UX telemetry (docs/developers/reference/ux-telemetry.md s4 and s8).
 *
 * Assignment is crc32("<id>|<name>") % 100, the same function as
 * iznik-batch/app/Support/ExperimentBucket.php, where <id> is the user id when logged in and the
 * client session id otherwise. The arms are {"name": {"control": [0, 49], "b": [50, 99]}} with
 * inclusive bounds.
 *
 * Where the arms come from, in order of precedence:
 *   1. The live config key `ux_experiments`, fetched at boot by plugins/uxExperiments.client.js
 *      from GET /config/ux_experiments. The batch rewrites that key every hour as the running
 *      experiments move traffic, so no deploy is needed to start, re-weight, adopt or revert.
 *   2. The copy of that key kept in localStorage from the last visit, so a deep link straight
 *      into a flow has an arm before the fetch returns.
 *   3. Runtime config public.UX_EXPERIMENTS, empty by default, for a deployment with no batch.
 */
import { useRuntimeConfig } from '#imports'
import { useAuthStore } from '~/stores/auth'
import { getSessionId } from '~/composables/useTrace'

export const LIVE_STORAGE_KEY = 'freegle_ux_experiments'

let table = null
let live = null

function crcTable() {
  if (table) return table
  table = new Uint32Array(256)
  for (let n = 0; n < 256; n++) {
    let c = n
    for (let k = 0; k < 8; k++) {
      c = c & 1 ? 0xedb88320 ^ (c >>> 1) : c >>> 1
    }
    table[n] = c >>> 0
  }
  return table
}

/**
 * CRC32 of the UTF-8 bytes of a string, as an unsigned integer. Identical to PHP's crc32().
 */
export function crc32(str) {
  const t = crcTable()
  const bytes = new TextEncoder().encode(str)
  let crc = 0xffffffff
  for (const b of bytes) {
    crc = t[(crc ^ b) & 0xff] ^ (crc >>> 8)
  }
  return (crc ^ 0xffffffff) >>> 0
}

export function bucket(id, name) {
  return crc32(id + '|' + name) % 100
}

function parse(raw) {
  try {
    if (typeof raw === 'string') {
      raw = JSON.parse(raw || '{}')
    }
    return raw && typeof raw === 'object' && !Array.isArray(raw) ? raw : {}
  } catch {
    return {}
  }
}

function envExperiments() {
  try {
    return parse(useRuntimeConfig()?.public?.UX_EXPERIMENTS)
  } catch {
    return {}
  }
}

function storedExperiments() {
  try {
    if (typeof localStorage === 'undefined') return null
    const raw = localStorage.getItem(LIVE_STORAGE_KEY)
    return raw === null ? null : parse(raw)
  } catch {
    return null
  }
}

/**
 * Install the live arms (the value of the ux_experiments config key, a JSON string or object)
 * and remember them for the next visit. Called by the boot plugin; tests call it directly.
 */
export function setLiveExperiments(raw) {
  live = parse(raw)
  try {
    if (typeof localStorage !== 'undefined') {
      localStorage.setItem(LIVE_STORAGE_KEY, JSON.stringify(live))
    }
  } catch {
    // Storage full or blocked: the in-memory copy still serves this visit.
  }
  return live
}

/** Forget the live arms (tests). */
export function resetLiveExperiments() {
  live = null
}

function experiments() {
  if (live) return live
  const stored = storedExperiments()
  if (stored) {
    live = stored
    return live
  }
  return envExperiments()
}

function assignmentId() {
  try {
    const id = useAuthStore()?.user?.id
    if (id) return String(id)
  } catch {
    // No store: fall back to the session.
  }
  return getSessionId()
}

function armFor(name, ranges, id) {
  if (!ranges || typeof ranges !== 'object') return null
  const b = bucket(id, name)
  for (const [arm, range] of Object.entries(ranges)) {
    if (Array.isArray(range) && b >= range[0] && b <= range[1]) {
      return arm
    }
  }
  return null
}

/**
 * The member's arm for a configured experiment, or null if it is not configured or they fall in
 * no arm. Code behind a variant reads `useExperiment('name') === 'b'`; anything else, including
 * null, is control, so a member outside every arm or with no config sees the site as it was.
 */
export function useExperiment(name) {
  return armFor(name, experiments()[name], assignmentId())
}

/**
 * Every experiment arm the member is in, as `name:arm` sorted and joined by commas. Empty when
 * there are none. Stamped on every flow event.
 */
export function experimentVariant() {
  const all = experiments()
  const names = Object.keys(all)
  if (!names.length) return ''

  const id = assignmentId()
  const arms = []
  for (const name of names) {
    const arm = armFor(name, all[name], id)
    if (arm) arms.push(`${name}:${arm}`)
  }
  return arms.sort().join(',')
}
