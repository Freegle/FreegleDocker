/**
 * Fixed-ratio experiment assignment for UX telemetry (docs/developers/reference/ux-telemetry.md s4).
 *
 * Assignment is crc32("<id>|<name>") % 100, the same function as
 * iznik-batch/app/Support/ExperimentBucket.php, where <id> is the user id when logged in and the
 * client session id otherwise. Experiments come from runtime config public.UX_EXPERIMENTS:
 *   {"name": {"control": [0, 49], "b": [50, 99]}}
 * with inclusive bounds. Empty by default.
 */
import { useRuntimeConfig } from '#imports'
import { useAuthStore } from '~/stores/auth'
import { getSessionId } from '~/composables/useTrace'

let table = null

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

function experiments() {
  try {
    let raw = useRuntimeConfig()?.public?.UX_EXPERIMENTS
    if (typeof raw === 'string') {
      raw = JSON.parse(raw || '{}')
    }
    return raw && typeof raw === 'object' ? raw : {}
  } catch {
    return {}
  }
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
 * no arm.
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
