import { describe, it, expect, beforeEach } from 'vitest'
import { mount } from '@vue/test-utils'
import { setActivePinia, createPinia } from 'pinia'
import ModSysAdminUxReplyConfirm from '~/modtools/components/ModSysAdminUxReplyConfirm.vue'
import { useUxStore } from '~/modtools/stores/ux'
import { uxStubs } from './uxStubs'

describe('ModSysAdminUxReplyConfirm', () => {
  let store
  beforeEach(() => {
    setActivePinia(createPinia())
    store = useUxStore()
    store.replyconfirm.data = {
      reasons: [
        {
          reason: 'damaged',
          asked: 40,
          yes_button: 20,
          yes_message: 4,
          no: 10,
          no_answer: 6,
          waiting: 0,
        },
        {
          reason: 'car',
          asked: 10,
          yes_button: 9,
          yes_message: 0,
          no: 0,
          no_answer: 0,
          waiting: 1,
        },
      ],
      previous: {
        reasons: [
          { reason: 'damaged', asked: 20, yes_button: 10, yes_message: 0 },
        ],
      },
    }
  })
  const make = () =>
    mount(ModSysAdminUxReplyConfirm, { global: { stubs: uxStubs } })

  it('names each reason in words, damaged first', () => {
    store.replyconfirm.data.reasons.reverse()
    const t = make().text()
    expect(t).toContain('Looks damaged')
    expect(t).toContain('Needs a car')
    expect(t.indexOf('Looks damaged')).toBeLessThan(t.indexOf('Needs a car'))
  })

  it('shows each answer as a share of those asked, with counts', () => {
    const t = make().text()
    expect(t).toContain('50.0% (20/40)')
    expect(t).toContain('10.0% (4/40)')
    expect(t).toContain('25.0% (10/40)')
    expect(t).toContain('15.0% (6/40)')
  })

  it('compares yes with the previous period, and says when there was none', () => {
    const t = make().text()
    expect(t).toContain('50.0% (10/20)')
    expect(t).toContain('–')
  })

  it('shows the empty message', () => {
    store.replyconfirm.data = { reasons: [] }
    expect(make().text()).toContain('No replies were asked about')
  })

  it('shows an error', () => {
    store.replyconfirm.data = null
    store.replyconfirm.error = 'down'
    expect(make().text()).toContain('down')
  })
})
