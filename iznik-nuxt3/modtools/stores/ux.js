import { defineStore } from 'pinia'
import api from '~/api'
import { idleSlot, loadInto } from '~/modtools/composables/useUxSlot'

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

// Two sets of params are the same when they hold the same keys and values, whatever the order.
const sameParams = (a, b) =>
  JSON.stringify(Object.entries(a || {}).sort()) ===
  JSON.stringify(Object.entries(b || {}).sort())

// State for the SysAdmin UX tab. One { data, loading, error, missing } slot per read endpoint so each
// panel handles its own loading and failure, plus a record of the params each slot was last asked
// with. The record lets a section skip a slot that already holds what it wants, and lets a panel say
// what was asked when it describes what the server did with it.
export const useUxStore = defineStore('ux', {
  state: () => {
    const s = { held: {} }
    for (const k of Object.keys(PANELS)) s[k] = idleSlot()
    return s
  },
  actions: {
    // Load one slot. The newest request for a slot wins (see useUxSlot).
    async load(name, params = {}) {
      this.held[name] = { ...params }
      await loadInto(this[name], () =>
        api(useRuntimeConfig()).ux[PANELS[name]]({ ...params })
      )
    },

    // Load the named slots with the same params, leaving out samples (opened on demand) and any slot
    // that already holds these params. One failure does not stop the others.
    async loadSlots(names, params = {}) {
      await Promise.all(
        names
          .filter((name) => name !== 'samples' && name in PANELS)
          .filter((name) => !sameParams(this.held[name], params))
          .map((name) => this.load(name, params))
      )
    },

    // Forget what each slot holds, so the next loadSlots asks the server again. Called when the tab
    // opens and when the person asks for a refresh.
    forget() {
      this.held = {}
    },

    // The params a slot was last asked with.
    requested(name) {
      return this.held[name] || {}
    },
  },
})
