import { describe, it, expect, vi, beforeEach } from 'vitest'
import { effectScope } from 'vue'

import {
  startSettingsFlow,
  changedFields,
  noteSettingsSave,
} from '~/composables/useSettingsTelemetry'
import {
  FlowName,
  useFlowTelemetry,
  _resetFlowTelemetryForTests,
} from '~/composables/useFlowTelemetry'

const log = vi.hoisted(() => ({ action: vi.fn(), flush: vi.fn() }))
vi.mock('~/composables/useClientLog', () => ({
  action: log.action,
  flush: log.flush,
}))
vi.mock('~/composables/useExperiment', () => ({ experimentVariant: () => '' }))

const events = () =>
  log.action.mock.calls.map(([name, ctx]) => ({ name, ...ctx }))
const names = () => events().map((e) => e.name)

describe('changedFields', () => {
  const user = {
    displayname: 'Sam Example',
    settings: { notificationmails: true, simplemail: 'Full', modmails: 1 },
  }

  it('names the settings that differ, with a kind', () => {
    expect(
      changedFields(user, {
        settings: {
          notificationmails: false,
          simplemail: 'Basic',
          modmails: 1,
        },
      })
    ).toEqual([
      { field: 'notificationmails', kind: 'toggled' },
      { field: 'simplemail', kind: 'replaced' },
    ])
  })

  it('names a top level param that differs', () => {
    expect(changedFields(user, { displayname: 'Other' })).toEqual([
      { field: 'displayname', kind: 'replaced' },
    ])
  })

  it('counts a setting new to the user as replaced', () => {
    expect(
      changedFields(user, { settings: { ...user.settings, newone: 1 } })
    ).toEqual([{ field: 'newone', kind: 'replaced' }])
  })

  it('ignores unchanged values and a missing user', () => {
    expect(
      changedFields(user, {
        displayname: 'Sam Example',
        settings: user.settings,
      })
    ).toEqual([])
    expect(changedFields(null, { displayname: 'x' })).toEqual([
      { field: 'displayname', kind: 'replaced' },
    ])
  })

  it('never contains a value', () => {
    const out = JSON.stringify(
      changedFields(user, {
        displayname: 'Secret Name',
        settings: { simplemail: 'Basic' },
      })
    )
    expect(out).not.toContain('Secret')
    expect(out).not.toContain('Basic')
  })
})

describe('settings flow', () => {
  let scope

  beforeEach(() => {
    _resetFlowTelemetryForTests()
    log.action.mockClear()
    scope = effectScope()
    scope.run(() => startSettingsFlow())
  })

  it('starts on the settings step when the page opens', () => {
    expect(names()).toEqual(['flow_start', 'step_enter'])
    expect(events()[0]).toMatchObject({ flow: 'settings', entry: 'page' })
    expect(events()[1].step).toBe('settings')
  })

  it('stays quiet for the first save of a setting', () => {
    noteSettingsSave([{ field: 'simplemail', kind: 'replaced' }])
    expect(names()).toEqual(['flow_start', 'step_enter'])
  })

  it('reports a setting saved again in the same visit, by name and kind', () => {
    noteSettingsSave([{ field: 'notificationmails', kind: 'toggled' }])
    noteSettingsSave([{ field: 'notificationmails', kind: 'toggled' }])
    expect(events().find((e) => e.name === 'field_revisit')).toMatchObject({
      field: 'notificationmails',
      change_kind: 'toggled',
    })
  })

  it('does not report different settings each saved once', () => {
    noteSettingsSave([{ field: 'a', kind: 'replaced' }])
    noteSettingsSave([{ field: 'b', kind: 'replaced' }])
    expect(names()).not.toContain('field_revisit')
  })

  it('starts counting afresh on the next visit', () => {
    noteSettingsSave([{ field: 'a', kind: 'replaced' }])
    scope.stop()
    effectScope().run(() => startSettingsFlow())
    log.action.mockClear()
    noteSettingsSave([{ field: 'a', kind: 'replaced' }])
    expect(names()).toEqual([])
  })

  it('does nothing when no settings visit is live', () => {
    scope.stop()
    log.action.mockClear()
    noteSettingsSave([{ field: 'a', kind: 'replaced' }])
    noteSettingsSave([{ field: 'a', kind: 'replaced' }])
    expect(log.action).not.toHaveBeenCalled()
  })

  it('abandons as navigated when the page is left without a save', () => {
    log.action.mockClear()
    scope.stop()
    expect(events().map((e) => [e.name, e.reason])).toEqual([
      ['flow_abandon', 'navigated'],
    ])
  })

  it('completes when the page is left after a successful save', () => {
    useFlowTelemetry(FlowName.SETTINGS).submitResult(true, 200, null)
    log.action.mockClear()
    scope.stop()
    expect(names()).toEqual(['flow_complete'])
  })
})
