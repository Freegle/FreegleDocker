/**
 * Flow telemetry for the settings page (FlowName.SETTINGS). One visit to the page is one flow
 * instance. The question it answers is which settings people change and then change again in the
 * same visit, so the rollup can show settings that are confusing. Only field names and a kind are
 * sent, never a value.
 */
import { onScopeDispose } from 'vue'
import {
  ChangeKind,
  FlowName,
  useFlowTelemetry,
} from '~/composables/useFlowTelemetry'

// Saves per field in the live visit.
let saves = {}

const same = (a, b) => JSON.stringify(a) === JSON.stringify(b)

/**
 * Which fields a save changes, as [{ field, kind }]. `user` is the current user, `params` what is
 * being saved: top level params are compared by name, and `settings` is compared key by key.
 */
export function changedFields(user, params) {
  const out = []

  const consider = (field, before, after) => {
    if (same(before, after)) return
    const bools = typeof before === 'boolean' && typeof after === 'boolean'
    out.push({ field, kind: bools ? ChangeKind.TOGGLED : ChangeKind.REPLACED })
  }

  for (const [key, value] of Object.entries(params || {})) {
    if (key === 'settings' && value && typeof value === 'object') {
      for (const [name, setting] of Object.entries(value)) {
        consider(name, user?.settings?.[name], setting)
      }
    } else {
      consider(key, user?.[key], value)
    }
  }

  return out
}

/**
 * Called from the settings page: starts the flow and ends it when the page is left, as complete if
 * something was saved and abandon if not.
 */
export function startSettingsFlow() {
  const flow = useFlowTelemetry(FlowName.SETTINGS)
  saves = {}
  flow.start('page')
  flow.enterStep('settings')

  onScopeDispose(() => {
    if (flow.submitSucceeded()) {
      flow.complete()
    } else {
      flow.abandon('navigated')
    }
  })
}

/**
 * Called when settings are saved. A field saved a second time in the same visit is a field the
 * member changed their mind about.
 */
export function noteSettingsSave(fields) {
  const flow = useFlowTelemetry(FlowName.SETTINGS)
  if (!flow.isLive()) return

  for (const { field, kind } of fields) {
    saves[field] = (saves[field] || 0) + 1
    if (saves[field] > 1) {
      flow.fieldRevisit(field, kind)
    }
  }
}
