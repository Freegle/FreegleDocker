import { defineStore } from 'pinia'
import api from '~/api'

const PANELS = {
  health: 'fetchHealth',
  funnels: 'fetchFunnels',
  outcomes: 'fetchOutcomes',
  fields: 'fetchFields',
  friction: 'fetchFriction',
  adoption: 'fetchAdoption',
  regret: 'fetchRegret',
  search: 'fetchSearch',
  samples: 'fetchSamples',
}

const idle = () => ({ data: null, loading: false, error: null })

// State for the SysAdmin UX tab. One { data, loading, error } slot per read
// endpoint so each panel handles its own loading and failure.
export const useUxStore = defineStore('ux', {
  state: () => {
    const s = {}
    for (const k of Object.keys(PANELS)) s[k] = idle()
    return s
  },
  actions: {
    async load(name, params = {}) {
      const slot = this[name]
      slot.loading = true
      slot.error = null
      try {
        slot.data = await api(useRuntimeConfig()).ux[PANELS[name]](params)
      } catch (e) {
        slot.data = null
        slot.error = e?.message || 'Unknown error'
      } finally {
        slot.loading = false
      }
    },

    // Everything the tab shows apart from samples, which are opened on demand.
    async loadAll(params = {}) {
      await Promise.all(
        Object.keys(PANELS)
          .filter((k) => k !== 'samples')
          .map((k) => this.load(k, { ...params }))
      )
    },
  },
})
