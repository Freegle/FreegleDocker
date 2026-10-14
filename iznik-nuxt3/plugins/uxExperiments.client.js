/**
 * Fetches the running UX experiments' arms at boot, so a change can start, move traffic, be
 * adopted or be reverted with no deploy. The key is public and holds names, bucket ranges and
 * expiry times only. docs/developers/reference/ux-telemetry.md section 8.
 *
 * Not awaited: a visit that starts a flow before the answer arrives uses the copy kept from the
 * last good fetch (composables/useExperiment.js), or no arm at all, which is control.
 *
 * The fetch is quiet when it fails. The frontend can deploy before the API that serves the key
 * does, in which case every page view gets a 403 or 404; that is expected, not something to report
 * to Sentry once per page view, so the request is made with logging off and the error is dropped.
 */
import { useRuntimeConfig } from '#imports'
import api from '~/api'
import { setLiveExperiments } from '~/composables/useExperiment'

export const CONFIG_KEY = 'ux_experiments'

export async function fetchLiveExperiments(config) {
  try {
    const rows = await api(config).config.fetchv2(CONFIG_KEY, false)
    const value = Array.isArray(rows) && rows.length ? rows[0].value : '{}'
    setLiveExperiments(value)
  } catch {
    // Refused, down or slow: keep the last good copy in localStorage (useExperiment.js), and say
    // nothing.
  }
}

export default defineNuxtPlugin(() => {
  if (import.meta.server) return
  fetchLiveExperiments(useRuntimeConfig())
})
