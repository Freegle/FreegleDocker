/**
 * Fetches the running UX experiments' arms at boot, so a change can start, move traffic, be
 * adopted or be reverted with no deploy. The key is public and holds names and bucket ranges
 * only. docs/developers/reference/ux-telemetry.md section 8.
 *
 * Not awaited: a visit that starts a flow before the answer arrives uses the copy kept from the
 * last visit (composables/useExperiment.js), or no arm at all, which is control.
 */
import { useRuntimeConfig } from '#imports'
import api from '~/api'
import { setLiveExperiments } from '~/composables/useExperiment'

export const CONFIG_KEY = 'ux_experiments'

export async function fetchLiveExperiments(config) {
  try {
    const rows = await api(config).config.fetchv2(CONFIG_KEY)
    const value = Array.isArray(rows) && rows.length ? rows[0].value : '{}'
    setLiveExperiments(value)
  } catch {
    // The API is down or slow: keep whatever the last visit stored in localStorage (useExperiment.js).
  }
}

export default defineNuxtPlugin(() => {
  if (import.meta.server) return
  fetchLiveExperiments(useRuntimeConfig())
})
