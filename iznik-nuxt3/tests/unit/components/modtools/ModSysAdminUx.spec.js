import { describe, it, expect, beforeEach, vi } from 'vitest'
import { mount } from '@vue/test-utils'
import { setActivePinia, createPinia } from 'pinia'
import ModSysAdminUx from '~/modtools/components/ModSysAdminUx.vue'
import { useUxStore } from '~/modtools/stores/ux'

const panels = [
  'Health',
  'Funnels',
  'Surveys',
  'Friction',
  'SecondThoughts',
  'Help',
  'Adoption',
  'Regret',
  'Search',
]

describe('ModSysAdminUx', () => {
  let store
  beforeEach(() => {
    setActivePinia(createPinia())
    store = useUxStore()
    store.loadAll = vi.fn().mockResolvedValue()
  })

  const make = () => {
    const stubs = {
      ModEmailDateFilter: {
        name: 'DateFilterStub',
        template: '<div class="date-filter" />',
        props: ['loading', 'fetchLabel', 'defaultPreset'],
      },
    }
    for (const p of panels) {
      stubs[`ModSysAdminUx${p}`] = { template: `<div class="panel-${p}" />` }
    }
    return mount(ModSysAdminUx, { global: { stubs } })
  }

  it('shows one date filter and every panel, Data health first', () => {
    const w = make()
    expect(w.findAll('.date-filter')).toHaveLength(1)
    const found = w.findAll('[class^="panel-"]').map((e) => e.classes()[0])
    expect(found).toEqual(panels.map((p) => `panel-${p}`))
  })

  it('shows the Surveys panel straight after Funnels', () => {
    const found = make()
      .findAll('[class^="panel-"]')
      .map((e) => e.classes()[0])
    expect(found.indexOf('panel-Surveys')).toBe(
      found.indexOf('panel-Funnels') + 1
    )
  })

  it('counts a loading experiments list as the tab loading', () => {
    store.experiments.loading = true
    const w = make()
    expect(w.findComponent({ name: 'DateFilterStub' }).props('loading')).toBe(
      true
    )
  })

  it('loads every panel with the chosen dates when the filter fires', async () => {
    const w = make()
    w.findComponent({ name: 'DateFilterStub' }).vm.$emit('fetch', {
      start: '2026-10-01',
      end: '2026-10-07',
    })
    expect(store.loadAll).toHaveBeenCalledWith({
      start: '2026-10-01',
      end: '2026-10-07',
    })
  })

  it('keeps itself out of the UX instrumentation', () => {
    expect(make().attributes('data-ux-ignore')).toBeDefined()
  })
})
